<?php

declare(strict_types=1);

use LandingOps\ControlPlane\Database\DatabaseConfig;
use LandingOps\ControlPlane\Database\PdoFactory;

$root = dirname(__DIR__);
require is_file($root . '/vendor/autoload.php') ? $root . '/vendor/autoload.php' : $root . '/src/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$requiredMigrations = [
    '001_v1_control_plane',
    '002_fix_active_key_null_constraints',
    '003_split_cloudflare_admin_verification_state',
    '004_bind_cloudflare_zone_verification_context',
    '005_baota_readonly_precheck',
    '006_baota_inventory_evidence_snapshots',
];
try {
    $pdo = (new PdoFactory())->connect(DatabaseConfig::fromEnvironment());
    $statement = $pdo->query('SELECT version FROM schema_migrations ORDER BY version');
    $migrations = $statement->fetchAll(PDO::FETCH_COLUMN);
    if (array_diff($requiredMigrations, $migrations) !== []) {
        throw new RuntimeException('schema_not_ready');
    }
    $version = is_file($root . '/RELEASE_VERSION') ? trim((string) file_get_contents($root . '/RELEASE_VERSION')) : 'development';
    http_response_code(200);
    echo json_encode([
        'status' => 'ok', 'release' => $version, 'database' => 'ready',
        'schema_migration_count' => count($requiredMigrations),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['status' => 'unavailable'], JSON_THROW_ON_ERROR);
}
