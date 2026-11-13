<?php

declare(strict_types=1);

use LandingOps\Contract\Json;
use LandingOps\Instance\OfflineInstanceBuilder;
use LandingOps\Tests\FixtureFactory;
use LandingOps\Tests\TestHarness as T;

return [
    'offline builder creates private, public, HTML, and manifest artifacts' => static function (): void {
        $dir = T::tempDirectory('offline-build');
        $zip = $dir . '/template.zip';
        FixtureFactory::sampleZip($zip);
        $instance = $dir . '/instance';
        $builder = new OfflineInstanceBuilder(clock: static fn (): string => '2026-11-13T00:00:00Z');
        $build = $builder->build($zip, FixtureFactory::sampleConfig(), $instance);

        $publicJs = $build->releasePath . '/public/' . str_replace('/', DIRECTORY_SEPARATOR, $build->publicConfigAsset);
        $privateConfig = $build->releasePath . '/private/.landingops/config.json';
        $index = $build->releasePath . '/public/index.html';
        $manifestPath = $build->releasePath . '/release-manifest.json';
        T::assertFileExists($publicJs);
        T::assertFileExists($privateConfig);
        T::assertFileExists($index);
        T::assertFileExists($manifestPath);

        $js = (string) file_get_contents($publicJs);
        T::assertContains('demo-pixel-001', $js);
        T::assertNotContains('INTERNAL-ONLY-DEMO-MARKER', $js);
        T::assertContains('INTERNAL-ONLY-DEMO-MARKER', (string) file_get_contents($privateConfig));
        T::assertContains($build->publicConfigAsset, (string) file_get_contents($index));
        T::assertNotContains('__LANDINGOPS_PUBLIC_CONFIG_ASSET__', (string) file_get_contents($index));

        $manifest = Json::decode((string) file_get_contents($manifestPath), 'release manifest');
        T::assertSame($build->releaseId, $manifest->release_id);
        foreach (get_object_vars($manifest->files) as $relative => $metadata) {
            $file = $build->releasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            T::assertSame($metadata->sha256, hash_file('sha256', $file), 'Manifest hash mismatch: ' . $relative);
            T::assertSame($metadata->size, filesize($file), 'Manifest size mismatch: ' . $relative);
        }
    },

    'offline builder refuses unknown non-empty instance directories' => static function (): void {
        $dir = T::tempDirectory('unknown-instance-root');
        $zip = $dir . '/template.zip';
        FixtureFactory::sampleZip($zip);
        $instance = $dir . '/instance';
        mkdir($instance, 0700, true);
        file_put_contents($instance . '/user-file.txt', 'preserve me');

        T::assertThrows(
            static fn () => (new OfflineInstanceBuilder())->build($zip, FixtureFactory::sampleConfig(), $instance),
            \LandingOps\Exception\LandingOpsException::class,
        );
        T::assertSame('preserve me', file_get_contents($instance . '/user-file.txt'));
        T::assertFalse(is_file($instance . '/.landingops-instance.json'));
    },
];
