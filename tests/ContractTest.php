<?php

declare(strict_types=1);

use LandingOps\Exception\ValidationException;
use LandingOps\Instance\OfflineInstanceBuilder;
use LandingOps\Instance\ReleasePointer;
use LandingOps\Instance\RevisionLedger;
use LandingOps\Tests\FixtureFactory;
use LandingOps\Tests\TestHarness as T;

return [
    'old page resources remain accessible across release switches' => static function (): void {
        $dir = T::tempDirectory('release-switch');
        $zip = $dir . '/template.zip';
        FixtureFactory::sampleZip($zip);
        $instance = $dir . '/instance';
        $builder = new OfflineInstanceBuilder(clock: static fn (): string => '2026-11-13T00:00:00Z');

        $first = $builder->build($zip, FixtureFactory::sampleConfig(), $instance);
        $oldIndex = (string) file_get_contents($first->releasePath . '/public/index.html');
        $oldAsset = $first->releasePath . '/public/' . str_replace('/', DIRECTORY_SEPARATOR, $first->publicConfigAsset);
        T::assertFileExists($oldAsset);

        $secondConfig = FixtureFactory::sampleConfig();
        $secondConfig->custom->headline = '第二个 release';
        $second = $builder->build($zip, $secondConfig, $instance);

        T::assertSame(2, $second->revision);
        T::assertSame($second->releaseId, (new ReleasePointer($instance))->currentId());
        T::assertFileExists($oldAsset);
        T::assertContains($first->publicConfigAsset, $oldIndex);
        T::assertContains($first->publicConfigAsset, (string) file_get_contents($first->releasePath . '/public/index.html'));
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
];
