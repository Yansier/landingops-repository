<?php

declare(strict_types=1);

use LandingOps\ControlPlane\Database\MigrationRunner;
use LandingOps\ControlPlane\Database\PdoFactory;
use LandingOps\ControlPlane\Database\DatabaseConfig;
use LandingOps\ControlPlane\Support\IdGenerator;

$root = dirname(__DIR__);
require is_file($root . '/vendor/autoload.php') ? $root . '/vendor/autoload.php' : $root . '/src/autoload.php';

$dsn = getenv('LANDINGOPS_TEST_MYSQL_DSN');
$user = getenv('LANDINGOPS_TEST_MYSQL_USER');
$password = getenv('LANDINGOPS_TEST_MYSQL_PASSWORD');
if (!is_string($dsn) || $dsn === '' || !is_string($user) || !is_string($password)) {
    fwrite(STDOUT, "SKIP: 未设置 LANDINGOPS_TEST_MYSQL_DSN/USER/PASSWORD；未执行真实 MySQL 集成测试。\n");
    exit(0);
}
if (!preg_match('/(?:^|;)dbname=([^;]+)/i', $dsn, $matches) || !str_ends_with(strtolower($matches[1]), '_test')) {
    fwrite(STDERR, "ERROR: 测试数据库名必须以 _test 结尾，防止误连非测试数据库。\n");
    exit(2);
}

try {
    $pdo = (new PdoFactory())->connect(new DatabaseConfig($dsn, $user, $password));
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    if (version_compare(preg_replace('/[^0-9.].*$/', '', $version) ?: '0', '8.0.0', '<')) {
        throw new RuntimeException('MySQL 版本低于 8.0：' . $version);
    }
    $runner = new MigrationRunner();
    $runner->migrate($pdo, $root . '/database/migrations');
    $second = $runner->migrate($pdo, $root . '/database/migrations');
    if ($second !== []) {
        throw new RuntimeException('重复迁移不应再次应用版本。');
    }

    $ids = new IdGenerator();
    $now = gmdate('Y-m-d H:i:s') . '.000000';
    $pdo->beginTransaction();
    try {
        $actorId = $ids->generate();
        $email = strtolower($actorId) . '@integration.invalid';
        $insertUser = $pdo->prepare(
            'INSERT INTO users(id, team_id, email, normalized_email, display_name, password_hash, role, status, '
            . 'version, password_changed_at, created_at, updated_at) VALUES (?,NULL,?,?,?,?,?,?,1,?,?,?)',
        );
        $insertUser->execute([
            $actorId, $email, $email, 'Integration', password_hash('not-a-real-credential', PASSWORD_DEFAULT),
            'super_admin', 'active', $now, $now, $now,
        ]);
        $duplicateEmailRejected = false;
        try {
            $insertUser->execute([
                $ids->generate(), strtoupper($email), $email, 'Duplicate', password_hash('not-a-real-credential', PASSWORD_DEFAULT),
                'super_admin', 'active', $now, $now, $now,
            ]);
        } catch (PDOException) {
            $duplicateEmailRejected = true;
        }
        if (!$duplicateEmailRejected) {
            throw new RuntimeException('数据库未拒绝重复 normalized_email。');
        }
        $insertBaota = $pdo->prepare(
            'INSERT INTO baota_config(id, singleton_key, base_url, api_key_ciphertext, secret_key_version, status, '
            . 'created_by_user_id, updated_by_user_id, created_at, updated_at) VALUES (?,1,?,?,?,?,?,?,?,?)',
        );
        $insertBaota->execute([
            $ids->generate(), 'https://127.0.0.1:1', random_bytes(32), 'integration-only', 'unverified',
            $actorId, $actorId, $now, $now,
        ]);
        $duplicateRejected = false;
        try {
            $insertBaota->execute([
                $ids->generate(), 'https://127.0.0.1:2', random_bytes(32), 'integration-only', 'unverified',
                $actorId, $actorId, $now, $now,
            ]);
        } catch (PDOException) {
            $duplicateRejected = true;
        }
        if (!$duplicateRejected) {
            throw new RuntimeException('数据库允许创建第二条宝塔配置。');
        }
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    fwrite(STDOUT, 'PASS: MySQL ' . $version . " 迁移重复执行、用户唯一索引与单宝塔约束。\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
