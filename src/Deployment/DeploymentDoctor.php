<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

final class DeploymentDoctor
{
    private const REQUIRED_MIGRATIONS = [
        '001_v1_control_plane',
        '002_fix_active_key_null_constraints',
        '003_split_cloudflare_admin_verification_state',
        '004_bind_cloudflare_zone_verification_context',
        '005_baota_readonly_precheck',
        '006_baota_inventory_evidence_snapshots',
    ];

    /**
     * @param array<string,mixed> $facts Sanitized, read-only observations from a future SSH adapter or fixture.
     * @param array<string,bool>|null $secretPresence keyed by configured environment variable name.
     * @return array<string,mixed>
     */
    public function inspect(DeploymentConfig $config, array $facts, ?array $secretPresence = null): array
    {
        $checks = [];
        $data = $config->all();
        $secretPresence ??= $this->environmentPresence($config);
        $identity = $this->object($facts, 'identity');
        $this->check($checks, 'ssh_target_identity',
            ($identity['host'] ?? null) === $data['target']['ssh_host']
                && (int) ($identity['port'] ?? 0) === $data['target']['ssh_port']
                && ($identity['user'] ?? null) === $data['target']['ssh_user'],
            'SSH 主机、端口和专用账号必须与配置完全一致。');
        $this->check($checks, 'ssh_host_key',
            ($identity['host_key_verified'] ?? false) === true
                && ($identity['host_fingerprint'] ?? null) === $data['target']['ssh_host_fingerprint'],
            'SSH 主机指纹必须先由操作者核对。');
        $this->check($checks, 'non_root_deploy_user',
            ($identity['user'] ?? null) !== 'root' && (int) ($identity['uid'] ?? 0) !== 0,
            '自动部署用户不得是 root。');

        $baota = $this->object($facts, 'baota');
        $this->check($checks, 'baota_identity',
            ($baota['https_origin'] ?? null) === $data['target']['baota_https_origin']
                && ($baota['version'] ?? null) === $data['target']['expected_baota_version'],
            '宝塔 origin 和版本必须与明确配置一致。');
        $this->check($checks, 'baota_read_only_only', ($baota['write_api_used'] ?? null) === false,
            'Phase 1L 不允许调用宝塔写入 API。');

        $filesystem = $this->object($facts, 'filesystem');
        $this->check($checks, 'installation_parent', ($filesystem['parent_exists'] ?? false) === true
            && ($filesystem['parent_authorized'] ?? false) === true, '部署父目录必须存在且明确授权。');
        $this->check($checks, 'ancestor_symlinks', ($filesystem['ancestors_have_symlink'] ?? true) === false,
            '目标路径及其祖先不得包含符号链接。');
        $targetState = $filesystem['target_state'] ?? 'unknown';
        $targetSafe = $config->installationMode() === 'fresh'
            ? in_array($targetState, ['absent', 'empty'], true)
            : $targetState === 'managed';
        $this->check($checks, 'target_directory_state', $targetSafe,
            '新安装只允许 absent/empty；已有安装必须有匹配所有权标记。');
        $this->check($checks, 'installation_marker', $targetState !== 'managed'
            || ($filesystem['installation_id'] ?? null) === $config->installationId(),
            '已有安装标记必须匹配当前 installation.id。');
        $this->check($checks, 'atomic_same_filesystem', ($filesystem['atomic_rename'] ?? false) === true
            && ($filesystem['same_filesystem'] ?? false) === true, '必须支持同文件系统原子切换。');
        $this->check($checks, 'isolated_web_root', ($filesystem['public_root_isolated'] ?? false) === true,
            'Nginx Web Root 必须只指向 release/public。');
        $this->check($checks, 'no_foreign_site_overlap', ($filesystem['foreign_site_overlap'] ?? true) === false,
            'LandingOps 路径不得与任何既有站点重叠。');

        $runtime = $this->object($facts, 'runtime');
        $phpVersion = is_string($runtime['php_version'] ?? null) ? $runtime['php_version'] : '0.0.0';
        $this->check($checks, 'php_version', version_compare($phpVersion, '8.1.0', '>=')
            && version_compare($phpVersion, '9.0.0', '<'), 'PHP CLI/FPM 必须为 8.1–8.x。');
        $extensions = is_array($runtime['php_extensions'] ?? null) ? array_map('strtolower', $runtime['php_extensions']) : [];
        $missingExtensions = array_values(array_diff($config->requiredExtensions(), $extensions));
        $this->check($checks, 'php_extensions', $missingExtensions === [], 'PHP 必需扩展必须完整。', ['missing' => $missingExtensions]);
        $this->check($checks, 'nginx_runtime', is_string($runtime['nginx_version'] ?? null)
            && ($runtime['nginx_config_test_ok'] ?? false) === true, 'Nginx 必须可识别且配置测试通过。');
        $this->check($checks, 'php_fpm_scope', ($runtime['shared_php_fpm_modified'] ?? true) === false,
            '不得修改共享 PHP-FPM 配置。');

        $database = $this->object($facts, 'database');
        $this->check($checks, 'database_identity', ($database['name'] ?? null) === $data['database']['name']
            && ($database['user'] ?? null) === $data['database']['user'], '数据库名称和账号必须与配置一致。');
        $this->check($checks, 'database_grant_scope', ($database['grants_limited_to_database'] ?? false) === true,
            '数据库账号权限必须限制到目标库。');
        $databaseState = $database['state'] ?? 'unknown';
        $migrations = is_array($database['migrations'] ?? null) ? array_values($database['migrations']) : [];
        $databaseSafe = $data['database']['mode'] === 'fresh'
            ? $databaseState === 'empty'
            : $databaseState === 'landingops' && array_diff(self::REQUIRED_MIGRATIONS, $migrations) === [];
        $this->check($checks, 'database_state', $databaseSafe,
            '目标库必须为空或是包含 001–006 的既有 LandingOps 数据库。');
        $this->check($checks, 'database_backup_restore', ($database['backup_verified'] ?? false) === true
            && ($database['restore_test_verified'] ?? false) === true, '执行迁移前必须验证备份及恢复。');

        $tls = $this->object($facts, 'tls');
        $this->check($checks, 'tls_certificate', ($tls['hostname'] ?? null) === $data['application']['domain']
            && ($tls['certificate_ready'] ?? false) === true, '管理后台域名必须已有可用 TLS 证书。');

        $build = $this->object($facts, 'build');
        foreach ([
            'composer_lock_present' => 'Composer lock 缺失。',
            'composer_install_ok' => 'Composer 生产依赖不完整。',
            'php_tests_ok' => 'PHP 测试未通过。',
            'node_lock_present' => 'Node lock 缺失。',
            'vue_typecheck_ok' => 'Vue TypeScript 检查失败。',
            'vue_build_ok' => 'Vue 构建失败。',
            'release_manifest_verified' => 'release manifest 未通过校验。',
        ] as $key => $message) {
            $this->check($checks, $key, ($build[$key] ?? false) === true, $message);
        }
        $missingSecrets = [];
        foreach ($config->credentialReferences() as $reference) {
            if (($secretPresence[$reference] ?? false) !== true) {
                $missingSecrets[] = $reference;
            }
        }
        $this->check($checks, 'credential_references', $missingSecrets === [],
            '所需凭据必须来自临时环境或本地安全输入。', ['missing_environment_variables' => $missingSecrets]);

        $blocked = array_values(array_filter($checks, static fn (array $check): bool => $check['status'] === 'blocked'));
        return [
            'classification' => $blocked === [] ? 'ready_for_simulation' : 'blocked',
            'deployment_scope' => 'landingops_platform',
            'checks' => $checks,
            'blocker_count' => count($blocked),
            'real_execution_enabled' => false,
            'write_operations_performed' => false,
        ];
    }

    /** @return array<string,bool> */
    private function environmentPresence(DeploymentConfig $config): array
    {
        $result = [];
        foreach ($config->credentialReferences() as $reference) {
            $value = getenv($reference);
            $result[$reference] = is_string($value) && $value !== '';
        }
        return $result;
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function object(array $facts, string $key): array
    {
        $value = $facts[$key] ?? null;
        return is_array($value) && !array_is_list($value) ? $value : [];
    }

    /** @param list<array<string,mixed>> $checks @param array<string,mixed> $details */
    private function check(array &$checks, string $key, bool $passed, string $message, array $details = []): void
    {
        $checks[] = ['key' => $key, 'status' => $passed ? 'passed' : 'blocked', 'message' => $message, 'details' => $details];
    }
}
