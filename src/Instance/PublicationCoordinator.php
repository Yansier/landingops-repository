<?php

declare(strict_types=1);

namespace LandingOps\Instance;

use LandingOps\Contract\Json;
use LandingOps\Exception\LandingOpsException;
use LandingOps\Filesystem\LocalFilesystem;
use stdClass;

final class PublicationCoordinator
{
    private readonly string $journalRoot;
    private readonly InstanceLock $instanceLock;

    public function __construct(
        private readonly string $instanceRoot,
        private readonly LocalFilesystem $filesystem = new LocalFilesystem(),
    ) {
        $this->journalRoot = $instanceRoot . DIRECTORY_SEPARATOR . '.landingops-publications';
        $this->instanceLock = new InstanceLock($instanceRoot, $filesystem);
        $this->instanceLock->assertHeld();
        $this->filesystem->ensureDirectory($this->journalRoot);
    }

    public function begin(int $revision, string $stagingRoot): void
    {
        $this->instanceLock->assertHeld();
        $stagingName = basename($stagingRoot);
        if (preg_match('/^revision-' . $revision . '-[a-f0-9]{8}$/D', $stagingName) !== 1) {
            throw new LandingOpsException('publication staging 名称非法。');
        }
        $this->filesystem->writeExclusive($this->journalPath($revision), Json::encode((object) [
            'contract' => 'landingops-publication-journal/1.0',
            'revision' => $revision,
            'phase' => 'allocated',
            'staging_name' => $stagingName,
            'release_id' => null,
        ], true));
    }

    public function stageReady(int $revision, string $releaseId): void
    {
        $this->instanceLock->assertHeld();
        $this->transition($revision, 'staging_ready', $releaseId);
    }

    public function releaseCommitted(int $revision): void
    {
        $this->instanceLock->assertHeld();
        $this->transition($revision, 'release_committed');
    }

    public function pointerActivated(int $revision): void
    {
        $this->instanceLock->assertHeld();
        $this->transition($revision, 'pointer_activated');
    }

    public function complete(int $revision): void
    {
        $this->instanceLock->assertHeld();
        $path = $this->journalPath($revision);
        if (is_file($path) && !unlink($path)) {
            throw new LandingOpsException('无法清理 publication journal。');
        }
    }

    public function recover(RevisionLedger $ledger, ReleasePointer $pointer): void
    {
        $this->instanceLock->assertHeld();
        $journals = glob($this->journalRoot . DIRECTORY_SEPARATOR . 'revision-*.json') ?: [];
        usort($journals, static function (string $left, string $right): int {
            return self::revisionFromPath($left) <=> self::revisionFromPath($right);
        });

        $state = $ledger->state();
        $valid = [];
        $discard = [];
        foreach ($journals as $path) {
            $journal = $this->read($path);
            $revision = $journal->revision;
            $staging = $this->instanceRoot . DIRECTORY_SEPARATOR . 'staging'
                . DIRECTORY_SEPARATOR . $journal->staging_name;

            $releaseId = is_string($journal->release_id ?? null) ? $journal->release_id : null;
            $releasePath = $releaseId === null ? null : $this->instanceRoot . DIRECTORY_SEPARATOR . 'releases'
                . DIRECTORY_SEPARATOR . $releaseId;
            $releaseExists = is_string($releasePath)
                && $this->isCompleteRelease($releasePath, $revision, (string) $releaseId);

            $status = property_exists($state->allocations, (string) $revision)
                ? $state->allocations->{(string) $revision}
                : null;
            if ($journal->phase === 'allocated'
                || ($journal->phase === 'staging_ready' && !$releaseExists)
                || $status === 'failed'
            ) {
                $discard[] = [$revision, $staging];
                continue;
            }

            if ($releaseId === null || !$releaseExists) {
                $discard[] = [$revision, $staging];
                continue;
            }

            $valid[$revision] = ['release_id' => $releaseId, 'journal' => true];
        }

        $currentId = $pointer->currentId();
        $current = is_string($currentId) ? $this->releaseCandidate($currentId, $state) : null;
        if ($current !== null) {
            $valid[$current['revision']] = ['release_id' => $current['release_id'], 'journal' => false];
        }
        if (is_int($state->active_revision) && is_string($state->active_release_id)) {
            $active = $this->releaseCandidate($state->active_release_id, $state);
            if ($active !== null && $active['revision'] === $state->active_revision) {
                $valid[$active['revision']] = ['release_id' => $active['release_id'], 'journal' => false];
            }
        }

        if ($valid !== []) {
            ksort($valid, SORT_NUMERIC);
            $winnerRevision = (int) array_key_last($valid);
            $winnerId = $valid[$winnerRevision]['release_id'];
            if ($pointer->currentId() !== $winnerId) {
                $pointer->activate($winnerId);
            }
            $ledger->markActive($winnerRevision, $winnerId);

            foreach ($valid as $revision => $candidate) {
                if ((int) $revision < $winnerRevision) {
                    $ledger->markSuperseded((int) $revision);
                }
            }
        }

        foreach ($discard as [$revision, $staging]) {
            if (is_dir($staging)) {
                $this->filesystem->removeTree($staging, dirname($staging));
            }
            $ledger->markFailed($revision);
        }
        foreach ($journals as $path) {
            $revision = self::revisionFromPath($path);
            if (is_file($path)) {
                $this->complete($revision);
            }
        }
    }

