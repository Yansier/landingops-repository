<?php

declare(strict_types=1);

use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\PasswordHasher;
use LandingOps\ControlPlane\Auth\Principal;
use LandingOps\ControlPlane\Auth\Role;
use LandingOps\ControlPlane\Auth\SessionService;
use LandingOps\ControlPlane\Database\DatabaseConfig;
use LandingOps\ControlPlane\Database\MigrationRunner;
use LandingOps\ControlPlane\Database\PdoFactory;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Repository\PdoControlPlaneRepository;
use LandingOps\ControlPlane\Security\AeadSecretCipher;
use LandingOps\ControlPlane\Service\BaotaConfigService;
use LandingOps\ControlPlane\Service\CloudflareConnectionService;
use LandingOps\ControlPlane\Service\DomainResourceService;
use LandingOps\ControlPlane\Service\DeploymentDryRunService;
use LandingOps\ControlPlane\Service\OwnershipTransferService;
use LandingOps\ControlPlane\Service\ResourceAccessService;
use LandingOps\ControlPlane\Service\TemplateGrantService;
use LandingOps\ControlPlane\Service\TemplateManagementService;
use LandingOps\ControlPlane\Support\IdGenerator;
use LandingOps\ControlPlane\Template\PrivateTemplateStorage;
use LandingOps\ControlPlane\Template\UploadedTemplateFile;

$root = dirname(__DIR__);
require is_file($root . '/vendor/autoload.php') ? $root . '/vendor/autoload.php' : $root . '/src/autoload.php';
require_once __DIR__ . '/FixtureFactory.php';

function integrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectPdoConstraint(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (PDOException) {
        return;
    }
    throw new RuntimeException($message);
}

function removeIntegrationDirectory(string $path): void
{
    foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            @unlink($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
}

function removeIntegrationTree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            removeIntegrationTree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
}

function activeKeyAnomalyCount(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT "
        . "(SELECT COUNT(*) FROM site_releases WHERE "
        . "(status = 'active' AND (active_instance_id IS NULL OR active_instance_id <> instance_id)) "
        . "OR (status <> 'active' AND active_instance_id IS NOT NULL)) + "
        . "(SELECT COUNT(*) FROM domain_reservations WHERE "
        . "(status IN ('held','committed') AND (active_fqdn IS NULL OR active_fqdn <> normalized_fqdn)) "
        . "OR (status IN ('released','expired') AND active_fqdn IS NOT NULL))",
    )->fetchColumn();
}

function showCreateTable(PDO $pdo, string $table): string
{
    $row = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? (string) array_values($row)[1] : '';
}

function cleanupStaleTestConnections(PDO $pdo, string $currentUser): int
{
    $account = explode('@', $currentUser, 2)[0];
    $currentId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
    $processes = $pdo->query('SHOW PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC);
    $threadIds = [];
    foreach ($processes as $process) {
        $threadId = (int) ($process['Id'] ?? 0);
        if ($threadId !== $currentId
            && ($process['User'] ?? null) === $account
            && ($process['db'] ?? null) === 'landingops_test'
            && ($process['Command'] ?? null) === 'Sleep'
            && (int) ($process['Time'] ?? 0) >= 60
        ) {
            $threadIds[] = $threadId;
        }
    }
    if ($threadIds === []) {
        return 0;
    }
    if (getenv('LANDINGOPS_TEST_CLEAN_STALE_TRANSACTIONS') !== 'I_UNDERSTAND') {
        throw new RuntimeException(
            '发现专用测试账号遗留的休眠连接：' . implode(',', $threadIds)
            . '。仅可通过安全测试脚本显式启用清理。',
        );
    }
    foreach ($threadIds as $threadId) {
        $pdo->exec('KILL CONNECTION ' . $threadId);
    }
    return count($threadIds);
}

function verifyMigrationRecovery(PDO $pdo, MigrationRunner $runner): void
{
    $baseVersion = '991_phase1c1_recovery_base';
    $retryVersion = '992_phase1c1_recovery_retry';
    $baseTable = 'phase1c1_recovery_base';
    $retryTable = 'phase1c1_recovery_retry';
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'landingops-mysql-recovery-' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('无法创建迁移恢复测试目录。');
    }
    try {
        $pdo->exec("DROP TABLE IF EXISTS {$retryTable}");
        $pdo->exec("DROP TABLE IF EXISTS {$baseTable}");
        $delete = $pdo->prepare('DELETE FROM schema_migrations WHERE version IN (?, ?)');
        $delete->execute([$baseVersion, $retryVersion]);
        file_put_contents($directory . "/{$baseVersion}.sql", "CREATE TABLE {$baseTable} (id INT PRIMARY KEY) ENGINE=InnoDB;\n");
        file_put_contents(
            $directory . "/{$retryVersion}.sql",
            "CREATE TABLE {$retryTable} (id INT PRIMARY KEY, broken SYNTAX) ENGINE=InnoDB;\n",
        );
        $failed = false;
        try {
            $runner->migrate($pdo, $directory);
        } catch (PDOException) {
            $failed = true;
        }
        integrationAssert($failed, '故障注入迁移没有失败。');
        $status = $pdo->prepare('SELECT version FROM schema_migrations WHERE version IN (?, ?) ORDER BY version');
        $status->execute([$baseVersion, $retryVersion]);
        integrationAssert(
            $status->fetchAll(PDO::FETCH_COLUMN) === [$baseVersion],
            '失败版本被错误标记，或已成功版本状态丢失。',
        );
        file_put_contents($directory . "/{$retryVersion}.sql", "CREATE TABLE {$retryTable} (id INT PRIMARY KEY) ENGINE=InnoDB;\n");
        integrationAssert($runner->migrate($pdo, $directory) === [$retryVersion], '修复后未从失败版本安全恢复。');
        $count = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() "
            . "AND table_name IN ('{$baseTable}', '{$retryTable}')",
        )->fetchColumn();
        integrationAssert((int) $count === 2, '迁移恢复测试表不完整。');
    } finally {
        $pdo->exec("DROP TABLE IF EXISTS {$retryTable}");
        $pdo->exec("DROP TABLE IF EXISTS {$baseTable}");
        $delete = $pdo->prepare('DELETE FROM schema_migrations WHERE version IN (?, ?)');
        $delete->execute([$baseVersion, $retryVersion]);
        removeIntegrationDirectory($directory);
    }
}

