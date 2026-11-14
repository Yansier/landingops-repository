<?php

declare(strict_types=1);

namespace LandingOps\Tests;

use LandingOps\Deployment\DeploymentConfig;
use LandingOps\Deployment\DeploymentException;
use LandingOps\Deployment\OpenSshReadOnlyTransport;
use LandingOps\Deployment\RemoteEvidenceSigner;
use LandingOps\Deployment\RemoteEvidenceValidator;
use LandingOps\Deployment\RemoteInstallationPlanner;
use LandingOps\Deployment\SshEnvironmentCollector;
use LandingOps\Deployment\SshReadOnlyTransport;

final class MockSshReadOnlyTransport implements SshReadOnlyTransport
{
    /** @param array<string,mixed> $session */
    public function __construct(private array $session)
    {
    }

    public function collect(DeploymentConfig $config): array
    {
        return $this->session;
    }
}

/** @return array<string,mixed> */
function phase1mConfigData(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__) . '/deploy/examples/deployment.example.json'), true, 64, JSON_THROW_ON_ERROR);
}

/** @return array{exit_code:int,stdout:string,timed_out:bool} */
function phase1mProbe(string $stdout, int $exitCode = 0, bool $timedOut = false): array
{
    return ['exit_code' => $exitCode, 'stdout' => $stdout, 'timed_out' => $timedOut];
}

/** @return array<string,mixed> */
function phase1mSession(): array
{
    $siteRoots = [];
    for ($index = 1; $index <= 26; $index++) {
        $siteRoots[] = '/www/wwwroot/external-site-' . $index;
    }
    return [
        'host_key_fingerprint' => 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'session_id' => str_repeat('a', 32),
        'probes' => [
            'identity_user' => phase1mProbe('landingops-deploy'),
            'identity_uid' => phase1mProbe('1201'),
            'identity_gid' => phase1mProbe('1201'),
            'identity_group' => phase1mProbe('landingops-deploy'),
            'os_release' => phase1mProbe("ID=ubuntu\nVERSION_ID=\"24.04\"\nPRETTY_NAME=\"Ubuntu 24.04.3 LTS\""),
            'architecture' => phase1mProbe('x86_64'),
            'php_version' => phase1mProbe('PHP 8.3.14 (cli)'),
            'php_extensions' => phase1mProbe("json\nopenssl\npdo\npdo_mysql\nzip"),
            'php_fpm_versions' => phase1mProbe('/www/server/php/83/sbin/php-fpm|PHP 8.3.14'),
            'php_runtime_matrix' => phase1mProbe('/www/server/php/83/bin/php|8.3.14|json,openssl,pdo,pdo_mysql,zip'),
            'nginx_version' => phase1mProbe('nginx version: nginx/1.24.0'),
            'nginx_vhost_capability' => phase1mProbe('baota-vhost-directory-present'),
            'disk' => phase1mProbe('104857600 52428800'),
            'filesystem' => phase1mProbe('ext4 /dev/vda1 / rw,relatime'),
            'target_state' => phase1mProbe('absent'),
            'installation_marker' => phase1mProbe('absent'),
            'parent_stat' => phase1mProbe('directory|root|root|755|2049'),
            'ancestor_symlinks' => phase1mProbe(''),
            'acl_summary' => phase1mProbe("user::rwx\ngroup::r-x\nother::r-x"),
            'site_roots' => phase1mProbe(implode("\n", $siteRoots)),
            'site_config_count' => phase1mProbe('26'),
            'site_root_declaration_count' => phase1mProbe('26'),
            'site_non_absolute_root_count' => phase1mProbe('0'),
            'site_unreadable_count' => phase1mProbe('0'),
            'site_symlink_count' => phase1mProbe('0'),
            'database_client' => phase1mProbe('mariadb  Ver 15.1 Distrib 10.11.10-MariaDB'),
            'database_server_binary' => phase1mProbe('mysqld  Ver 10.11.10-MariaDB'),
            'database_runtime_binary' => phase1mProbe('mysqld  Ver 10.11.10-MariaDB'),
            'baota_version' => phase1mProbe('8.0.7'),
        ],
    ];
}

function phase1mCollect(array $session, ?array $configData = null, ?\DateTimeImmutable $at = null): array
{
    $config = DeploymentConfig::fromArray($configData ?? phase1mConfigData());
    return (new SshEnvironmentCollector(new MockSshReadOnlyTransport($session)))->collect(
        $config,
        str_repeat('e', 32),
        $at ?? new \DateTimeImmutable('2026-01-01T00:00:00Z'),
    );
}

