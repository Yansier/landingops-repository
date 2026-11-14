<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

final class DeploymentConfig
{
    private const REQUIRED_EXTENSIONS = ['json', 'openssl', 'pdo', 'pdo_mysql', 'zip'];

    /** @param array<string,mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new DeploymentException('deployment_config_missing', '部署配置文件不存在。');
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DeploymentException('deployment_config_invalid_json', '部署配置不是有效 JSON。');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new DeploymentException('deployment_config_invalid', '部署配置必须是 JSON 对象。');
        }
        return self::fromArray($data);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        self::exactKeys($data, [
            'schema_version', 'deployment_scope', 'installation', 'target', 'application', 'paths',
            'runtime', 'database', 'tls', 'credentials', 'release', 'persistence',
        ], '$');
        self::equals($data, 'schema_version', 'landingops-deployment/1.0', '$');
        self::equals($data, 'deployment_scope', 'landingops_platform', '$');
        $installation = self::object($data, 'installation', '$');
        self::exactKeys($installation, ['id', 'mode'], '$.installation');
        self::pattern($installation, 'id', '/^[a-z][a-z0-9-]{2,47}$/D', '$.installation');
        self::enum($installation, 'mode', ['fresh', 'existing_migration'], '$.installation');

        $target = self::object($data, 'target', '$');
        self::exactKeys($target, [
            'ssh_host', 'ssh_port', 'ssh_user', 'ssh_host_fingerprint', 'baota_https_origin', 'expected_baota_version',
        ], '$.target');
        $host = self::string($target, 'ssh_host', '$.target');
        if (str_contains($host, '://') || preg_match('/\s/', $host) === 1
            || (filter_var($host, FILTER_VALIDATE_IP) === false
                && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/Di', $host) !== 1)) {
            throw new DeploymentException('ssh_target_invalid', 'SSH 主机必须是单一 IP 或主机名。');
        }
        $port = self::integer($target, 'ssh_port', '$.target');
        if ($port < 1 || $port > 65535) {
            throw new DeploymentException('ssh_target_invalid', 'SSH 端口无效。');
        }
        $user = self::string($target, 'ssh_user', '$.target');
        if ($user === 'root' || preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $user) !== 1) {
            throw new DeploymentException('ssh_user_unsafe', '必须使用非 root 专用部署账号。');
        }
        self::pattern($target, 'ssh_host_fingerprint', '/^SHA256:[A-Za-z0-9+\/=]{20,100}$/D', '$.target');
        self::httpsOrigin($target, 'baota_https_origin', '$.target');
        self::pattern($target, 'expected_baota_version', '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', '$.target');

        $application = self::object($data, 'application', '$');
        self::exactKeys($application, ['domain'], '$.application');
        $domain = strtolower(self::string($application, 'domain', '$.application'));
        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $domain) !== 1) {
            throw new DeploymentException('application_domain_invalid', '管理后台域名格式无效。');
        }

        $paths = self::object($data, 'paths', '$');
        self::exactKeys($paths, [
            'install_root', 'releases_dir', 'current_link', 'shared_dir', 'staging_dir', 'private_template_storage', 'backup_dir',
        ], '$.paths');
        foreach (array_keys($paths) as $key) {
            self::posixPath($paths, $key, '$.paths');
        }
        $root = rtrim((string) $paths['install_root'], '/');
        foreach (['releases_dir', 'current_link', 'shared_dir', 'staging_dir', 'private_template_storage', 'backup_dir'] as $key) {
            $path = (string) $paths[$key];
            if (!str_starts_with($path . '/', $root . '/')) {
                throw new DeploymentException('deployment_path_escape', "{$key} 必须位于 install_root 内。");
            }
        }
        if (!str_starts_with((string) $paths['private_template_storage'] . '/', rtrim((string) $paths['shared_dir'], '/') . '/')) {
            throw new DeploymentException('private_storage_invalid', '模板私有库存储必须位于 shared_dir 内。');
        }
        $exclusivePaths = ['releases_dir', 'current_link', 'shared_dir', 'staging_dir', 'backup_dir'];
        $normalizedExclusivePaths = [];
        foreach ($exclusivePaths as $key) {
            $normalizedExclusivePaths[$key] = rtrim((string) $paths[$key], '/');
        }
        if (count(array_unique($normalizedExclusivePaths)) !== count($normalizedExclusivePaths)) {
            throw new DeploymentException('deployment_path_overlap', 'release、current、shared、staging 与 backup 路径必须彼此独立。');
        }

        $runtime = self::object($data, 'runtime', '$');
        self::exactKeys($runtime, ['php_constraint', 'required_extensions', 'nginx_required'], '$.runtime');
        self::equals($runtime, 'php_constraint', '>=8.1 <9.0', '$.runtime');
        $extensions = self::stringList($runtime, 'required_extensions', '$.runtime');
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (!in_array($extension, $extensions, true)) {
                throw new DeploymentException('php_extension_contract_incomplete', 'PHP 扩展契约缺少 ' . $extension . '。');
            }
        }
        self::boolean($runtime, 'nginx_required', '$.runtime', true);

        $database = self::object($data, 'database', '$');
        self::exactKeys($database, ['host', 'port', 'name', 'user', 'engine', 'mode'], '$.database');
        self::string($database, 'host', '$.database');
        $databasePort = self::integer($database, 'port', '$.database');
        if ($databasePort < 1 || $databasePort > 65535) {
            throw new DeploymentException('database_target_invalid', '数据库端口无效。');
        }
        foreach (['name', 'user'] as $key) {
            self::pattern($database, $key, '/^[A-Za-z0-9_]{1,64}$/D', '$.database');
        }
        if (str_ends_with(strtolower((string) $database['name']), '_test')) {
            throw new DeploymentException('test_database_forbidden', '生产部署配置不得使用测试数据库名称。');
        }
        self::enum($database, 'engine', ['mysql8', 'mariadb10'], '$.database');
        self::enum($database, 'mode', ['fresh', 'existing'], '$.database');
        $expectedDatabaseMode = $installation['mode'] === 'fresh' ? 'fresh' : 'existing';
        if ($database['mode'] !== $expectedDatabaseMode) {
            throw new DeploymentException('installation_database_mode_mismatch', '安装模式与数据库模式不一致。');
        }

        $tls = self::object($data, 'tls', '$');
        self::exactKeys($tls, ['required', 'certificate_mode', 'expected_hostname'], '$.tls');
        self::boolean($tls, 'required', '$.tls', true);
        self::equals($tls, 'certificate_mode', 'preprovisioned', '$.tls');
        if (!hash_equals($domain, strtolower(self::string($tls, 'expected_hostname', '$.tls')))) {
            throw new DeploymentException('tls_identity_mismatch', 'TLS 主机名必须与管理后台域名一致。');
        }

        $credentials = self::object($data, 'credentials', '$');
        self::exactKeys($credentials, [
            'ssh_private_key_env', 'database_password_env', 'application_keyring_env', 'application_active_key_version_env',
        ], '$.credentials');
        foreach (array_keys($credentials) as $key) {
            self::pattern($credentials, $key, '/^LANDINGOPS_[A-Z0-9_]{3,100}$/D', '$.credentials');
        }

        $release = self::object($data, 'release', '$');
        self::exactKeys($release, [
            'atomic_switch_required', 'same_filesystem_required', 'retain_releases', 'health_path',
            'source', 'database_auto_rollback',
        ], '$.release');
        self::boolean($release, 'atomic_switch_required', '$.release', true);
        self::boolean($release, 'same_filesystem_required', '$.release', true);
        $retain = self::integer($release, 'retain_releases', '$.release');
        if ($retain < 2 || $retain > 20) {
            throw new DeploymentException('release_retention_invalid', '至少必须保留两个 release。');
        }
        self::pattern($release, 'health_path', '#^/[a-z0-9/_-]{1,100}$#D', '$.release');
        self::equals($release, 'source', 'github_built_artifact', '$.release');
        self::boolean($release, 'database_auto_rollback', '$.release', false);

        $persistence = self::object($data, 'persistence', '$');
        self::exactKeys($persistence, [
            'database_backup_required', 'template_storage_required', 'keyring_backup_required', 'restore_test_required_before_migration',
        ], '$.persistence');
        foreach (array_keys($persistence) as $key) {
            self::boolean($persistence, $key, '$.persistence', true);
        }
        self::rejectSensitiveValues($data, '$');
        return new self($data);
    }

    /** @return array<string,mixed> */
    public function all(): array { return $this->data; }
    public function installationId(): string { return (string) $this->data['installation']['id']; }
    public function installationMode(): string { return (string) $this->data['installation']['mode']; }
    public function installRoot(): string { return (string) $this->data['paths']['install_root']; }
    public function databaseMode(): string { return (string) $this->data['database']['mode']; }
    /** @return array<string,string> */
    public function credentialReferences(): array { return $this->data['credentials']; }
    /** @return list<string> */
    public function requiredExtensions(): array { return $this->data['runtime']['required_extensions']; }

