<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

final class SshEnvironmentCollector
{
    public const VERSION = 'phase-1m-a/1.0';
    private const REQUIRED_PROBES = [
        'identity_user', 'identity_uid', 'identity_gid', 'identity_group', 'os_release', 'architecture',
        'php_version', 'php_extensions', 'php_fpm_versions', 'php_runtime_matrix', 'nginx_version', 'nginx_vhost_capability',
        'disk', 'filesystem', 'target_state', 'installation_marker', 'parent_stat', 'ancestor_symlinks', 'acl_summary',
        'site_roots', 'site_config_count', 'site_root_declaration_count', 'site_non_absolute_root_count',
        'site_unreadable_count', 'site_symlink_count',
        'database_client', 'database_server_binary', 'database_runtime_binary', 'baota_version',
    ];

    public function __construct(
        private readonly SshReadOnlyTransport $transport,
        private readonly RemoteEvidenceSigner $signer = new RemoteEvidenceSigner(),
    ) {
    }

    /** @return array<string,mixed> */
    public function collect(
        DeploymentConfig $config,
        string $signingKey,
        ?\DateTimeImmutable $observedAt = null,
    ): array {
        $observedAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $session = $this->transport->collect($config);
        if (($session['host_key_fingerprint'] ?? null) !== $config->all()['target']['ssh_host_fingerprint']) {
            throw new DeploymentException('ssh_host_key_mismatch', 'SSH 主机指纹与部署配置不一致。');
        }
        $probes = is_array($session['probes'] ?? null) ? $session['probes'] : [];
        $errors = [];
        foreach (self::REQUIRED_PROBES as $probe) {
            $result = $probes[$probe] ?? null;
            if (!is_array($result)) {
                $errors[] = $this->error($probe, 'missing');
            } elseif (($result['timed_out'] ?? false) === true) {
                $errors[] = $this->error($probe, 'timeout');
            } elseif (($result['exit_code'] ?? 1) !== 0) {
                $errors[] = $this->error($probe, 'unavailable');
            }
        }
        $output = static function (array $probes, string $key): ?string {
            $value = $probes[$key]['stdout'] ?? null;
            return is_string($value) && $value !== '' ? trim($value) : null;
        };
        $configured = $config->all();
        $user = $output($probes, 'identity_user');
        $uid = $this->integer($output($probes, 'identity_uid'));
        $gid = $this->integer($output($probes, 'identity_gid'));
        $siteRoots = $this->absoluteLines($output($probes, 'site_roots'));
        $siteConfigCount = $this->integer($output($probes, 'site_config_count'));
        $siteDeclarationCount = $this->integer($output($probes, 'site_root_declaration_count'));
        $siteNonAbsoluteCount = $this->integer($output($probes, 'site_non_absolute_root_count'));
        $siteUnreadableCount = $this->integer($output($probes, 'site_unreadable_count'));
        $siteSymlinkCount = $this->integer($output($probes, 'site_symlink_count'));
        $siteInventoryComplete = $siteConfigCount !== null && $siteConfigCount > 0
            && $siteDeclarationCount !== null && $siteDeclarationCount > 0
            && $siteNonAbsoluteCount === 0 && $siteUnreadableCount === 0 && $siteSymlinkCount === 0;
        $overlaps = array_values(array_filter($siteRoots, fn (string $path): bool => $this->overlap($path, $config->installRoot())));
        $extensions = $this->lines($output($probes, 'php_extensions'));
        $missingExtensions = array_values(array_diff($config->requiredExtensions(), array_map('strtolower', $extensions)));
        $disk = $this->parseDisk($output($probes, 'disk'));
        $identityMatches = $user === $configured['target']['ssh_user'] && $uid !== null;
        $targetState = $output($probes, 'target_state') ?? 'unknown';
        $installationMarker = $this->parseInstallationMarker($output($probes, 'installation_marker'));
        $targetStateSafe = $config->installationMode() === 'fresh'
            ? in_array($targetState, ['absent', 'empty'], true)
            : $targetState === 'nonempty' && ($installationMarker['installation_id'] ?? null) === $config->installationId()
                && ($installationMarker['deployment_scope'] ?? null) === 'landingops_platform';
        $facts = [
            'identity' => [
                'observed_user' => $user, 'uid' => $uid, 'gid' => $gid,
                'group' => $output($probes, 'identity_group'),
                'configured_user_matches' => $identityMatches,
                'is_root' => $uid === 0 || $user === 'root',
            ],
            'system' => [
                'os_release' => $this->parseOsRelease($output($probes, 'os_release')),
                'architecture' => $output($probes, 'architecture'),
            ],
            'runtime' => [
                'php_version' => $this->version($output($probes, 'php_version'), '/PHP\s+([0-9]+\.[0-9]+\.[0-9]+)/'),
                'php_extensions' => $extensions,
                'missing_required_extensions' => $missingExtensions,
                'php_fpm_summary' => $this->bounded($output($probes, 'php_fpm_versions')),
                'php_runtime_matrix' => $this->bounded($output($probes, 'php_runtime_matrix')),
                'nginx_version' => $this->version($output($probes, 'nginx_version'), '#nginx/(\d+\.\d+\.\d+)#i'),
                'nginx_vhost_capability' => $output($probes, 'nginx_vhost_capability'),
            ],
            'filesystem' => [
                'target_path' => $config->installRoot(),
                'target_state' => $targetState,
                'installation_marker' => $installationMarker,
                'parent_stat' => $this->bounded($output($probes, 'parent_stat')),
                'ancestor_symlinks' => $this->lines($output($probes, 'ancestor_symlinks')),
                'acl_summary' => $this->bounded($output($probes, 'acl_summary')),
                'disk' => $disk,
                'filesystem' => $this->bounded($output($probes, 'filesystem')),
                'site_root_count' => count($siteRoots),
                'site_config_count' => $siteConfigCount,
                'site_root_declaration_count' => $siteDeclarationCount,
                'site_non_absolute_root_count' => $siteNonAbsoluteCount,
                'site_symlink_count' => $siteSymlinkCount,
                'site_inventory_complete' => $siteInventoryComplete,
                'site_path_overlap' => $overlaps,
            ],
            'database' => [
                'configured_name' => $configured['database']['name'],
                'client_version' => $this->bounded($output($probes, 'database_client')),
                'server_binary_version' => $this->bounded($output($probes, 'database_server_binary')),
                'running_server_binary_version' => $this->bounded($output($probes, 'database_runtime_binary')),
                'production_identity_verified' => false,
                'test_database_forbidden' => !str_ends_with(strtolower($configured['database']['name']), '_test'),
            ],
            'baota' => ['version' => $output($probes, 'baota_version')],
        ];
        $blockers = [];
        if (!$identityMatches || $facts['identity']['is_root']) { $blockers[] = 'dedicated_non_root_identity_unverified'; }
        if (!$targetStateSafe) { $blockers[] = 'target_directory_state_unsafe'; }
        if ($missingExtensions !== []) { $blockers[] = 'php_extensions_missing'; }
        if (($disk['available_bytes'] ?? 0) < 1073741824) { $blockers[] = 'disk_space_insufficient'; }
        if ($overlaps !== []) { $blockers[] = 'existing_site_path_overlap'; }
        if (!$siteInventoryComplete) { $blockers[] = 'existing_site_inventory_incomplete'; }
        if (($facts['filesystem']['ancestor_symlinks'] ?? []) !== []) { $blockers[] = 'ancestor_symlink_detected'; }
        if (($facts['runtime']['nginx_version'] ?? null) === null) { $blockers[] = 'nginx_unverified'; }
        if ($errors !== []) { $blockers[] = 'readonly_probe_incomplete'; }
        $evidence = [
            'schema_version' => 'landingops-remote-evidence/1.0',
            'source' => 'trusted_ssh_readonly_collector',
            'trust_level' => 'verified_read_only_session',
            'collector_version' => self::VERSION,
            'classification' => $blockers === [] ? 'readonly_verified_with_pending_writes' : 'readonly_blocked',
            'observed_at' => $observedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $observedAt->modify('+30 minutes')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'target' => [
                'host' => $configured['target']['ssh_host'], 'port' => $configured['target']['ssh_port'],
                'configured_user' => $configured['target']['ssh_user'], 'observed_user' => $user,
                'uid' => $uid, 'gid' => $gid,
                'host_key_fingerprint' => $session['host_key_fingerprint'], 'host_key_verified' => true,
                'session_id' => is_string($session['session_id'] ?? null) ? $session['session_id'] : '',
            ],
            'facts' => $facts,
            'capabilities' => [
                'confirmed' => ['ssh_host_identity', 'readonly_session', 'system_metadata', 'path_metadata'],
                'pending' => [
                    'atomic_symlink_switch', 'database_backup_restore', 'production_database_identity',
                    'nginx_vhost_write_and_reload', 'tls_installation', 'directory_creation_and_permissions',
                ],
                'unsupported' => [],
            ],
            'blockers' => array_values(array_unique($blockers)),
            'errors' => $errors,
            'command_policy' => [
                'id' => OpenSshReadOnlyTransport::POLICY_ID,
                'probe_count' => count(self::REQUIRED_PROBES),
                'arbitrary_commands_allowed' => false,
            ],
            'real_execution_eligible' => false,
            'phase_1m_b_authorization_required' => true,
            'real_execution_enabled' => false,
            'write_operations_performed' => false,
        ];
        return $this->signer->sign($evidence, $signingKey);
    }

