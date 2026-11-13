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
use LandingOps\ControlPlane\Service\OwnershipTransferService;
use LandingOps\ControlPlane\Service\ResourceAccessService;
use LandingOps\ControlPlane\Service\TemplateGrantService;
use LandingOps\ControlPlane\Support\IdGenerator;

$root = dirname(__DIR__);
require is_file($root . '/vendor/autoload.php') ? $root . '/vendor/autoload.php' : $root . '/src/autoload.php';

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
    $numericVersion = preg_replace('/[^0-9.].*$/', '', $version) ?: '0';
    $isMariaDb = stripos($version, 'mariadb') !== false;
    $minimumVersion = $isMariaDb ? '10.6.0' : '8.0.0';
    integrationAssert(
        version_compare($numericVersion, $minimumVersion, '>='),
        ($isMariaDb ? 'MariaDB' : 'MySQL') . ' 版本低于支持下限 ' . $minimumVersion . '：' . $version,
    );
    $runner = new MigrationRunner();
    $runner->migrate($pdo, $root . '/database/migrations');
    integrationAssert($runner->migrate($pdo, $root . '/database/migrations') === [], '第二次迁移不应应用版本。');

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

        $insertBaota = $pdo->prepare(
            "INSERT INTO baota_config(id,singleton_key,base_url,api_key_ciphertext,secret_key_version,status,"
            . "created_by_user_id,updated_by_user_id,created_at,updated_at) VALUES (?,1,?,?,?,?,?,?,?,?)",
        );
        $insertBaota->execute([
            $ids->generate(), 'https://127.0.0.1:1', random_bytes(32), 'integration-only', 'unverified',
            $superId, $superId, $now, $now,
        ]);
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
    }
    fwrite(STDOUT, 'PASS: Database server ' . $version . '，账号 ' . $currentUser
        . "；22 表、重复/失败迁移恢复、FK/CHECK/UNIQUE、PDO Session、负责人事务及单宝塔约束均通过。\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . ($connectionSummary === null ? '' : $connectionSummary . '；')
        . $exception->getMessage() . PHP_EOL);
    exit(1);
}