    /** @param array<string,mixed> $object @param list<string> $keys */
    private static function exactKeys(array $object, array $keys, string $path): void
    {
        $unknown = array_diff(array_keys($object), $keys);
        $missing = array_diff($keys, array_keys($object));
        if ($unknown !== [] || $missing !== []) {
            throw new DeploymentException('deployment_config_shape_invalid', $path . ' 字段不完整或包含未知字段。', [
                'path' => $path, 'unknown' => array_values($unknown), 'missing' => array_values($missing),
            ]);
        }
    }

    /** @param array<string,mixed> $object @return array<string,mixed> */
    private static function object(array $object, string $key, string $path): array
    {
        $value = $object[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new DeploymentException('deployment_config_type_invalid', "{$path}.{$key} 必须是对象。");
        }
        return $value;
    }

    /** @param array<string,mixed> $object */
    private static function string(array $object, string $key, string $path): string
    {
        $value = $object[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new DeploymentException('deployment_config_type_invalid', "{$path}.{$key} 必须是非空字符串。");
        }
        return trim($value);
    }

    /** @param array<string,mixed> $object */
    private static function integer(array $object, string $key, string $path): int
    {
        $value = $object[$key] ?? null;
        if (!is_int($value)) {
            throw new DeploymentException('deployment_config_type_invalid', "{$path}.{$key} 必须是整数。");
        }
        return $value;
    }

