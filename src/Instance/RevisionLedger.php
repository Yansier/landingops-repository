<?php

declare(strict_types=1);

namespace LandingOps\Instance;

use LandingOps\Contract\Json;
use LandingOps\Exception\LandingOpsException;
use LandingOps\Filesystem\LocalFilesystem;
use stdClass;

final class RevisionLedger
{
    private readonly string $statePath;
    private readonly string $lockPath;

    public function __construct(
        private readonly string $instanceRoot,
        private readonly LocalFilesystem $filesystem = new LocalFilesystem(),
    ) {
        $this->statePath = $instanceRoot . DIRECTORY_SEPARATOR . 'revision-ledger.json';
        $this->lockPath = $instanceRoot . DIRECTORY_SEPARATOR . 'revision-ledger.lock';
        $this->filesystem->ensureDirectory($instanceRoot);
    }

    public function allocate(): int
    {
        return $this->locked(function (stdClass $state): array {
            $revision = (int) $state->next_revision;
            $state->next_revision = $revision + 1;
            $state->allocations->{(string) $revision} = 'allocated';
            return [$state, $revision];
        });
    }

    public function markActive(int $revision, string $releaseId): void
    {
        $this->locked(function (stdClass $state) use ($revision, $releaseId): array {
            $this->assertAllocated($state, $revision);
            $state->allocations->{(string) $revision} = 'active';
            $state->active_revision = $revision;
            $state->active_release_id = $releaseId;
            return [$state, null];
        });
    }

    public function markFailed(int $revision): void
    {
        $this->locked(function (stdClass $state) use ($revision): array {
            $this->assertAllocated($state, $revision);
            if ($state->allocations->{(string) $revision} !== 'active') {
                $state->allocations->{(string) $revision} = 'failed';
            }
            return [$state, null];
        });
    }

    public function state(): stdClass
    {
        return $this->readState();
    }

    /** @template T @param callable(stdClass):array{0:stdClass,1:T} $callback @return T */
    private function locked(callable $callback): mixed
    {
        $handle = fopen($this->lockPath, 'c+b');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            throw new LandingOpsException('无法取得 revision ledger 锁。');
        }
        try {
            [$state, $return] = $callback($this->readState());
            $this->filesystem->writeAtomic($this->statePath, Json::encode($state, true));
            return $return;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function readState(): stdClass
    {
        if (!is_file($this->statePath)) {
            return (object) [
                'contract' => 'landingops-revision-ledger/1.0',
                'next_revision' => 1,
                'active_revision' => null,
                'active_release_id' => null,
                'allocations' => new stdClass(),
            ];
        }

        $state = Json::decode((string) file_get_contents($this->statePath), 'revision-ledger.json');
        if (!($state instanceof stdClass) || ($state->contract ?? null) !== 'landingops-revision-ledger/1.0') {
            throw new LandingOpsException('revision ledger 损坏。');
        }
        return $state;
    }

    private function assertAllocated(stdClass $state, int $revision): void
    {
        if (!property_exists($state->allocations, (string) $revision)) {
            throw new LandingOpsException('revision 未分配。');
        }
    }
}