    /** @return array{revision:int,release_id:string}|null */
    private function releaseCandidate(string $releaseId, stdClass $ledgerState): ?array
    {
        if (preg_match('/^r([1-9]\d*)-[a-f0-9]{12}$/D', $releaseId, $matches) !== 1) {
            return null;
        }
        $revision = (int) $matches[1];
        if (!property_exists($ledgerState->allocations, (string) $revision)
            || $ledgerState->allocations->{(string) $revision} === 'failed'
        ) {
            return null;
        }
        $path = $this->instanceRoot . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . $releaseId;
        return $this->isCompleteRelease($path, $revision, $releaseId)
            ? ['revision' => $revision, 'release_id' => $releaseId]
            : null;
    }

    private function transition(int $revision, string $phase, ?string $releaseId = null): void
    {
        $path = $this->journalPath($revision);
        $journal = $this->read($path);
        if ($releaseId !== null) {
            if (preg_match('/^r' . $revision . '-[a-f0-9]{12}$/D', $releaseId) !== 1) {
                throw new LandingOpsException('publication release ID 非法。');
            }
            $journal->release_id = $releaseId;
        }
        $journal->phase = $phase;
        $this->filesystem->writeAtomic($path, Json::encode($journal, true));
    }

    private function read(string $path): stdClass
    {
        $journal = Json::decode((string) file_get_contents($path), basename($path));
        $revision = self::revisionFromPath($path);
        if (!($journal instanceof stdClass)
            || ($journal->contract ?? null) !== 'landingops-publication-journal/1.0'
            || ($journal->revision ?? null) !== $revision
            || !is_string($journal->phase ?? null)
            || !in_array($journal->phase, ['allocated', 'staging_ready', 'release_committed', 'pointer_activated'], true)
            || !is_string($journal->staging_name ?? null)
            || preg_match('/^revision-' . $revision . '-[a-f0-9]{8}$/D', $journal->staging_name) !== 1
        ) {
            throw new LandingOpsException('publication journal 损坏。');
        }
        if (in_array($journal->phase, ['staging_ready', 'release_committed', 'pointer_activated'], true)
            && (!is_string($journal->release_id ?? null)
                || preg_match('/^r' . $revision . '-[a-f0-9]{12}$/D', $journal->release_id) !== 1)
        ) {
            throw new LandingOpsException('publication journal 缺少有效 release ID。');
        }
        return $journal;
    }

    private function isCompleteRelease(string $path, int $revision, string $releaseId): bool
    {
        $manifestPath = $path . DIRECTORY_SEPARATOR . 'release-manifest.json';
        if (!is_dir($path) || !is_file($manifestPath)) {
            return false;
        }
        try {
            $manifest = Json::decode((string) file_get_contents($manifestPath), 'release-manifest.json');
        } catch (LandingOpsException) {
            return false;
        }
        return $manifest instanceof stdClass
            && ($manifest->contract ?? null) === 'landingops-release/1.0'
            && ($manifest->revision ?? null) === $revision
            && ($manifest->release_id ?? null) === $releaseId;
    }

    private function journalPath(int $revision): string
    {
        if ($revision < 1) {
            throw new LandingOpsException('publication revision 非法。');
        }
        return $this->journalRoot . DIRECTORY_SEPARATOR . 'revision-' . $revision . '.json';
    }

    private static function revisionFromPath(string $path): int
    {
        if (preg_match('/^revision-([1-9]\d*)\.json$/D', basename($path), $matches) !== 1) {
            throw new LandingOpsException('publication journal 文件名非法。');
        }
        return (int) $matches[1];
    }
}