    /** @param array<string,mixed> $object */
    private static function boolean(array $object, string $key, string $path, bool $expected): void
    {
        if (!is_bool($object[$key] ?? null) || $object[$key] !== $expected) {
            throw new DeploymentException('deployment_config_policy_invalid', "{$path}.{$key} 必须为 " . ($expected ? 'true' : 'false') . '。');
        }
    }

    /** @param array<string,mixed> $object */
    private static function equals(array $object, string $key, string $expected, string $path): void
    {
        if (!is_string($object[$key] ?? null) || !hash_equals($expected, $object[$key])) {
            throw new DeploymentException('deployment_config_value_invalid', "{$path}.{$key} 值无效。");
        }
    }

    /** @param array<string,mixed> $object @param list<string> $allowed */
    private static function enum(array $object, string $key, array $allowed, string $path): void
    {
        $value = self::string($object, $key, $path);
        if (!in_array($value, $allowed, true)) {
            throw new DeploymentException('deployment_config_value_invalid', "{$path}.{$key} 值无效。");
        }
    }

    /** @param array<string,mixed> $object */
    private static function pattern(array $object, string $key, string $pattern, string $path): void
    {
        if (preg_match($pattern, self::string($object, $key, $path)) !== 1) {
            throw new DeploymentException('deployment_config_value_invalid', "{$path}.{$key} 格式无效。");
        }
    }

    /** @param array<string,mixed> $object */
    private static function httpsOrigin(array $object, string $key, string $path): void
    {
        $value = self::string($object, $key, $path);
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new DeploymentException('baota_origin_invalid', '宝塔地址必须是规范化的固定 HTTPS origin。');
        }
        $parts = parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !is_string($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/')) {
            throw new DeploymentException('baota_origin_invalid', '宝塔地址必须是固定 HTTPS origin。');
        }
        $host = $parts['host'];
        $isIpv6 = str_starts_with($host, '[') && str_ends_with($host, ']');
        $hostValue = $isIpv6 ? substr($host, 1, -1) : $host;
        $validHost = filter_var($hostValue, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $hostValue) === 1;
        $port = $parts['port'] ?? null;
        if (!$validHost || $hostValue !== strtolower($hostValue) || ($port !== null && ($port < 1 || $port > 65535))) {
            throw new DeploymentException('baota_origin_invalid', '宝塔地址主机或端口不是规范化值。');
        }
        $serializedHost = str_contains($hostValue, ':') ? '[' . $hostValue . ']' : $hostValue;
        $normalized = 'https://' . $serializedHost . ($port === null ? '' : ':' . $port);
        if ($value !== $normalized && $value !== $normalized . '/') {
            throw new DeploymentException('baota_origin_invalid', '宝塔地址必须是规范化的固定 HTTPS origin。');
        }
    }

    /** @param array<string,mixed> $object */
    private static function posixPath(array $object, string $key, string $path): void
    {
        $value = self::string($object, $key, $path);
        if (!str_starts_with($value, '/') || str_contains($value, "\0") || preg_match('#(?:^|/)\.\.?(/|$)#', $value) === 1
            || str_contains($value, '//')) {
            throw new DeploymentException('deployment_path_invalid', "{$path}.{$key} 必须是规范化绝对 Linux 路径。");
        }
    }

    /** @param array<string,mixed> $object @return list<string> */
    private static function stringList(array $object, string $key, string $path): array
    {
        $value = $object[$key] ?? null;
        if (!is_array($value) || !array_is_list($value) || $value === []
            || array_filter($value, static fn (mixed $item): bool => !is_string($item) || $item === '') !== []) {
            throw new DeploymentException('deployment_config_type_invalid', "{$path}.{$key} 必须是非空字符串数组。");
        }
        return array_values(array_unique($value));
    }

    private static function rejectSensitiveValues(mixed $value, string $path): void
    {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            $childPath = $path . '.' . (string) $key;
            if (is_string($child) && is_string($key) && preg_match('/(?:password|token|api_key|private_key|secret|keyring)/i', $key) === 1
                && !str_ends_with($key, '_env')) {
                throw new DeploymentException('sensitive_config_forbidden', $childPath . ' 不得保存敏感值，只允许环境变量引用。');
            }
            self::rejectSensitiveValues($child, $childPath);
        }
    }
}