if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "SKIP: 当前 PHP 缺少 pdo_mysql，未连接数据库。请在 php.ini 启用 extension=pdo_mysql 并确认 extension_dir。\n");
    exit(0);
}

$dsn = getenv('LANDINGOPS_TEST_MYSQL_DSN');
$user = getenv('LANDINGOPS_TEST_MYSQL_USER');
$password = getenv('LANDINGOPS_TEST_MYSQL_PASSWORD');
if (!is_string($dsn) || $dsn === '' || !is_string($user) || !is_string($password)) {
    fwrite(STDOUT, "SKIP: 未设置 LANDINGOPS_TEST_MYSQL_DSN/USER/PASSWORD；未连接真实 MySQL。\n");
    exit(0);
}
if (!preg_match('/(?:^|;)dbname=([^;]+)/i', $dsn, $databaseMatch)
    || strtolower(trim($databaseMatch[1])) !== 'landingops_test'
) {
    fwrite(STDERR, "ERROR: 只允许连接明确指定的独立数据库 landingops_test。\n");
    exit(2);
}
preg_match('/(?:^|;)host=([^;]+)/i', $dsn, $hostMatch);
$host = strtolower(trim($hostMatch[1] ?? 'localhost'));
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
    && getenv('LANDINGOPS_TEST_MYSQL_ALLOW_REMOTE') !== 'I_UNDERSTAND'
) {
    fwrite(STDERR, "ERROR: 远程测试库还需设置 LANDINGOPS_TEST_MYSQL_ALLOW_REMOTE=I_UNDERSTAND。\n");
    exit(2);
}

