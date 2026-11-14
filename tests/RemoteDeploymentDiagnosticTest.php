<?php

declare(strict_types=1);

namespace LandingOps\Tests;

use LandingOps\Deployment\DeploymentConfig;
use LandingOps\Deployment\DeploymentException;
use LandingOps\Deployment\ForcedCommandPolicy;
use LandingOps\Deployment\OpenSshReadOnlyTransport;
use LandingOps\Deployment\RemoteEvidenceSigner;
use LandingOps\Deployment\RemoteEvidenceValidator;
use LandingOps\Deployment\RemoteInstallationPlanner;
use LandingOps\Deployment\SshEnvironmentCollector;
use LandingOps\Deployment\SshAuthenticationBoundaryAuditor;
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
            'protocol_version' => phase1mProbe(OpenSshReadOnlyTransport::PROTOCOL_VERSION),
            'command_policy_id' => phase1mProbe(OpenSshReadOnlyTransport::POLICY_ID),
            'write_operations_performed' => phase1mProbe('false'),
            'identity_user' => phase1mProbe('landingops-audit'),
            'identity_uid' => phase1mProbe('1201'),
            'identity_gid' => phase1mProbe('1201'),
            'identity_group' => phase1mProbe('landingops-audit'),
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
    'open ssh transport requests only the versioned fixed command with empty stdin' => static function (): void {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Deployment/OpenSshReadOnlyTransport.php');
        TestHarness::assertSame('landingops-readonly collect v2', OpenSshReadOnlyTransport::REMOTE_COMMAND);
        TestHarness::assertContains('StrictHostKeyChecking=yes', $source);
        TestHarness::assertContains('self::REMOTE_COMMAND', $source);
        TestHarness::assertContains('$this->run($command, null', $source);
        foreach (['ClearAllForwardings=yes', 'ForwardAgent=no', 'RequestTTY=no', 'PermitLocalCommand=no', 'PasswordAuthentication=no', 'KbdInteractiveAuthentication=no'] as $option) {
            TestHarness::assertContains($option, $source);
        }
        TestHarness::assertNotContains("'sh', '-s'", $source);
        TestHarness::assertNotContains('probeScript', $source);
    },

    'forced command policy rejects injection scp sftp tty and protocol variants' => static function (): void {
        $policy = new ForcedCommandPolicy();
        TestHarness::assertTrue($policy->authorize('landingops-readonly collect v2'));
        foreach ([null, '', 'landingops-readonly collect', 'landingops-readonly collect v3', 'landingops-readonly collect v2 ', 'landingops-readonly collect v2; id', 'scp -t /tmp/x', 'sftp', 'bash', "landingops-readonly collect v2\nid"] as $command) {
            TestHarness::assertFalse($policy->authorize($command));
        }
        TestHarness::assertFalse($policy->authorize('landingops-readonly collect v2', true));
    },

    'server wrapper exact matches original command discards stdin and executes fixed root probe' => static function (): void {
        $wrapper = (string) file_get_contents(dirname(__DIR__) . '/deploy/ssh/landingops-readonly-wrapper');
        TestHarness::assertContains("EXPECTED_COMMAND='landingops-readonly collect v2'", $wrapper);
        TestHarness::assertContains('[ "${SSH_ORIGINAL_COMMAND-}" != "$EXPECTED_COMMAND" ]', $wrapper);
        TestHarness::assertContains('exec </dev/null', $wrapper);
        TestHarness::assertContains("PROBE='/usr/local/libexec/landingops-readonly-probe-v2'", $wrapper);
        TestHarness::assertContains("root\\|755|root\\|750", $wrapper);
        foreach (['eval ', 'sh -c', 'bash -c', '$SSH_ORIGINAL_COMMAND\n', 'exec $SSH_ORIGINAL_COMMAND'] as $forbidden) {
            TestHarness::assertNotContains($forbidden, $wrapper);
        }
    },

    'fixed probe exposes protocol handshake and no client supplied script channel' => static function (): void {
        $probe = (string) file_get_contents(dirname(__DIR__) . '/deploy/ssh/landingops-readonly-probe-v2');
        TestHarness::assertContains("emit_value protocol_version 'landingops-ssh-readonly/2'", $probe);
        TestHarness::assertContains("emit_value command_policy_id 'landingops-linux-readonly-v2'", $probe);
        TestHarness::assertContains("emit_value write_operations_performed 'false'", $probe);
        TestHarness::assertContains("SITE_SNAPSHOT='/var/lib/landingops-audit/site-roots.snapshot'", $probe);
        foreach (['eval ', 'sh -c', 'bash -c', 'sudo ', 'systemctl ', 'nginx -s', 'mysql -e', 'scp ', 'sftp '] as $forbidden) {
            TestHarness::assertNotContains($forbidden, $probe);
        }
    },

    'collector refuses missing altered or failed protocol handshake' => static function (): void {
        foreach (['protocol_version', 'command_policy_id', 'write_operations_performed'] as $probe) {
            $session = phase1mSession();
            $session['probes'][$probe] = phase1mProbe('attacker-controlled');
            TestHarness::assertSame('ssh_probe_handshake_mismatch', TestHarness::assertThrows(
                static fn () => phase1mCollect($session),
                DeploymentException::class,
            )->errorCode);
        }
    },

    'authorized key and filesystem boundary allow only locked root owned forced command identity' => static function (): void {
        $key = 'AAAAC3NzaC1lZDI1NTE5AAAAIEA4VGVzdEtleU1hdGVyaWFsMDEyMzQ1Njc4OQ==';
        $facts = [
            'user' => 'landingops-audit', 'uid' => 1201, 'gid' => 1201,
            'groups' => ['landingops-audit'], 'password_locked' => true,
            'shell' => '/usr/local/libexec/landingops-readonly-wrapper',
            'authorized_key_line' => 'restrict,command="/usr/local/libexec/landingops-readonly-wrapper" ssh-ed25519 ' . $key . ' landingops-audit@landingops',
            'home' => ['owner' => 'root', 'group' => 'root', 'mode' => '755'],
            'ssh_dir' => ['owner' => 'root', 'group' => 'root', 'mode' => '755'],
            'authorized_keys' => ['owner' => 'root', 'group' => 'root', 'mode' => '644'],
            'wrapper' => ['owner' => 'root', 'group' => 'root', 'mode' => '755'],
            'probe' => ['owner' => 'root', 'group' => 'root', 'mode' => '755'],
        ];
        $auditor = new SshAuthenticationBoundaryAuditor();
        TestHarness::assertTrue($auditor->audit($facts)['safe']);
        $facts['groups'][] = 'sudo';
        $facts['shell'] = '/bin/sh';
        $facts['probe']['mode'] = '775';
        $facts['authorized_key_line'] = 'command="/bin/sh" ssh-ed25519 ' . $key . ' landingops-audit@landingops';
        $result = $auditor->audit($facts);
        TestHarness::assertFalse($result['safe']);
        TestHarness::assertTrue(in_array('supplemental_groups_forbidden', $result['violations'], true));
        TestHarness::assertTrue(in_array('forced_login_shell_required', $result['violations'], true));
        TestHarness::assertTrue(in_array('probe_mode_invalid', $result['violations'], true));
        TestHarness::assertTrue(in_array('authorized_key_restrictions_invalid', $result['violations'], true));
    },

    'authorized key restrict option covers forwarding agent x11 pty and user rc boundaries' => static function (): void {
        $example = (string) file_get_contents(dirname(__DIR__) . '/deploy/ssh/authorized_keys.options.example');
        TestHarness::assertContains('restrict,command="/usr/local/libexec/landingops-readonly-wrapper"', $example);
        TestHarness::assertNotContains('permitopen=', $example);
        TestHarness::assertNotContains('environment=', $example);
        $audit = (string) file_get_contents(dirname(__DIR__) . '/deploy/ssh/landingops-audit-boundary-check');
        TestHarness::assertContains('password_locked=', $audit);
        TestHarness::assertContains('primary_group_only=', $audit);
        TestHarness::assertContains('authorized_key_policy=', $audit);
        TestHarness::assertContains('effective_pubkey_authentication=', $audit);
        TestHarness::assertContains('effective_authorized_keys_path=', $audit);
        TestHarness::assertContains('boundary_safe=', $audit);
        TestHarness::assertContains('exit 79', $audit);
        TestHarness::assertContains('write_operations_performed=false', $audit);
        foreach (['useradd ', 'usermod ', 'passwd -l', 'chmod ', 'chown ', 'sed -i', 'systemctl '] as $forbidden) {
            TestHarness::assertNotContains($forbidden, $audit);
        }
    },

    'windows ed25519 tool keeps key outside repository and never supplies passphrase argument' => static function (): void {
        $script = (string) file_get_contents(dirname(__DIR__) . '/scripts/New-LandingOpsAuditKey.ps1');
        TestHarness::assertContains('-t ed25519 -a 100 -o', $script);
        TestHarness::assertContains('repository', $script);
        TestHarness::assertContains('/inheritance:r', $script);
        TestHarness::assertNotContains('-N ', $script);
        TestHarness::assertNotContains('ConvertFrom-SecureString', $script);
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
