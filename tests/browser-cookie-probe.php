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
        'capabilities' => ['manage_teams' => true, 'manage_users' => true, 'transfer_owner' => true],
        'csrf_token' => 'refreshed-csrf',
    ], 'request_id' => 'cookie-probe'], JSON_THROW_ON_ERROR);
    return;
}

if ($method === 'GET' && in_array($path, ['/api/v1/instances', '/api/v1/teams', '/api/v1/users', '/api/v1/templates'], true)
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