    /** @return array{probe:string,category:string,message:string} */
    private function error(string $probe, string $category): array
    {
        return ['probe' => $probe, 'category' => $category, 'message' => '只读探针未获得完整证据；未记录远端原始错误。'];
    }

    private function integer(?string $value): ?int
    {
        return is_string($value) && preg_match('/^\d+$/D', $value) === 1 ? (int) $value : null;
    }

    /** @return list<string> */
    private function lines(?string $value): array
    {
        if ($value === null) { return []; }
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $value) ?: []), static fn (string $line): bool => $line !== '')));
    }

    /** @return list<string> */
    private function absoluteLines(?string $value): array
    {
        return array_values(array_filter($this->lines($value), static fn (string $line): bool => str_starts_with($line, '/')
            && !str_contains($line, "\0") && preg_match('#(?:^|/)\.\.?(/|$)#', $line) !== 1));
    }

    /** @return array<string,string> */
    private function parseOsRelease(?string $value): array
    {
        $result = [];
        foreach ($this->lines($value) as $line) {
            if (preg_match('/^(ID|VERSION_ID|PRETTY_NAME)=(.*)$/D', $line, $match) === 1) {
                $result[strtolower($match[1])] = trim($match[2], " \t\n\r\0\x0B\"");
            }
        }
        return $result;
    }

    /** @return array{available_bytes:int,total_bytes:int}|array{} */
    private function parseDisk(?string $value): array
    {
        if ($value !== null && preg_match('/^(\d+)\s+(\d+)$/D', trim($value), $match) === 1) {
            return ['total_bytes' => (int) $match[1] * 1024, 'available_bytes' => (int) $match[2] * 1024];
        }
        return [];
    }

    private function version(?string $value, string $pattern): ?string
    {
        return $value !== null && preg_match($pattern, $value, $match) === 1 ? $match[1] : null;
    }

    private function bounded(?string $value): ?string
    {
        return $value === null ? null : substr($value, 0, 1000);
    }

    /** @return array<string,mixed> */
    private function parseInstallationMarker(?string $value): array
    {
        if ($value === null || $value === 'absent') { return ['status' => 'absent']; }
        try {
            $marker = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['status' => 'invalid'];
        }
        if (!is_array($marker) || array_is_list($marker)) { return ['status' => 'invalid']; }
        return [
            'status' => 'present',
            'installation_id' => is_string($marker['installation_id'] ?? null) ? $marker['installation_id'] : null,
            'deployment_scope' => is_string($marker['deployment_scope'] ?? null) ? $marker['deployment_scope'] : null,
        ];
    }

    private function overlap(string $left, string $right): bool
    {
        $left = rtrim($left, '/');
        $right = rtrim($right, '/');
        return $left === $right || str_starts_with($left . '/', $right . '/') || str_starts_with($right . '/', $left . '/');
    }
}
