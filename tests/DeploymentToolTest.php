<?php

declare(strict_types=1);

namespace LandingOps\Tests;

use LandingOps\Deployment\DeploymentConfig;
use LandingOps\Deployment\DeploymentDoctor;
use LandingOps\Deployment\DeploymentException;
use LandingOps\Deployment\DeploymentExecutionPolicy;
use LandingOps\Deployment\DeploymentPlanner;
use LandingOps\Deployment\ReleaseBuilder;
use LandingOps\Deployment\ReleaseManifest;
use LandingOps\Deployment\SimulatedDeploymentExecutor;

/** @return array<string,mixed> */
function phase1lConfigData(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__) . '/deploy/examples/deployment.example.json'), true, 64, JSON_THROW_ON_ERROR);
}

/** @return array<string,mixed> */
function phase1lFacts(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__) . '/deploy/examples/doctor-facts.example.json'), true, 64, JSON_THROW_ON_ERROR);
}

/** @return array<string,bool> */
function phase1lSecrets(DeploymentConfig $config, bool $present = true): array
{
    return array_fill_keys(array_values($config->credentialReferences()), $present);
}

function phase1lWrite(string $path, string $content): void
{
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
        throw new \RuntimeException('Unable to create fixture directory.');
    }
    file_put_contents($path, $content);
}

