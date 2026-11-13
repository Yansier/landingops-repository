<?php

declare(strict_types=1);

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "The ext-zip PHP extension is required.\n");
    exit(2);
}

$root = dirname(__DIR__);
$composerAutoload = $root . '/vendor/autoload.php';
require is_file($composerAutoload) ? $composerAutoload : $root . '/src/autoload.php';
require __DIR__ . '/TestHarness.php';
require __DIR__ . '/FixtureFactory.php';
require __DIR__ . '/MemoryControlPlaneRepository.php';

use LandingOps\Tests\TestHarness;

$tests = [];
foreach (['TemplateValidatorTest.php', 'OfflineInstanceBuilderTest.php', 'ContractTest.php', 'ControlPlaneTest.php', 'TemplateManagementTest.php', 'CloudflareDomainTest.php', 'CloudflareReadOnlyTest.php'] as $file) {
    /** @var array<string,Closure> $suite */
    $suite = require __DIR__ . '/' . $file;
    $tests = array_merge($tests, $suite);
}

$passed = 0;
$failed = 0;
$started = microtime(true);
foreach ($tests as $name => $test) {
    try {
        $test();
        $passed++;
        fwrite(STDOUT, "PASS  {$name}\n");
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, "FAIL  {$name}\n");
        fwrite(STDERR, '      ' . $exception::class . ': ' . $exception->getMessage() . "\n");
        $previous = $exception->getPrevious();
        if ($previous !== null) {
            fwrite(STDERR, '      caused by ' . $previous::class . ': ' . $previous->getMessage() . "\n");
        }
    }
}
TestHarness::cleanup();

$elapsed = microtime(true) - $started;
fwrite(STDOUT, sprintf("\n%d passed, %d failed in %.3fs\n", $passed, $failed, $elapsed));
exit($failed === 0 ? 0 : 1);
