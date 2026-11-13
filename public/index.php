<?php

declare(strict_types=1);

use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\SessionService;
use LandingOps\ControlPlane\Database\DatabaseConfig;
use LandingOps\ControlPlane\Database\PdoFactory;
use LandingOps\ControlPlane\Http\ControlPlaneKernel;
use LandingOps\ControlPlane\Http\Request;
use LandingOps\ControlPlane\Http\RequestId;
use LandingOps\ControlPlane\Repository\PdoControlPlaneRepository;
use LandingOps\ControlPlane\Service\AccountService;
use LandingOps\ControlPlane\Service\OwnershipTransferService;
use LandingOps\ControlPlane\Service\ResourceAccessService;

$root = dirname(__DIR__);
require is_file($root . '/vendor/autoload.php') ? $root . '/vendor/autoload.php' : $root . '/src/autoload.php';

/** @param array<string,mixed> $details */
function emitApiError(int $status, string $code, string $message, string $requestId, array $details = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Request-Id: ' . $requestId);
    echo json_encode([
        'error' => [
            'code' => $code,
            'message' => $message,
            'details' => $details,
            'retryable' => false,
        ],
        'request_id' => $requestId,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$headers = is_array($headers) ? array_map('strval', $headers) : [];
$requestId = (new RequestId())->resolve($headers['X-Request-Id'] ?? $headers['x-request-id'] ?? '');
$headers['X-Request-Id'] = $requestId;
$raw = file_get_contents('php://input');
$body = [];
if (is_string($raw) && $raw !== '') {
    if (strlen($raw) > 1024 * 1024) {
        emitApiError(413, 'request_too_large', '请求体超过 1 MiB 限制。', $requestId);
    }
    $contentType = '';
    foreach ($headers as $name => $value) {
        if (strcasecmp($name, 'Content-Type') === 0) {
            $contentType = $value;
            break;
        }
    }
    if (!str_starts_with(strtolower($contentType), 'application/json')) {
        emitApiError(415, 'content_type_invalid', '请求体必须使用 application/json。', $requestId);
    }
    try {
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        emitApiError(400, 'json_invalid', 'JSON 请求体无效。', $requestId);
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        emitApiError(400, 'json_object_required', 'JSON 请求体必须是对象。', $requestId);
    }
    $body = $decoded;
}

try {
    $pdo = (new PdoFactory())->connect(DatabaseConfig::fromEnvironment());
    $repository = new PdoControlPlaneRepository($pdo);
    $authorization = new AuthorizationService();
    $kernel = new ControlPlaneKernel(
        new SessionService($repository),
        new AccountService($repository, $authorization),
        new ResourceAccessService($repository, $authorization),
        new OwnershipTransferService($repository, $authorization),
    );

    $response = $kernel->handle(new Request(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
        $headers,
        array_map('strval', $_COOKIE),
        $body,
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ));
    http_response_code($response->status);
    foreach ($response->headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($response->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable) {
    emitApiError(500, 'bootstrap_error', '控制面初始化失败。', $requestId);
}
