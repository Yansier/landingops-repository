<?php

declare(strict_types=1);

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$cookie = $_COOKIE['landingops_session'] ?? '';
$user = [
    'id' => '01AAAAAAAAAAAAAAAAAAAAAAAA',
    'team_id' => null,
    'email' => 'probe@localhost.test',
    'display_name' => 'Cookie Probe',
    'role' => 'super_admin',
    'status' => 'active',
];

if ($method === 'POST' && $path === '/api/v1/login') {
    setcookie('landingops_session', 'secure-cookie-probe', [
        'expires' => time() + 300,
        'path' => '/api/v1',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    echo json_encode(['data' => [
        'user' => $user,
        'csrf_token' => 'login-csrf',
        'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 300),
    ], 'request_id' => 'cookie-probe'], JSON_THROW_ON_ERROR);
    return;
}

if ($method === 'GET' && $path === '/api/v1/me' && hash_equals('secure-cookie-probe', $cookie)) {
    echo json_encode(['data' => [
        'user' => $user,
        'capabilities' => [
            'manage_teams' => true, 'manage_users' => true, 'transfer_owner' => true,
            'manage_cloudflare' => true, 'manage_domains' => true, 'manage_baota' => true,
        ],
        'csrf_token' => 'refreshed-csrf',
    ], 'request_id' => 'cookie-probe'], JSON_THROW_ON_ERROR);
    return;
}

if ($method === 'GET' && $path === '/api/v1/settings/baota' && hash_equals('secure-cookie-probe', $cookie)) {
    echo json_encode(['data' => [
        'id' => '01BBBBBBBBBBBBBBBBBBBBBBBB', 'base_url_hint' => 'https://p***.example.test:8888',
        'api_key_hint' => '…demo', 'status' => 'active', 'admin_state' => 'enabled',
        'verification_status' => 'valid', 'panel_version' => '11.7.0',
        'capabilities' => ['site_list' => 'supported', 'directory_metadata' => 'unsupported'],
        'last_verified_at' => '2026-11-13 08:00:00', 'last_verification_attempt_at' => '2026-11-13 08:00:00',
        'last_verification_result' => ['status' => 'valid'], 'version' => 2,
        'created_at' => '2026-11-13 08:00:00', 'updated_at' => '2026-11-13 08:00:00',
    ], 'request_id' => 'cookie-probe'], JSON_THROW_ON_ERROR);
    return;
}

if ($method === 'GET' && $path === '/api/v1/settings/baota/sites' && hash_equals('secure-cookie-probe', $cookie)) {
    echo json_encode(['data' => [[
        'external_id' => 7, 'name' => 'legacy.example.test', 'path' => '/www/wwwroot/legacy',
        'normalized_path' => '/www/wwwroot/legacy', 'status' => '1', 'metadata_complete' => true,
        'domains' => ['legacy.example.test', 'alias.example.test'], 'domain_capability' => 'supported',
        'php_capability' => 'supported', 'php_version' => '81',
        'ownership' => ['classification' => 'external', 'instance_id' => null, 'reasons' => ['没有匹配外部站点 ID 的 LandingOps 实例。']],
    ]], 'page' => ['next_cursor' => null, 'has_more' => false], 'request_id' => 'cookie-probe'], JSON_THROW_ON_ERROR);
    return;
}

if ($method === 'GET' && $path === '/api/v1/settings/baota/isolation-checks' && hash_equals('secure-cookie-probe', $cookie)) {
    echo json_encode(['data' => [
        'checks' => [
            ['key' => 'site_directory_organization', 'status' => 'supported', 'message' => '发现路径可规范化。'],
            ['key' => 'per_site_php_fpm_pool', 'status' => 'unsupported', 'message' => '没有每站独立 FPM pool 的证据。'],
        ],
        'php_deployment' => ['status' => 'disabled', 'message' => '执行隔离证据不足，未来 PHP 部署保持禁用。'],
        'mutations_performed' => false,
    ], 'request_id' => 'cookie-probe'], JSON_THROW_ON_ERROR);
    return;
}

if ($method === 'GET' && $path === '/api/v1/cloudflare-connections' && hash_equals('secure-cookie-probe', $cookie)) {
    echo json_encode(['data' => [[
        'id' => '01CCCCCCCCCCCCCCCCCCCCCCCC', 'label' => '本地只读探针', 'account_external_id' => 'acct_probe',
        'token_hint' => '…test', 'status' => 'active', 'admin_state' => 'enabled', 'verification_status' => 'valid',
        'credential_context_version' => 2, 'last_verified_at' => '2026-11-13 08:00:00',
        'last_verification_attempt_at' => '2026-11-13 08:00:00', 'last_verification_result' => ['status' => 'success'],
        'version' => 7, 'created_at' => '2026-11-13 08:00:00', 'updated_at' => '2026-11-13 08:00:00',
    ]], 'page' => ['next_cursor' => null, 'has_more' => false], 'request_id' => 'cookie-probe'], JSON_THROW_ON_ERROR);
    return;
}

if ($method === 'GET' && $path === '/api/v1/cloudflare-zones' && hash_equals('secure-cookie-probe', $cookie)) {
    $base = [
        'connection_id' => '01CCCCCCCCCCCCCCCCCCCCCCCC', 'connection_label' => '本地只读探针',
        'connection_status' => 'active', 'connection_admin_state' => 'enabled', 'connection_verification_status' => 'valid',
        'connection_credential_context_version' => 2, 'status' => 'active', 'admin_state' => 'enabled',
        'last_verification_result' => null, 'version' => 4, 'last_synced_at' => null,
        'created_at' => '2026-11-13 08:00:00', 'updated_at' => '2026-11-13 08:00:00',
    ];
    echo json_encode(['data' => [
        $base + ['id' => '01ZZZZZZZZZZZZZZZZZZZZZZA', 'external_zone_id' => 'zone_a', 'zone_name' => 'a.example.test', 'normalized_zone_name' => 'a.example.test', 'verification_status' => 'valid', 'verified_credential_context_version' => 2, 'cloudflare_status' => 'active'],
        $base + ['id' => '01ZZZZZZZZZZZZZZZZZZZZZZB', 'external_zone_id' => 'zone_b', 'zone_name' => 'b.example.test', 'normalized_zone_name' => 'b.example.test', 'verification_status' => 'unverified', 'verified_credential_context_version' => null, 'cloudflare_status' => null],
    ], 'page' => ['next_cursor' => null, 'has_more' => false], 'request_id' => 'cookie-probe'], JSON_THROW_ON_ERROR);
    return;
}

if ($method === 'GET' && in_array($path, [
    '/api/v1/instances', '/api/v1/teams', '/api/v1/users', '/api/v1/templates',
    '/api/v1/primary-domains',
], true)
    && hash_equals('secure-cookie-probe', $cookie)
) {
    echo json_encode(['data' => [], 'page' => ['next_cursor' => null, 'has_more' => false], 'request_id' => 'cookie-probe'], JSON_THROW_ON_ERROR);
    return;
}

if ($method === 'POST' && $path === '/api/v1/logout'
    && hash_equals('secure-cookie-probe', $cookie)
    && hash_equals('refreshed-csrf', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')
) {
    setcookie('landingops_session', '', [
        'expires' => 1,
        'path' => '/api/v1',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    echo json_encode(['data' => ['logout' => 'accepted'], 'request_id' => 'cookie-probe'], JSON_THROW_ON_ERROR);
    return;
}

http_response_code(401);
echo json_encode(['error' => ['code' => 'unauthorized', 'message' => 'Cookie 或 CSRF 未通过探针。'], 'request_id' => 'cookie-probe'], JSON_THROW_ON_ERROR);