function phase1lRelease(string $root, string $version): string
{
    $release = $root . DIRECTORY_SEPARATOR . 'release-' . $version;
    phase1lWrite($release . '/public/index.html', '<!doctype html>');
    phase1lWrite($release . '/public/index.php', '<?php');
    phase1lWrite($release . '/public/health.php', '<?php');
    phase1lWrite($release . '/src/App.php', '<?php');
    phase1lWrite($release . '/RELEASE_VERSION', $version . "\n");
    $files = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($release, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($release) + 1));
            $files[$relative] = ['sha256' => hash_file('sha256', $file->getPathname()), 'size' => $file->getSize()];
        }
    }
    ksort($files, SORT_STRING);
    phase1lWrite($release . '/release-manifest.json', json_encode([
        'schema_version' => 'landingops-release/1.0', 'deployment_scope' => 'landingops_platform',
        'version' => $version, 'source_commit' => str_repeat('a', 40), 'built_at' => '2026-01-01T00:00:00Z',
        'web_root' => 'public', 'database_migrations' => [], 'files' => $files,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    return $release;
}

function phase1lCurrent(string $sandbox): ?string
{
    $path = $sandbox . '/srv/landingops/current-release.json';
    if (!is_file($path)) {
        return null;
    }
    return json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR)['version'] ?? null;
}

/** @return array<string,\Closure> */
return [
    'deployment config is portable strict and contains only environment credential references' => static function (): void {
        $config = DeploymentConfig::fromArray(phase1lConfigData());
        TestHarness::assertSame('landingops-primary', $config->installationId());
        TestHarness::assertSame('/srv/landingops', $config->installRoot());
        TestHarness::assertSame(4, count($config->credentialReferences()));
        TestHarness::assertNotContains('password":', strtolower(json_encode($config->all(), JSON_THROW_ON_ERROR)));
        $other = phase1lConfigData();
        $other['target']['ssh_host'] = 'new-host.example.invalid';
        TestHarness::assertSame('new-host.example.invalid', DeploymentConfig::fromArray($other)->all()['target']['ssh_host']);
    },

    'deployment config rejects root unsafe targets traversal test databases and secret values' => static function (): void {
        foreach ([
            static function (array &$data): void { $data['target']['ssh_user'] = 'root'; },
            static function (array &$data): void { $data['target']['ssh_host'] = 'https://attacker.invalid'; },
            static function (array &$data): void { $data['paths']['staging_dir'] = '/srv/landingops/../other'; },
            static function (array &$data): void { $data['database']['name'] = 'landingops_test'; },
            static function (array &$data): void { $data['database']['password'] = 'must-not-exist'; },
            static function (array &$data): void { $data['database']['mode'] = 'existing'; },
            static function (array &$data): void { $data['paths']['backup_dir'] = $data['paths']['staging_dir']; },
        ] as $mutate) {
            $data = phase1lConfigData();
            $mutate($data);
            TestHarness::assertThrows(static fn () => DeploymentConfig::fromArray($data), DeploymentException::class);
        }
    },

    'deployment doctor passes only complete matching non root isolated evidence' => static function (): void {
        $config = DeploymentConfig::fromArray(phase1lConfigData());
        $report = (new DeploymentDoctor())->inspect($config, phase1lFacts(), phase1lSecrets($config));
        TestHarness::assertSame('ready_for_simulation', $report['classification']);
        TestHarness::assertSame(0, $report['blocker_count']);
        TestHarness::assertFalse($report['real_execution_enabled']);
        TestHarness::assertFalse($report['write_operations_performed']);
    },

    'deployment doctor blocks missing extensions build failures symlinks and credentials' => static function (): void {
        $config = DeploymentConfig::fromArray(phase1lConfigData());
        $facts = phase1lFacts();
        $facts['runtime']['php_extensions'] = ['json', 'openssl', 'pdo'];
        $facts['filesystem']['ancestors_have_symlink'] = true;
        $facts['build']['vue_build_ok'] = false;
        $facts['build']['composer_install_ok'] = false;
        $report = (new DeploymentDoctor())->inspect($config, $facts, phase1lSecrets($config, false));
        TestHarness::assertSame('blocked', $report['classification']);
        $blocked = array_column(array_filter($report['checks'], static fn (array $check): bool => $check['status'] === 'blocked'), 'key');
        foreach (['ancestor_symlinks', 'php_extensions', 'vue_build_ok', 'composer_install_ok', 'credential_references'] as $key) {
            TestHarness::assertTrue(in_array($key, $blocked, true));
        }
    },

    'deployment plan separates platform from customer sites and database rollback' => static function (): void {
        $config = DeploymentConfig::fromArray(phase1lConfigData());
        $doctor = (new DeploymentDoctor())->inspect($config, phase1lFacts(), phase1lSecrets($config));
        foreach (['init', 'update', 'rollback'] as $operation) {
            $plan = (new DeploymentPlanner())->plan($config, $operation, 'v1.2.3', $doctor);
            TestHarness::assertSame('landingops_platform', $plan['deployment_scope']);
            TestHarness::assertSame(0, $plan['customer_site_operations']);
            TestHarness::assertFalse($plan['database']['automatic_rollback']);
            TestHarness::assertFalse($plan['real_execution_enabled']);
            TestHarness::assertFalse($plan['write_operations_performed']);
        }
    },

    'real deployment execution is disabled unless an explicit simulation root is supplied' => static function (): void {
        $error = TestHarness::assertThrows(
            static fn () => (new DeploymentExecutionPolicy())->requireSimulationRoot(null),
            DeploymentException::class,
        );
        TestHarness::assertSame('real_execution_disabled', $error->errorCode);
    },

    'release builder creates a private application package and minimal public web root' => static function (): void {
        $project = TestHarness::tempDirectory('phase1l-builder-project');
        $output = TestHarness::tempDirectory('phase1l-builder-output');
        foreach ([
            'composer.json' => '{}', 'composer.lock' => '{}', 'vendor/autoload.php' => '<?php',
            'frontend/dist/index.html' => '<!doctype html>', 'frontend/dist/assets/app.js' => 'console.log(1)',
            'public/index.php' => '<?php', 'public/health.php' => '<?php', 'src/App.php' => '<?php',
            'bin/landingops-control' => '<?php', 'tests/secret-test.php' => '<?php', 'deploy/tool.php' => '<?php',
        ] as $path => $content) {
            phase1lWrite($project . '/' . $path, $content);
        }
        for ($i = 1; $i <= 6; $i++) {
            phase1lWrite($project . '/database/migrations/00' . $i . '_test.sql', 'SELECT ' . $i . ';');
        }
        $result = (new ReleaseBuilder())->build($project, $output, 'v1.0.0', str_repeat('a', 40), '2026-01-01T00:00:00Z');
        TestHarness::assertFileExists($result['archive']);
        TestHarness::assertFileExists($result['archive'] . '.sha256');
        TestHarness::assertFileExists($result['release_directory'] . '/public/index.html');
        TestHarness::assertFileExists($result['release_directory'] . '/src/App.php');
        TestHarness::assertFalse(is_file($result['release_directory'] . '/public/src/App.php'));
        TestHarness::assertFalse(is_dir($result['release_directory'] . '/tests'));
        TestHarness::assertFalse(is_dir($result['release_directory'] . '/deploy'));
        TestHarness::assertSame('landingops_platform', (new ReleaseManifest())->verify($result['release_directory'])['deployment_scope']);
    },

    'release builder fails closed when composer or vue production artifacts are missing' => static function (): void {
        $project = TestHarness::tempDirectory('phase1l-missing-build');
        $output = TestHarness::tempDirectory('phase1l-missing-output');
        phase1lWrite($project . '/composer.json', '{}');
        $error = TestHarness::assertThrows(
            static fn () => (new ReleaseBuilder())->build($project, $output, 'v1', str_repeat('a', 40), '2026-01-01T00:00:00Z'),
            DeploymentException::class,
        );
        TestHarness::assertSame('build_prerequisite_missing', $error->errorCode);
    },

    'simulated init rejects unknown target content and never touches sibling sites' => static function (): void {
        $sandbox = TestHarness::tempDirectory('phase1l-unknown-target');
        phase1lWrite($sandbox . '/srv/landingops/foreign.txt', 'unknown');
        phase1lWrite($sandbox . '/srv/existing-site/index.html', 'must-stay');
        $config = DeploymentConfig::fromArray(phase1lConfigData());
        $release = phase1lRelease($sandbox, 'v1');
        $error = TestHarness::assertThrows(
            static fn () => (new SimulatedDeploymentExecutor())->execute($config, 'init', 'v1', $release, $sandbox),
            DeploymentException::class,
        );
        TestHarness::assertSame('target_directory_unknown_content', $error->errorCode);
        TestHarness::assertSame('must-stay', file_get_contents($sandbox . '/srv/existing-site/index.html'));
    },

    'simulated install update and rollback preserve persistent data and foreign sites' => static function (): void {
        $sandbox = TestHarness::tempDirectory('phase1l-lifecycle');
        phase1lWrite($sandbox . '/srv/existing-site/index.html', 'external');
        $config = DeploymentConfig::fromArray(phase1lConfigData());
        $executor = new SimulatedDeploymentExecutor();
        $v1 = phase1lRelease($sandbox, 'v1');
        TestHarness::assertSame('simulated_success', $executor->execute($config, 'init', 'v1', $v1, $sandbox)['status']);
        phase1lWrite($sandbox . '/srv/landingops/shared/template-zips/keep.zip', 'persistent');
        $v2 = phase1lRelease($sandbox, 'v2');
        $executor->execute($config, 'update', 'v2', $v2, $sandbox);
        TestHarness::assertSame('v2', phase1lCurrent($sandbox));
        TestHarness::assertSame('persistent', file_get_contents($sandbox . '/srv/landingops/shared/template-zips/keep.zip'));
        $executor->execute($config, 'rollback', 'v1', null, $sandbox);
        TestHarness::assertSame('v1', phase1lCurrent($sandbox));
        TestHarness::assertSame('external', file_get_contents($sandbox . '/srv/existing-site/index.html'));
    },

    'manifest mismatch blocks install before release switch' => static function (): void {
        $sandbox = TestHarness::tempDirectory('phase1l-manifest-fail');
        $config = DeploymentConfig::fromArray(phase1lConfigData());
        $release = phase1lRelease($sandbox, 'v1');
        file_put_contents($release . '/src/App.php', 'tampered');
        $error = TestHarness::assertThrows(
            static fn () => (new SimulatedDeploymentExecutor())->execute($config, 'init', 'v1', $release, $sandbox),
            DeploymentException::class,
        );
        TestHarness::assertSame('release_manifest_hash_mismatch', $error->errorCode);
        TestHarness::assertSame(null, phase1lCurrent($sandbox));
    },

    'migration and switch failures keep the prior active release' => static function (): void {
        $sandbox = TestHarness::tempDirectory('phase1l-faults');
        $config = DeploymentConfig::fromArray(phase1lConfigData());
        $executor = new SimulatedDeploymentExecutor();
        $executor->execute($config, 'init', 'v1', phase1lRelease($sandbox, 'v1'), $sandbox);
        $v2 = phase1lRelease($sandbox, 'v2');
        TestHarness::assertSame('database_migration_failed', TestHarness::assertThrows(
            static fn () => $executor->execute($config, 'update', 'v2', $v2, $sandbox, ['migration_fail' => true]),
            DeploymentException::class,
        )->errorCode);
        TestHarness::assertSame('v1', phase1lCurrent($sandbox));
        TestHarness::assertSame('release_switch_failed', TestHarness::assertThrows(
            static fn () => $executor->execute($config, 'update', 'v2', $v2, $sandbox, ['switch_fail' => true]),
            DeploymentException::class,
        )->errorCode);
        TestHarness::assertSame('v1', phase1lCurrent($sandbox));
    },

    'failed health check restores old code pointer without database rollback' => static function (): void {
        $sandbox = TestHarness::tempDirectory('phase1l-health-fail');
        $config = DeploymentConfig::fromArray(phase1lConfigData());
        $executor = new SimulatedDeploymentExecutor();
        $executor->execute($config, 'init', 'v1', phase1lRelease($sandbox, 'v1'), $sandbox);
        $result = $executor->execute($config, 'update', 'v2', phase1lRelease($sandbox, 'v2'), $sandbox, ['health_fail' => true]);
        TestHarness::assertSame('rolled_back_after_health_failure', $result['status']);
        TestHarness::assertSame('v1', $result['active_version']);
        TestHarness::assertFalse($result['database_rollback_performed']);
        TestHarness::assertSame('v1', phase1lCurrent($sandbox));
    },
];
