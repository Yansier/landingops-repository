<?php

declare(strict_types=1);

use LandingOps\Exception\ValidationException;
use LandingOps\Exception\PublicationInterruptedException;
use LandingOps\Instance\OfflinePublicRouter;
use LandingOps\Instance\OfflineInstanceBuilder;
use LandingOps\Instance\InstanceLock;
use LandingOps\Instance\ReleasePointer;
use LandingOps\Instance\RevisionLedger;
use LandingOps\Tests\FixtureFactory;
use LandingOps\Tests\TestHarness as T;

$startLockWorker = static function (
    string $mode,
    string $instance,
    string $ready,
    int $milliseconds,
): array {
    $command = [
        PHP_BINARY,
        __DIR__ . '/helpers/instance-lock-worker.php',
        $mode,
        $instance,
        $ready,
        (string) $milliseconds,
    ];
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start instance lock worker.');
    }
    fclose($pipes[0]);
    return [$process, $pipes];
};

$waitForFile = static function (string $path, int $timeoutMilliseconds = 3000): void {
    $deadline = microtime(true) + ($timeoutMilliseconds / 1000);
    while (!is_file($path) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    T::assertFileExists($path);
};

$closeWorker = static function ($process, array $pipes, bool $terminate = false): int {
    if ($terminate && (proc_get_status($process)['running'] ?? false)) {
        proc_terminate($process);
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    return proc_close($process);
};

$recoveryScenario = static function (string $faultPoint, bool $pointerMovesBeforeRecovery): void {
    $dir = T::tempDirectory('recovery-' . $faultPoint);
    $zip = $dir . '/template.zip';
    FixtureFactory::sampleZip($zip);
    $instance = $dir . '/instance';
    $baseline = (new OfflineInstanceBuilder(clock: static fn (): string => '2026-11-13T00:00:00Z'))
        ->build($zip, FixtureFactory::sampleConfig(), $instance);

    $nextConfig = FixtureFactory::sampleConfig();
    $nextConfig->custom->headline = '待恢复的 release';
    $interrupted = new OfflineInstanceBuilder(
        clock: static fn (): string => '2026-11-13T00:01:00Z',
        faultInjector: static function (string $point, int $revision, string $releaseId) use ($faultPoint): void {
            if ($point === $faultPoint) {
                throw new PublicationInterruptedException(
                    sprintf('模拟进程中断：%s revision=%d release=%s', $point, $revision, $releaseId),
                );
            }
        },
    );
    T::assertThrows(
        static fn () => $interrupted->build($zip, $nextConfig, $instance),
        PublicationInterruptedException::class,
    );

    $pointerBefore = (new ReleasePointer($instance))->currentId();
    T::assertSame($pointerMovesBeforeRecovery ? 2 : 1, (int) explode('-', ltrim((string) $pointerBefore, 'r'), 2)[0]);
    T::assertSame(1, count(glob($instance . '/.landingops-publications/revision-*.json') ?: []));
    if ($faultPoint === 'after_staging_ready') {
        T::assertSame(1, count(glob($instance . '/staging/revision-*') ?: []));
        T::assertSame(1, count(glob($instance . '/releases/r*') ?: []));
    } else {
        T::assertSame(0, count(glob($instance . '/staging/revision-*') ?: []));
        T::assertSame(2, count(glob($instance . '/releases/r*') ?: []));
    }

    (new OfflineInstanceBuilder())->recover($instance);
    $pointerAfter = (new ReleasePointer($instance))->currentId();
    $stateAfter = (new RevisionLedger($instance))->state();
    T::assertSame($stateAfter->active_release_id, $pointerAfter);
    T::assertSame(
        $stateAfter->active_revision,
        (int) explode('-', ltrim((string) $pointerAfter, 'r'), 2)[0],
    );
    T::assertSame(3, $stateAfter->next_revision);
    T::assertSame(0, count(glob($instance . '/.landingops-publications/revision-*.json') ?: []));

    if ($faultPoint === 'after_staging_ready') {
        T::assertSame($baseline->releaseId, $pointerAfter);
        T::assertSame('failed', $stateAfter->allocations->{'2'});
    } else {
        T::assertSame(2, $stateAfter->active_revision);
        T::assertSame('active', $stateAfter->allocations->{'2'});
    }

    $thirdConfig = FixtureFactory::sampleConfig();
    $thirdConfig->custom->headline = '恢复后的新 release';
    $third = (new OfflineInstanceBuilder())->build($zip, $thirdConfig, $instance);
    T::assertSame(3, $third->revision);
    T::assertSame(4, (new RevisionLedger($instance))->state()->next_revision);
};

return [
    'old page resources remain accessible across release switches' => static function (): void {
        $dir = T::tempDirectory('release-switch');
        $zip = $dir . '/template.zip';
        FixtureFactory::sampleZip($zip);
        $instance = $dir . '/instance';
        $builder = new OfflineInstanceBuilder(clock: static fn (): string => '2026-11-13T00:00:00Z');

        $first = $builder->build($zip, FixtureFactory::sampleConfig(), $instance);
        $router = new OfflinePublicRouter($instance);
        $oldIndex = $router->read('/');
        T::assertContains($first->publicBaseUrl, $oldIndex);
        preg_match_all('~(?:src|href)=["\']([^"\']+)["\']~i', $oldIndex, $matches);
        $oldResourceUrls = array_values(array_filter(
            $matches[1],
            static fn (string $url): bool => str_starts_with($url, $first->publicBaseUrl),
        ));
        T::assertTrue(count($oldResourceUrls) >= 3, 'Expected versioned stylesheet, config, and app URLs.');
        $beforeSwitch = [];
        foreach ($oldResourceUrls as $url) {
            $beforeSwitch[$url] = $router->read($url);
        }

        $secondConfig = FixtureFactory::sampleConfig();
        $secondConfig->custom->headline = '第二个 release';
        $second = $builder->build($zip, $secondConfig, $instance);

        T::assertSame(2, $second->revision);
        T::assertSame($second->releaseId, (new ReleasePointer($instance))->currentId());
        T::assertContains($second->publicBaseUrl, $router->read('/'));
        T::assertSame($oldIndex, $router->read($first->publicBaseUrl . 'index.html'));
        foreach ($oldResourceUrls as $url) {
            T::assertSame($beforeSwitch[$url], $router->read($url), 'Old resource URL changed after switch: ' . $url);
        }
        $oldConfig = $router->read($first->publicBaseUrl . $first->publicConfigAsset);
        T::assertContains('完全离线的 LandingOps 示例', $oldConfig);
        T::assertNotContains('第二个 release', $oldConfig);
    },

    'failed publication revisions are never allocated twice' => static function (): void {
        $dir = T::tempDirectory('revision-failure');
        $zip = $dir . '/template.zip';
        FixtureFactory::sampleZip($zip);
        $instance = $dir . '/instance';
        $builder = new OfflineInstanceBuilder(clock: static fn (): string => '2026-11-13T00:00:00Z');

        $first = $builder->build($zip, FixtureFactory::sampleConfig(), $instance);
        T::assertSame(1, $first->revision);

        $invalid = FixtureFactory::sampleConfig();
        unset($invalid->private_note);
        T::assertThrows(
            static fn () => $builder->build($zip, $invalid, $instance),
            ValidationException::class,
        );

        $third = $builder->build($zip, FixtureFactory::sampleConfig(), $instance);
        T::assertSame(3, $third->revision);
        $state = (new RevisionLedger($instance))->state();
        T::assertSame('failed', $state->allocations->{'2'});
        T::assertSame('active', $state->allocations->{'3'});
        T::assertSame(4, $state->next_revision);
    },

    'recovery rolls back staging before release switch' => static function () use ($recoveryScenario): void {
        $recoveryScenario('after_staging_ready', false);
    },

    'recovery rolls forward committed release before pointer switch' => static function () use ($recoveryScenario): void {
        $recoveryScenario('after_release_committed', false);
    },

    'recovery reconciles pointer switch before ledger completion' => static function () use ($recoveryScenario): void {
        $recoveryScenario('after_pointer_activated', true);
    },

    'instance lock is reentrant while different instances remain independent' => static function () use (
        $startLockWorker,
        $waitForFile,
        $closeWorker,
    ): void {
        $dir = T::tempDirectory('instance-lock-independent');
        $instanceA = $dir . '/instance-a';
        $instanceB = $dir . '/instance-b';
        $nested = false;
        (new InstanceLock($instanceA))->synchronized(static function () use ($instanceA, &$nested): void {
            (new InstanceLock($instanceA))->synchronized(static function () use (&$nested): void {
                $nested = true;
            });
        });
        T::assertTrue($nested, 'Reentrant lock callback did not complete.');

        $ready = $dir . '/instance-a.ready';
        [$process, $pipes] = $startLockWorker('hold', $instanceA, $ready, 3000);
        try {
            $waitForFile($ready);
            $independent = false;
            (new InstanceLock($instanceB))->synchronized(static function () use (&$independent): void {
                $independent = true;
            });
            T::assertTrue($independent);
            T::assertTrue((proc_get_status($process)['running'] ?? false), 'Instance B waited for instance A lock.');
        } finally {
            $closeWorker($process, $pipes, true);
        }
    },

    'concurrent holder serializes the complete build lifecycle' => static function () use (
        $startLockWorker,
        $waitForFile,
        $closeWorker,
    ): void {
        $dir = T::tempDirectory('instance-lock-build');
        $zip = $dir . '/template.zip';
        FixtureFactory::sampleZip($zip);
        $instance = $dir . '/instance';
        $ready = $dir . '/holder.ready';
        [$process, $pipes] = $startLockWorker('hold', $instance, $ready, 700);
        try {
            $waitForFile($ready);
            $started = microtime(true);
            $build = (new OfflineInstanceBuilder())->build($zip, FixtureFactory::sampleConfig(), $instance);
            $elapsed = microtime(true) - $started;
            T::assertSame(1, $build->revision);
            T::assertTrue($elapsed >= 0.4, sprintf('Build did not wait for instance lock: %.3fs', $elapsed));
        } finally {
            $closeWorker($process, $pipes);
        }
    },

    'recovery serializes with a holder and proceeds after process crash' => static function () use (
        $startLockWorker,
        $waitForFile,
        $closeWorker,
    ): void {
        $dir = T::tempDirectory('instance-lock-recovery');
        $zip = $dir . '/template.zip';
        FixtureFactory::sampleZip($zip);
        $instance = $dir . '/instance';
        $baseline = (new OfflineInstanceBuilder())->build($zip, FixtureFactory::sampleConfig(), $instance);

        $interruptAtStaging = static function (string $point, int $revision, string $releaseId): void {
            if ($point === 'after_staging_ready') {
                throw new PublicationInterruptedException('staging interruption');
            }
        };
        T::assertThrows(
            static fn () => (new OfflineInstanceBuilder(faultInjector: $interruptAtStaging))
                ->build($zip, FixtureFactory::sampleConfig(), $instance),
            PublicationInterruptedException::class,
        );

        $ready = $dir . '/recovery-holder.ready';
        [$process, $pipes] = $startLockWorker('hold', $instance, $ready, 700);
        try {
            $waitForFile($ready);
            $started = microtime(true);
            (new OfflineInstanceBuilder())->recover($instance);
            $elapsed = microtime(true) - $started;
            T::assertTrue($elapsed >= 0.4, sprintf('Recovery did not wait for instance lock: %.3fs', $elapsed));
        } finally {
            $closeWorker($process, $pipes);
        }

        T::assertThrows(
            static fn () => (new OfflineInstanceBuilder(faultInjector: $interruptAtStaging))
                ->build($zip, FixtureFactory::sampleConfig(), $instance),
            PublicationInterruptedException::class,
        );
        $crashReady = $dir . '/crash-holder.ready';
        [$crashProcess, $crashPipes] = $startLockWorker('crash', $instance, $crashReady, 0);
        $waitForFile($crashReady);
        $closeWorker($crashProcess, $crashPipes);

        (new OfflineInstanceBuilder())->recover($instance);
        $state = (new RevisionLedger($instance))->state();
        T::assertSame($baseline->releaseId, (new ReleasePointer($instance))->currentId());
        T::assertSame('failed', $state->allocations->{'2'});
        T::assertSame('failed', $state->allocations->{'3'});
        T::assertSame(4, $state->next_revision);
    },

    'recovery chooses the newest valid release across legacy journals' => static function (): void {
        $dir = T::tempDirectory('legacy-journal-order');
        $zip = $dir . '/template.zip';
        FixtureFactory::sampleZip($zip);
        $instance = $dir . '/instance';
        (new OfflineInstanceBuilder())->build($zip, FixtureFactory::sampleConfig(), $instance);

        $interruptAtCommit = static function (string $point, int $revision, string $releaseId): void {
            if ($point === 'after_release_committed') {
                throw new PublicationInterruptedException('release committed interruption');
            }
        };
        $secondConfig = FixtureFactory::sampleConfig();
        $secondConfig->custom->headline = 'legacy release two';
        T::assertThrows(
            static fn () => (new OfflineInstanceBuilder(faultInjector: $interruptAtCommit))
                ->build($zip, $secondConfig, $instance),
            PublicationInterruptedException::class,
        );
        $journalRoot = $instance . '/.landingops-publications';
        $journalTwo = (string) file_get_contents($journalRoot . '/revision-2.json');
        (new OfflineInstanceBuilder())->recover($instance);

        $thirdConfig = FixtureFactory::sampleConfig();
        $thirdConfig->custom->headline = 'newest release three';
        T::assertThrows(
            static fn () => (new OfflineInstanceBuilder(faultInjector: $interruptAtCommit))
                ->build($zip, $thirdConfig, $instance),
            PublicationInterruptedException::class,
        );
        $journalThree = (string) file_get_contents($journalRoot . '/revision-3.json');
        (new OfflineInstanceBuilder())->recover($instance);
        $newest = (new ReleasePointer($instance))->currentId();
        T::assertTrue(is_string($newest) && str_starts_with($newest, 'r3-'));

        file_put_contents($journalRoot . '/revision-2.json', $journalTwo, LOCK_EX);
        file_put_contents($journalRoot . '/revision-3.json', $journalThree, LOCK_EX);
        (new OfflineInstanceBuilder())->recover($instance);
        T::assertSame($newest, (new ReleasePointer($instance))->currentId());

        file_put_contents($journalRoot . '/revision-2.json', $journalTwo, LOCK_EX);
        (new OfflineInstanceBuilder())->recover($instance);
        $state = (new RevisionLedger($instance))->state();
        T::assertSame($newest, (new ReleasePointer($instance))->currentId());
        T::assertSame(3, $state->active_revision);
        T::assertSame('superseded', $state->allocations->{'2'});
        T::assertSame('active', $state->allocations->{'3'});
    },
];
