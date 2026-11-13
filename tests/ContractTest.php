<?php

declare(strict_types=1);

use LandingOps\Exception\ValidationException;
use LandingOps\Exception\PublicationInterruptedException;
use LandingOps\Instance\OfflinePublicRouter;
use LandingOps\Instance\OfflineInstanceBuilder;
use LandingOps\Instance\ReleasePointer;
use LandingOps\Instance\RevisionLedger;
use LandingOps\Tests\FixtureFactory;
use LandingOps\Tests\TestHarness as T;

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
];