try {
    $connectionSummary = null;
    $stage = 'connect';
    $pdo = (new PdoFactory())->connect(new DatabaseConfig($dsn, $user, $password));
    integrationAssert((string) $pdo->query('SELECT DATABASE()')->fetchColumn() === 'landingops_test', '当前数据库不是 landingops_test。');
    $currentUser = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
    $grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($grants as $grant) {
        $grant = (string) $grant;
        $usageOnly = preg_match('/^GRANT\s+USAGE\s+ON\s+\*\.\*/i', $grant) === 1;
        $testDatabaseOnly = preg_match('/\sON\s+`?landingops_test`?\.\*\s+TO\s/i', $grant) === 1;
        integrationAssert(
            $usageOnly || $testDatabaseOnly,
            '数据库账号存在 landingops_test 以外的显式权限：' . $grant,
        );
    }
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $connectionSummary = 'server=' . $version . ', database=landingops_test, account=' . $currentUser;
    fwrite(STDOUT, 'INFO: ' . $connectionSummary . "，权限范围检查通过。\n");
    $cleanedConnections = cleanupStaleTestConnections($pdo, $currentUser);
    if ($cleanedConnections > 0) {
        fwrite(STDOUT, 'INFO: 已关闭 ' . $cleanedConnections . " 个同账号、同测试库且休眠超过 60 秒的遗留连接。\n");
    }
    $stage = 'migrations-and-schema';
    $numericVersion = preg_replace('/[^0-9.].*$/', '', $version) ?: '0';
    $isMariaDb = stripos($version, 'mariadb') !== false;
    $minimumVersion = $isMariaDb ? '10.6.0' : '8.0.0';
    integrationAssert(
        version_compare($numericVersion, $minimumVersion, '>='),
        ($isMariaDb ? 'MariaDB' : 'MySQL') . ' 版本低于支持下限 ' . $minimumVersion . '：' . $version,
    );
    $existingActiveKeyTables = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() "
        . "AND table_name IN ('site_releases', 'domain_reservations')",
    )->fetchColumn();
    if ($existingActiveKeyTables === 2) {
        integrationAssert(activeKeyAnomalyCount($pdo) === 0, '002 执行前发现活动键历史异常，必须人工处理。');
        fwrite(STDOUT, "INFO: 002 执行前历史活动键异常记录为 0。\n");
    }

    $runner = new MigrationRunner();
    $runner->migrate($pdo, $root . '/database/migrations');
    integrationAssert(activeKeyAnomalyCount($pdo) === 0, '002 执行后出现活动键异常。');
    $structureBeforeRepeat = [
        showCreateTable($pdo, 'site_releases'),
        showCreateTable($pdo, 'domain_reservations'),
        showCreateTable($pdo, 'cloudflare_connections'),
        showCreateTable($pdo, 'cloudflare_zones'),
        showCreateTable($pdo, 'baota_config'),
        showCreateTable($pdo, 'instances'),
    ];
    $dataBeforeRepeat = [
        (int) $pdo->query('SELECT COUNT(*) FROM site_releases')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM domain_reservations')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM cloudflare_connections')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM cloudflare_zones')->fetchColumn(),
    ];
    integrationAssert($runner->migrate($pdo, $root . '/database/migrations') === [], '第二次迁移不应应用版本。');
    integrationAssert($structureBeforeRepeat === [
        showCreateTable($pdo, 'site_releases'),
        showCreateTable($pdo, 'domain_reservations'),
        showCreateTable($pdo, 'cloudflare_connections'),
        showCreateTable($pdo, 'cloudflare_zones'),
        showCreateTable($pdo, 'baota_config'),
        showCreateTable($pdo, 'instances'),
    ], '重复执行迁移改变了活动键表结构。');
    integrationAssert($dataBeforeRepeat === [
        (int) $pdo->query('SELECT COUNT(*) FROM site_releases')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM domain_reservations')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM cloudflare_connections')->fetchColumn(),
        (int) $pdo->query('SELECT COUNT(*) FROM cloudflare_zones')->fetchColumn(),
    ], '重复执行迁移改变了活动键表数据。');
    $newCheckCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() "
        . "AND constraint_name IN ('chk_site_releases_active_key_not_null', "
        . "'chk_domain_reservations_active_key_not_null') AND constraint_type = 'CHECK'",
    )->fetchColumn();
    integrationAssert($newCheckCount === 2, '002 的两个严格 CHECK 未完整创建。');
    $cloudflareCheckCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() "
        . "AND constraint_name IN ('chk_cloudflare_connections_admin_state','chk_cloudflare_connections_verification',"
        . "'chk_cloudflare_connections_version','chk_cloudflare_zones_admin_state','chk_cloudflare_zones_verification',"
        . "'chk_cloudflare_zones_version') AND constraint_type = 'CHECK'",
    )->fetchColumn();
    integrationAssert($cloudflareCheckCount === 6, '003 的管理员、验证和版本 CHECK 未完整创建。');
    $bindingCheckCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() "
        . "AND constraint_name IN ('chk_cloudflare_connections_credential_context_version',"
        . "'chk_cloudflare_zones_verified_credential_context') AND constraint_type = 'CHECK'",
    )->fetchColumn();
    integrationAssert($bindingCheckCount === 2, '004 的凭据上下文 CHECK 未完整创建。');
    $bindingColumnCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() "
        . "AND ((table_name='cloudflare_connections' AND column_name='credential_context_version') "
        . "OR (table_name='cloudflare_zones' AND column_name='verified_credential_context_version'))",
    )->fetchColumn();
    integrationAssert($bindingColumnCount === 2, '004 的凭据上下文字段未完整创建。');
    $baotaCheckCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() "
        . "AND constraint_name IN ('chk_baota_config_admin_state','chk_baota_config_verification_status',"
        . "'chk_baota_config_version','chk_instances_baota_ownership_evidence') AND constraint_type = 'CHECK'",
    )->fetchColumn();
    integrationAssert($baotaCheckCount === 4, '005 的宝塔状态与所有权证据 CHECK 未完整创建。');
    $baotaColumnCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND "
        . "((table_name='baota_config' AND column_name IN ('api_key_hint','admin_state','verification_status',"
        . "'last_verification_attempt_at','last_verification_result_json','panel_version','capabilities_json','version')) "
        . "OR (table_name='instances' AND column_name IN ('baota_site_external_id','baota_site_root_path','ownership_marker_hash'))) ",
    )->fetchColumn();
    integrationAssert($baotaColumnCount === 11, '005 的宝塔连接与实例所有权字段未完整创建。');

    $businessTables = [
        'teams', 'users', 'auth_sessions', 'templates', 'template_versions', 'template_grants',
        'baota_config', 'cloudflare_connections', 'cloudflare_zones', 'primary_domains', 'domain_grants',
        'backup_page_assets', 'vendor_component_versions', 'instances', 'instance_owner_history',
        'config_versions', 'site_releases', 'deployment_batches', 'deployment_jobs', 'job_steps',
        'domain_reservations', 'audit_logs',
    ];
    $tableQuery = $pdo->prepare(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('
        . implode(',', array_fill(0, count($businessTables), '?')) . ')',
    );
    $tableQuery->execute($businessTables);
    $createdTables = $tableQuery->fetchAll(PDO::FETCH_COLUMN);
    sort($businessTables);
    sort($createdTables);
    integrationAssert($createdTables === $businessTables, '22 张业务表未全部创建。');
    foreach ($businessTables as $table) {
        integrationAssert(
            $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1")->fetchColumn() === false,
            "安全检查失败：独立测试库表 {$table} 已包含数据，测试已停止。",
        );
    }
    verifyMigrationRecovery($pdo, $runner);

    $ids = new IdGenerator();
    $hasher = new PasswordHasher();
    $repository = new PdoControlPlaneRepository($pdo);
    $now = gmdate('Y-m-d H:i:s') . '.000000';
    $teamA = $ids->generate();
    $teamB = $ids->generate();
    $superId = $ids->generate();
    $adminAId = $ids->generate();
    $staffAId = $ids->generate();
    $staffA2Id = $ids->generate();
    $templateId = $ids->generate();
    $templateVersionId = $ids->generate();
    $connectionId = $ids->generate();
    $zoneId = $ids->generate();
    $domainId = $ids->generate();
    $instanceId = $ids->generate();
    $configVersionId = $ids->generate();

    $integrationStorageRoot = null;
    $stage = 'business-fixtures-and-constraints';
    $pdo->beginTransaction();
    try {
        $insertTeam = $pdo->prepare(
            "INSERT INTO teams(id,name,slug,status,version,created_at,updated_at) VALUES (?,?,?,'active',1,?,?)",
        );
        $insertTeam->execute([$teamA, 'Integration A', 'integration-a-' . strtolower($teamA), $now, $now]);
        $insertTeam->execute([$teamB, 'Integration B', 'integration-b-' . strtolower($teamB), $now, $now]);
        expectPdoConstraint(
            static fn () => $pdo->prepare(
                "INSERT INTO teams(id,name,slug,status,version,created_at,updated_at) VALUES (?,?,?,'invalid',1,?,?)",
            )->execute([$ids->generate(), 'Invalid', 'invalid-' . strtolower($ids->generate()), $now, $now]),
            'CHECK 没有拒绝非法团队状态。',
        );

        $passwordText = 'Integration password 123!';
        foreach ([
            [$superId, null, 'super', Role::SUPER_ADMIN],
            [$adminAId, $teamA, 'admin-a', Role::TEAM_ADMIN],
            [$staffAId, $teamA, 'staff-a', Role::STAFF],
            [$staffA2Id, $teamA, 'staff-a2', Role::STAFF],
        ] as [$id, $teamId, $label, $role]) {
            $email = $label . '-' . strtolower($id) . '@integration.invalid';
            $repository->insertUser([
                'id' => $id, 'team_id' => $teamId, 'email' => $email, 'normalized_email' => $email,
                'display_name' => $label, 'password_hash' => $hasher->hash($passwordText), 'role' => $role,
                'status' => 'active', 'version' => 1, 'password_changed_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $staffEmail = 'staff-a-' . strtolower($staffAId) . '@integration.invalid';
        expectPdoConstraint(
            static fn () => $repository->insertUser([
                'id' => $ids->generate(), 'team_id' => $teamA, 'email' => strtoupper($staffEmail),
                'normalized_email' => $staffEmail, 'display_name' => 'Duplicate',
                'password_hash' => $hasher->hash($passwordText), 'role' => Role::STAFF, 'status' => 'active',
                'version' => 1, 'password_changed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]),
            'UNIQUE 没有拒绝重复 normalized_email。',
        );
        $missingTeamEmail = strtolower($ids->generate()) . '@integration.invalid';
        expectPdoConstraint(
            static fn () => $repository->insertUser([
                'id' => $ids->generate(), 'team_id' => $ids->generate(), 'email' => $missingTeamEmail,
                'normalized_email' => $missingTeamEmail, 'display_name' => 'Missing Team',
                'password_hash' => $hasher->hash($passwordText), 'role' => Role::TEAM_ADMIN, 'status' => 'active',
                'version' => 1, 'password_changed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]),
            '外键没有拒绝不存在的团队。',
        );

        $pdo->prepare(
            "INSERT INTO templates(id,slug,name,description,owner_team_id,created_by_user_id,status,created_at,updated_at) "
            . "VALUES (?,?,?,?,?,?,'active',?,?)",
        )->execute([$templateId, 'integration-' . strtolower($templateId), 'Integration', '', $teamA, $adminAId, $now, $now]);
        $pdo->prepare(
            "INSERT INTO template_versions(id,template_id,version,standard_version,zip_storage_key,zip_sha256,"
            . "manifest_json,config_schema_json,capability_tags_json,validation_status,validation_report_json,"
            . "uploaded_by_user_id,published_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,'valid',?,?,?,?)",
        )->execute([
            $templateVersionId, $templateId, '1.0.0', '1.0', 'integration/template.zip', hash('sha256', random_bytes(32)),
            '{}', null, '[]', '{}', $adminAId, $now, $now,
        ]);
        $pdo->prepare(
            "INSERT INTO cloudflare_connections(id,label,account_external_id,token_ciphertext,secret_key_version,"
            . "token_hint,status,created_by_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,'unverified',?,?,?)",
        )->execute([$connectionId, 'integration-' . $connectionId, null, random_bytes(32), 'integration', 'test', $superId, $now, $now]);
        $pdo->prepare(
            "INSERT INTO cloudflare_zones(id,connection_id,external_zone_id,zone_name,normalized_zone_name,status,created_at,updated_at) "
            . "VALUES (?,?,?,?,?,'unverified',?,?)",
        )->execute([$zoneId, $connectionId, 'zone-' . $zoneId, 'integration.invalid', strtolower($zoneId) . '.invalid', $now, $now]);
        $pdo->prepare(
            "INSERT INTO primary_domains(id,zone_id,domain_name,normalized_domain_name,status,dns_policy_json,created_by_user_id,created_at,updated_at) "
            . "VALUES (?,?,?,?,'active',?,?,?,?)",
        )->execute([$domainId, $zoneId, 'page.integration.invalid', strtolower($domainId) . '.invalid', '{}', $superId, $now, $now]);
        $pdo->prepare(
            "INSERT INTO instances(id,team_id,template_version_id,primary_domain_id,fqdn,normalized_fqdn,site_path_key,status,"
            . "version,created_by_user_id,current_owner_user_id,native_admin_enabled,active_config_revision,created_at,updated_at) "
            . "VALUES (?,?,?,?,?,?,?,'active',1,?,?,FALSE,0,?,?)",
        )->execute([
            $instanceId, $teamA, $templateVersionId, $domainId, strtolower($instanceId) . '.invalid',
            strtolower($instanceId) . '.invalid', 'integration-' . strtolower($instanceId), $staffAId, $staffAId, $now, $now,
        ]);

        $digest = 'sha256:' . hash('sha256', random_bytes(32));
        $pdo->prepare(
            "INSERT INTO config_versions(id,instance_id,revision,schema_version,content_hash,public_hash,config_json,"
            . "source,status,base_revision,created_by_user_id,created_at) VALUES (?,?,1,'1.0',?,?,?,'landingops',"
            . "'applied',0,?,?)",
        )->execute([$configVersionId, $instanceId, $digest, $digest, '{}', $adminAId, $now]);

        $insertRelease = $pdo->prepare(
            "INSERT INTO site_releases(id,instance_id,config_version_id,release_no,release_path_key,manifest_hash,"
            . "public_config_asset,public_hash,status,previous_release_id,active_instance_id,created_at) "
            . "VALUES (?,?,?,?,?,?,?,?,?,NULL,?,?)",
        );
        expectPdoConstraint(
            static fn () => $insertRelease->execute([
                $ids->generate(), $instanceId, $configVersionId, 1, 'release-null', $digest,
                'assets/config-null.js', $digest, 'active', null, $now,
            ]),
            'active release 的 NULL active_instance_id 未被拒绝。',
        );
        expectPdoConstraint(
            static fn () => $insertRelease->execute([
                $ids->generate(), $instanceId, $configVersionId, 2, 'release-mismatch', $digest,
                'assets/config-mismatch.js', $digest, 'active', $ids->generate(), $now,
            ]),
            'active release 的错配 active_instance_id 未被拒绝。',
        );
        $insertRelease->execute([
            $ids->generate(), $instanceId, $configVersionId, 3, 'release-active', $digest,
            'assets/config-active.js', $digest, 'active', $instanceId, $now,
        ]);
        expectPdoConstraint(
            static fn () => $insertRelease->execute([
                $ids->generate(), $instanceId, $configVersionId, 4, 'release-second-active', $digest,
                'assets/config-second.js', $digest, 'active', $instanceId, $now,
            ]),
            '相同实例的第二个 active release 未被拒绝。',
        );
        $insertRelease->execute([
            $ids->generate(), $instanceId, $configVersionId, 5, 'release-staged', $digest,
            'assets/config-staged.js', $digest, 'staged', null, $now,
        ]);

        $insertReservation = $pdo->prepare(
            "INSERT INTO domain_reservations(id,normalized_fqdn,instance_id,job_id,status,lease_expires_at,created_at,"
            . "updated_at,active_fqdn) VALUES (?,?,?,NULL,?,?,?,?,?)",
        );
        $reservedFqdn = strtolower($ids->generate()) . '.integration.invalid';
        expectPdoConstraint(
            static fn () => $insertReservation->execute([
                $ids->generate(), $reservedFqdn, $instanceId, 'held', $now, $now, $now, null,
            ]),
            'held 预留的 NULL active_fqdn 未被拒绝。',
        );
        expectPdoConstraint(
            static fn () => $insertReservation->execute([
                $ids->generate(), strtolower($ids->generate()) . '.integration.invalid', $instanceId,
                'committed', $now, $now, $now, null,
            ]),
            'committed 预留的 NULL active_fqdn 未被拒绝。',
        );
        $insertReservation->execute([
            $ids->generate(), $reservedFqdn, $instanceId, 'held', $now, $now, $now, $reservedFqdn,
        ]);
        expectPdoConstraint(
            static fn () => $insertReservation->execute([
                $ids->generate(), $reservedFqdn, $instanceId, 'committed', $now, $now, $now, $reservedFqdn,
            ]),
            '相同 FQDN 的第二个活动预留未被拒绝。',
        );
        $insertReservation->execute([
            $ids->generate(), strtolower($ids->generate()) . '.integration.invalid', $instanceId,
            'released', $now, $now, $now, null,
        ]);
        $insertReservation->execute([
            $ids->generate(), strtolower($ids->generate()) . '.integration.invalid', $instanceId,
            'expired', $now, $now, $now, null,
        ]);

        $stage = 'session-and-owner-transfer';
        $super = Principal::fromRow($repository->findUserById($superId) ?? []);
        $adminA = Principal::fromRow($repository->findUserById($adminAId) ?? []);
        $sessions = new SessionService($repository);
        $session = $sessions->login($staffEmail, $passwordText, 'mysql-integration', '127.0.0.1', 'mysql-session');
        $authenticated = $sessions->authenticate($session->cookieToken);
        integrationAssert($authenticated['principal']->id === $staffAId, 'PDO Session 登录失败。');
        $sessions->assertCsrf($authenticated['session'], $session->csrfToken);
        $sessions->logout($session->sessionId, $authenticated['principal'], 'mysql-logout');
        try {
            $sessions->authenticate($session->cookieToken);
            throw new RuntimeException('登出后的 Session 仍有效。');
        } catch (ApiException $exception) {
            integrationAssert($exception->errorCode === 'session_invalid', 'Session 失效错误码不正确。');
        }

        $transfers = new OwnershipTransferService($repository, new AuthorizationService());
        $updated = $transfers->transfer($adminA, $instanceId, $staffA2Id, 1, 'integration transfer', 'mysql-transfer');
        integrationAssert($updated['current_owner_user_id'] === $staffA2Id, '负责人事务转交失败。');
        try {
            $transfers->transfer($adminA, $instanceId, $staffAId, 1, 'stale transfer', 'mysql-transfer-stale');
            throw new RuntimeException('乐观锁没有拒绝旧版本。');
        } catch (ApiException $exception) {
            integrationAssert($exception->errorCode === 'instance_version_conflict', '乐观锁错误码不正确。');
        }

        $grantService = new TemplateGrantService($repository, new AuthorizationService());
        $templateAccess = new ResourceAccessService($repository, new AuthorizationService());
        $grantService->grantUploaderTeam($adminA, $templateVersionId, 'mysql-template-default');
        $templateAccess->assertTemplateVersion($adminA, $teamA, $templateVersionId);
        $grantService->revokeBySuperAdmin($super, $templateVersionId, $teamA, 'mysql-template-revoke');
        try {
            $templateAccess->assertTemplateVersion($adminA, $teamA, $templateVersionId);
            throw new RuntimeException('被撤销的模板授权仍可使用。');
        } catch (ApiException $exception) {
            integrationAssert($exception->errorCode === 'template_not_available', '模板撤销错误码不正确。');
        }
        $grantService->grantBySuperAdmin($super, $templateVersionId, $teamA, 'mysql-template-regrant');
        $templateAccess->assertTemplateVersion($adminA, $teamA, $templateVersionId);

        // Phase 1E/1F：通过真实 PDO 仓储执行完整 ZIP 上传、版本入库、默认授权与授权生命周期。
        $stage = 'template-upload-and-grants';
        $integrationStorageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'landingops-mysql-template-' . bin2hex(random_bytes(6));
        if (!mkdir($integrationStorageRoot, 0700, true) && !is_dir($integrationStorageRoot)) {
            throw new RuntimeException('无法创建 PDO 模板集成测试目录。');
        }
        $zipPath = $integrationStorageRoot . DIRECTORY_SEPARATOR . 'template.zip';
        \LandingOps\Tests\FixtureFactory::sampleZip($zipPath);
        $templateManagement = new TemplateManagementService(
            $repository,
            new PrivateTemplateStorage($integrationStorageRoot . DIRECTORY_SEPARATOR . 'private'),
            new AuthorizationService(),
            grants: $grantService,
        );
        $uploaded = $templateManagement->upload(
            $adminA,
            new UploadedTemplateFile($zipPath, 'template.zip', (int) filesize($zipPath)),
            'mysql-template-upload',
        );
        integrationAssert($uploaded['validation_status'] === 'valid', '真实 PDO 模板上传未形成有效版本。');
        integrationAssert(
            $repository->findTemplateVersionByHash((string) $uploaded['zip_sha256']) !== null,
            '真实 PDO 模板版本未入库。',
        );
        integrationAssert(
            count($templateManagement->list($adminA)) >= 1,
            '上传团队未获得默认模板授权或列表不可见。',
        );
        $grantService->revokeBySuperAdmin($super, (string) $uploaded['template_version_id'], $teamA, 'mysql-upload-revoke');
        integrationAssert(
            array_filter($templateManagement->list($adminA), static fn (array $item): bool => $item['id'] === $uploaded['template_id']) === [],
            '撤销后上传模板仍对团队可见。',
        );
        $grantService->grantBySuperAdmin($super, (string) $uploaded['template_version_id'], $teamA, 'mysql-upload-regrant');
        integrationAssert(count($templateManagement->list($adminA)) >= 1, '重新授权后模板未恢复可见。');

        // Phase 1F：真实 PDO 上验证密文、连接/Zone/主域名关联及团队授权。
        $stage = 'cloudflare-domain-and-context';
        $key = random_bytes(32);
        $cipher = new AeadSecretCipher(['mysql-v1' => $key], 'mysql-v1');
        $connectionService = new CloudflareConnectionService($repository, $cipher, new AuthorizationService());
        $domainService = new DomainResourceService($repository, new AuthorizationService());
        $secretToken = 'mysql-integration-token-' . bin2hex(random_bytes(8));
        $managedConnection = $connectionService->create($super, [
            'label' => 'managed-' . strtolower($ids->generate()),
            'account_external_id' => 'account_' . strtolower($ids->generate()),
            'api_token' => $secretToken,
        ], 'mysql-cf-create');
        $storedConnection = $repository->findCloudflareConnection((string) $managedConnection['id']);
        integrationAssert(is_array($storedConnection), 'Cloudflare 连接未入库。');
        integrationAssert(
            $cipher->decrypt(
                (string) $storedConnection['token_ciphertext'],
                (string) $storedConnection['secret_key_version'],
                'landingops:cloudflare-token:' . $managedConnection['id'],
            ) === $secretToken,
            'Cloudflare Token AEAD 回读失败。',
        );
        integrationAssert(
            !str_contains(json_encode($connectionService->list($super), JSON_THROW_ON_ERROR), $secretToken),
            'Cloudflare Token 泄露到连接列表。',
        );
        $disabledConnection = $connectionService->update($super, (string) $managedConnection['id'], [
            'admin_state' => 'disabled', 'expected_version' => 1,
        ], 'mysql-cf-disable');
        integrationAssert($disabledConnection['admin_state'] === 'disabled', 'Cloudflare 连接停用失败。');
        $rotatedConnection = $connectionService->update($super, (string) $managedConnection['id'], [
            'api_token' => $secretToken . '-rotated', 'expected_version' => 2,
        ], 'mysql-cf-rotate-disabled');
        integrationAssert(
            $rotatedConnection['admin_state'] === 'disabled' && $rotatedConnection['verification_status'] === 'unverified',
            '停用连接在 Token 轮换后被错误启用。',
        );
        $managedConnection = $connectionService->update($super, (string) $managedConnection['id'], [
            'admin_state' => 'enabled', 'expected_version' => 3,
        ], 'mysql-cf-enable');
        $managedZone = $domainService->createZone($super, [
            'connection_id' => $managedConnection['id'],
            'external_zone_id' => 'external_' . strtolower($ids->generate()),
            'zone_name' => strtolower($ids->generate()) . '.integration.invalid',
        ], 'mysql-zone-create');
        $managedZoneB = $domainService->createZone($super, [
            'connection_id' => $managedConnection['id'],
            'external_zone_id' => 'external_' . strtolower($ids->generate()),
            'zone_name' => strtolower($ids->generate()) . '.integration.invalid',
        ], 'mysql-zone-create-b');
        foreach ([$managedZone, $managedZoneB] as $verifiedZone) {
            integrationAssert($repository->updateCloudflareZone((string) $verifiedZone['id'], [
                'verification_status' => 'valid',
                'verified_credential_context_version' => (int) $managedConnection['credential_context_version'],
                'cloudflare_status' => 'active', 'status' => 'active', 'updated_at' => $now,
            ], 1), '真实 PDO Zone 验证状态写入失败。');
        }
        $managedConnection = $connectionService->update($super, (string) $managedConnection['id'], [
            'api_token' => $secretToken . '-second-rotation', 'expected_version' => 4,
        ], 'mysql-cf-multi-zone-rotate');
        integrationAssert($managedConnection['credential_context_version'] === 3, 'Token 轮换未递增凭据上下文版本。');
        foreach ([$managedZone, $managedZoneB] as $invalidatedZone) {
            $storedZone = $repository->findCloudflareZone((string) $invalidatedZone['id']);
            integrationAssert(
                is_array($storedZone)
                && $storedZone['verification_status'] === 'unverified'
                && $storedZone['verified_credential_context_version'] === null
                && (int) $storedZone['version'] === 3,
                'Token 轮换未使全部关联 Zone 的旧证据和乐观锁版本失效。',
            );
        }
        $managedDomain = $domainService->createDomain($super, [
            'zone_id' => $managedZone['id'],
            'domain_name' => 'pages.' . $managedZone['normalized_zone_name'],
        ], 'mysql-domain-create');
        $domainService->grant($super, (string) $managedDomain['id'], $teamA, 'mysql-domain-grant');
        integrationAssert(count($domainService->listDomains($adminA)) >= 1, '团队管理员未看到获授权主域名。');
        integrationAssert($domainService->listDomains(Principal::fromRow($repository->findUserById($staffAId) ?? [])) === [], '未验证资源不应对员工显示为可用。');
        $domainService->revoke($super, (string) $managedDomain['id'], $teamA, 'mysql-domain-revoke');
        integrationAssert($domainService->listDomains($adminA) === [], '撤销后团队仍能看到主域名。');
        $domainService->grant($super, (string) $managedDomain['id'], $teamA, 'mysql-domain-regrant');
        integrationAssert(count($domainService->listDomains($adminA)) >= 1, '主域名重新授权失败。');

        integrationAssert($repository->updateCloudflareConnection((string) $managedConnection['id'], [
            'verification_status' => 'valid', 'status' => 'active', 'last_verified_at' => $now,
            'updated_at' => $now,
        ], 5), 'Dry Run 前无法建立当前连接验证证据。');
        integrationAssert($repository->updateCloudflareZone((string) $managedZone['id'], [
            'verification_status' => 'valid',
            'verified_credential_context_version' => (int) $managedConnection['credential_context_version'],
            'cloudflare_status' => 'active', 'status' => 'active', 'updated_at' => $now,
        ], 3), 'Dry Run 前无法建立当前 Zone 验证证据。');
        $stage = 'deployment-dry-run';
        $dryRunBefore = [
            (int) $pdo->query('SELECT COUNT(*) FROM instances')->fetchColumn(),
            (int) $pdo->query('SELECT COUNT(*) FROM domain_reservations')->fetchColumn(),
            (int) $pdo->query('SELECT COUNT(*) FROM deployment_jobs')->fetchColumn(),
            (int) $pdo->query('SELECT COUNT(*) FROM job_steps')->fetchColumn(),
        ];
        $dryRun = (new DeploymentDryRunService($repository))->run($adminA, [
            'template_version_id' => (string) $uploaded['template_version_id'],
            'primary_domain_id' => (string) $managedDomain['id'],
            'subdomain_prefix' => 'dry-run',
            'config' => json_decode(json_encode(\LandingOps\Tests\FixtureFactory::sampleConfig(), JSON_THROW_ON_ERROR), true, 128, JSON_THROW_ON_ERROR),
        ]);
        integrationAssert($dryRun['dry_run'] === true && $dryRun['mutations_performed'] === false, '真实 PDO Dry Run 错误地报告外部写入。');
        integrationAssert($dryRun['configuration']['valid'] === true, '真实 PDO Dry Run 配置校验失败。');
        integrationAssert($dryRunBefore === [
            (int) $pdo->query('SELECT COUNT(*) FROM instances')->fetchColumn(),
            (int) $pdo->query('SELECT COUNT(*) FROM domain_reservations')->fetchColumn(),
            (int) $pdo->query('SELECT COUNT(*) FROM deployment_jobs')->fetchColumn(),
            (int) $pdo->query('SELECT COUNT(*) FROM job_steps')->fetchColumn(),
        ], '真实 PDO Dry Run 写入了实例、预留或部署任务。');

        $stage = 'baota-singleton-and-secret';
        $baotaService = new BaotaConfigService($repository, $cipher, new AuthorizationService());
        $baotaSecret = 'mysql-baota-key-' . bin2hex(random_bytes(8));
        $managedBaota = $baotaService->save($super, [
            'base_url' => 'https://panel.integration.invalid:8888', 'api_key' => $baotaSecret,
        ], 'mysql-baota-create');
        $storedBaota = $repository->findBaotaConfig();
        integrationAssert(is_array($storedBaota), '宝塔单例配置未通过真实 PDO 入库。');
        integrationAssert($baotaService->decryptApiKey($storedBaota) === $baotaSecret, '宝塔 API Key AEAD 回读失败。');
        integrationAssert(!str_contains(json_encode($managedBaota, JSON_THROW_ON_ERROR), $baotaSecret), '宝塔 API Key 泄露到安全响应。');
        $disabledBaota = $baotaService->save($super, [
            'admin_state' => 'disabled', 'expected_version' => 1,
        ], 'mysql-baota-disable');
        $rotatedBaota = $baotaService->save($super, [
            'api_key' => $baotaSecret . '-rotated', 'expected_version' => $disabledBaota['version'],
        ], 'mysql-baota-rotate');
        integrationAssert(
            $rotatedBaota['admin_state'] === 'disabled' && $rotatedBaota['verification_status'] === 'unverified',
            '宝塔密钥轮换错误地重新启用了已停用连接。',
        );
        $insertBaota = $pdo->prepare(
            "INSERT INTO baota_config(id,singleton_key,base_url,api_key_ciphertext,secret_key_version,status,"
            . "created_by_user_id,updated_by_user_id,created_at,updated_at) VALUES (?,1,?,?,?,?,?,?,?,?)",
        );
        expectPdoConstraint(
            static fn () => $insertBaota->execute([
                $ids->generate(), 'https://127.0.0.1:2', random_bytes(32), 'integration-only', 'unverified',
                $superId, $superId, $now, $now,
            ]),
            '数据库允许第二条宝塔配置。',
        );
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_string($integrationStorageRoot)) {
            removeIntegrationTree($integrationStorageRoot);
        }
    }
    $stage = 'rollback-and-cleanup-verification';
    foreach ($businessTables as $table) {
        integrationAssert(
            (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() === 0,
            "测试结束后业务表 {$table} 仍有集成测试数据。",
        );
    }
    $migrationVersions = $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
    integrationAssert($migrationVersions === [
        '001_v1_control_plane',
        '002_fix_active_key_null_constraints',
        '003_split_cloudflare_admin_verification_state',
        '004_bind_cloudflare_zone_verification_context',
        '005_baota_readonly_precheck',
    ], '测试结束后正式迁移账本不完整或含有临时迁移记录。');
    fwrite(STDOUT, 'PASS: Database server ' . $version . '，账号 ' . $currentUser
        . "；22 表、002/003/004/005、凭据上下文、多 Zone 失效、部署预演零写入、宝塔密文轮换、重复/失败迁移恢复、"
        . "FK/CHECK/UNIQUE、PDO Session、负责人事务、单宝塔约束及测试数据清理均通过。\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . ($connectionSummary === null ? '' : $connectionSummary . '；')
        . 'stage=' . $stage . '；' . $exception::class . '；' . $exception->getMessage()
        . '；at=' . basename($exception->getFile()) . ':' . $exception->getLine() . PHP_EOL);
    exit(1);
}