/** @return array<string,\Closure> */
return [
    'open ssh transport exposes only the immutable read only probe script' => static function (): void {
        $transport = new OpenSshReadOnlyTransport();
        $method = new \ReflectionMethod($transport, 'probeScript');
        $script = $method->invoke($transport, DeploymentConfig::fromArray(phase1mConfigData()));
        TestHarness::assertContains('StrictHostKeyChecking', (string) file_get_contents(dirname(__DIR__) . '/src/Deployment/OpenSshReadOnlyTransport.php'));
        foreach (['rm -', 'mv ', 'chmod ', 'chown ', 'sudo ', 'systemctl ', 'service ', 'nginx -s', 'mysql -e'] as $forbidden) {
            TestHarness::assertNotContains($forbidden, $script);
        }
        TestHarness::assertContains('emit_probe', $script);
        TestHarness::assertContains('/www/server/panel/vhost/nginx', $script);
    },

    'trusted ssh evidence binds fingerprint target session time and zero writes' => static function (): void {
        $config = DeploymentConfig::fromArray(phase1mConfigData());
        $evidence = phase1mCollect(phase1mSession());
        $verified = (new RemoteEvidenceValidator())->validate(
            $evidence,
            $config,
            str_repeat('e', 32),
            new \DateTimeImmutable('2026-01-01T00:10:00Z'),
        );
        TestHarness::assertSame('readonly_verified_with_pending_writes', $verified['classification']);
        TestHarness::assertSame(26, $verified['facts']['filesystem']['site_root_count']);
        TestHarness::assertFalse($verified['real_execution_eligible']);
        TestHarness::assertFalse($verified['real_execution_enabled']);
        TestHarness::assertFalse($verified['write_operations_performed']);
        TestHarness::assertFalse($verified['command_policy']['arbitrary_commands_allowed']);
    },

    'ssh collector rejects an incorrect host fingerprint before trusting probes' => static function (): void {
        $session = phase1mSession();
        $session['host_key_fingerprint'] = 'SHA256:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=';
        TestHarness::assertSame('ssh_host_key_mismatch', TestHarness::assertThrows(
            static fn () => phase1mCollect($session),
            DeploymentException::class,
        )->errorCode);
    },

    'remote evidence cannot be reused for an unexpected target host' => static function (): void {
        $evidence = phase1mCollect(phase1mSession());
        $other = phase1mConfigData();
        $other['target']['ssh_host'] = 'new-host.example.invalid';
        TestHarness::assertSame('remote_evidence_target_mismatch', TestHarness::assertThrows(
            static fn () => (new RemoteEvidenceValidator())->validate(
                $evidence, DeploymentConfig::fromArray($other), str_repeat('e', 32), new \DateTimeImmutable('2026-01-01T00:10:00Z'),
            ),
            DeploymentException::class,
        )->errorCode);
    },

    'root or unexpected ssh identity is blocked while a dedicated non root identity passes' => static function (): void {
        $session = phase1mSession();
        $session['probes']['identity_user'] = phase1mProbe('root');
        $session['probes']['identity_uid'] = phase1mProbe('0');
        $evidence = phase1mCollect($session);
        TestHarness::assertSame('readonly_blocked', $evidence['classification']);
        TestHarness::assertTrue(in_array('dedicated_non_root_identity_unverified', $evidence['blockers'], true));
    },

    'target absence is observed while existing site path overlap blocks installation planning' => static function (): void {
        $session = phase1mSession();
        $session['probes']['site_roots'] = phase1mProbe("/www/wwwroot/external\n/srv/landingops/legacy");
        $evidence = phase1mCollect($session);
        TestHarness::assertSame('absent', $evidence['facts']['filesystem']['target_state']);
        TestHarness::assertTrue(in_array('existing_site_path_overlap', $evidence['blockers'], true));
    },

    'unknown nonempty target directory is blocked without claiming ownership' => static function (): void {
        $session = phase1mSession();
        $session['probes']['target_state'] = phase1mProbe('nonempty');
        $session['probes']['installation_marker'] = phase1mProbe('absent');
        $evidence = phase1mCollect($session);
        TestHarness::assertTrue(in_array('target_directory_state_unsafe', $evidence['blockers'], true));
        TestHarness::assertSame('absent', $evidence['facts']['filesystem']['installation_marker']['status']);
    },

    'unreadable variable or symlinked nginx site configuration makes inventory incomplete' => static function (): void {
        foreach (['site_unreadable_count', 'site_non_absolute_root_count', 'site_symlink_count'] as $probe) {
            $session = phase1mSession();
            $session['probes'][$probe] = phase1mProbe('1');
            $evidence = phase1mCollect($session);
            TestHarness::assertTrue(in_array('existing_site_inventory_incomplete', $evidence['blockers'], true));
            TestHarness::assertFalse($evidence['facts']['filesystem']['site_inventory_complete']);
        }
    },

    'ancestor symlink missing php extension unknown nginx and low disk fail closed' => static function (): void {
        $session = phase1mSession();
        $session['probes']['ancestor_symlinks'] = phase1mProbe('/srv');
        $session['probes']['php_extensions'] = phase1mProbe("json\nopenssl\npdo");
        $session['probes']['nginx_version'] = phase1mProbe('', 127);
        $session['probes']['disk'] = phase1mProbe('100000 100');
        $evidence = phase1mCollect($session);
        foreach (['ancestor_symlink_detected', 'php_extensions_missing', 'nginx_unverified', 'disk_space_insufficient', 'readonly_probe_incomplete'] as $blocker) {
            TestHarness::assertTrue(in_array($blocker, $evidence['blockers'], true));
        }
    },

    'database planning identity mismatch invalidates signed evidence' => static function (): void {
        $config = DeploymentConfig::fromArray(phase1mConfigData());
        $evidence = phase1mCollect(phase1mSession());
        $evidence['facts']['database']['configured_name'] = 'another_database';
        $evidence = (new RemoteEvidenceSigner())->sign($evidence, str_repeat('e', 32));
        TestHarness::assertSame('remote_evidence_database_mismatch', TestHarness::assertThrows(
            static fn () => (new RemoteEvidenceValidator())->validate(
                $evidence, $config, str_repeat('e', 32), new \DateTimeImmutable('2026-01-01T00:10:00Z'),
            ),
            DeploymentException::class,
        )->errorCode);
    },

    'atomic release backup restore database and nginx writes remain pending after readonly success' => static function (): void {
        $config = DeploymentConfig::fromArray(phase1mConfigData());
        $evidence = phase1mCollect(phase1mSession());
        $plan = (new RemoteInstallationPlanner())->plan(
            $config, $evidence, str_repeat('e', 32), new \DateTimeImmutable('2026-01-01T00:10:00Z'),
        );
        TestHarness::assertSame('readonly_ready_for_manual_preparation', $plan['classification']);
        TestHarness::assertTrue(in_array('atomic_symlink_switch', $plan['missing_or_write_required_evidence'], true));
        TestHarness::assertTrue(in_array('database_backup_restore', $plan['missing_or_write_required_evidence'], true));
        TestHarness::assertFalse($plan['all_steps_execute']);
        TestHarness::assertFalse($plan['real_execution_enabled']);
    },

    'expired evidence and locally edited evidence are rejected' => static function (): void {
        $config = DeploymentConfig::fromArray(phase1mConfigData());
        $evidence = phase1mCollect(phase1mSession());
        TestHarness::assertSame('remote_evidence_expired', TestHarness::assertThrows(
            static fn () => (new RemoteEvidenceValidator())->validate(
                $evidence, $config, str_repeat('e', 32), new \DateTimeImmutable('2026-01-01T00:31:00Z'),
            ),
            DeploymentException::class,
        )->errorCode);
        $evidence['real_execution_enabled'] = true;
        TestHarness::assertThrows(
            static fn () => (new RemoteEvidenceValidator())->validate(
                $evidence, $config, str_repeat('e', 32), new \DateTimeImmutable('2026-01-01T00:10:00Z'),
            ),
            DeploymentException::class,
        );
    },

    'simulation only manual json cannot be promoted to trusted remote evidence' => static function (): void {
        $manual = json_decode((string) file_get_contents(dirname(__DIR__) . '/deploy/examples/doctor-facts.example.json'), true, 64, JSON_THROW_ON_ERROR);
        TestHarness::assertSame('remote_evidence_contract_invalid', TestHarness::assertThrows(
            static fn () => (new RemoteEvidenceValidator())->validate(
                $manual, DeploymentConfig::fromArray(phase1mConfigData()), str_repeat('e', 32), new \DateTimeImmutable('2026-01-01T00:10:00Z'),
            ),
            DeploymentException::class,
        )->errorCode);
    },

    'partial failure and timeout are sanitized and block readonly acceptance' => static function (): void {
        $session = phase1mSession();
        $session['probes']['php_fpm_versions'] = phase1mProbe('sensitive remote text', 124, true);
        $evidence = phase1mCollect($session);
        TestHarness::assertSame('readonly_blocked', $evidence['classification']);
        TestHarness::assertSame('timeout', $evidence['errors'][0]['category']);
        TestHarness::assertNotContains('sensitive remote text', json_encode($evidence['errors'], JSON_THROW_ON_ERROR));
        TestHarness::assertFalse($evidence['write_operations_performed']);
    },
];
