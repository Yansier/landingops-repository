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
use LandingOps\ControlPlane\Security\AeadSecretCipher;
use LandingOps\ControlPlane\Cloudflare\CloudflareReadOnlyClient;
use LandingOps\ControlPlane\Baota\BaotaReadOnlyClient;
use LandingOps\ControlPlane\Service\BaotaConfigService;
use LandingOps\ControlPlane\Service\BaotaReadOnlyService;
use LandingOps\ControlPlane\Service\CloudflareConnectionService;
use LandingOps\ControlPlane\Service\CloudflareDnsPrecheckService;
use LandingOps\ControlPlane\Service\CloudflareVerificationService;
use LandingOps\ControlPlane\Service\DomainResourceService;
use LandingOps\ControlPlane\Service\OwnershipTransferService;
use LandingOps\ControlPlane\Service\ResourceAccessService;
use LandingOps\ControlPlane\Service\TemplateGrantService;
use LandingOps\ControlPlane\Service\TemplateManagementService;
use LandingOps\ControlPlane\Template\PrivateTemplateStorage;

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
$contentType = '';
foreach ($headers as $name => $value) {
    if (strcasecmp($name, 'Content-Type') === 0) {
        $contentType = $value;
        break;
    }
}
$multipart = str_starts_with(strtolower($contentType), 'multipart/form-data');
$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($multipart && $contentLength > (30 * 1024 * 1024) + (256 * 1024)) {
    emitApiError(413, 'request_too_large', 'multipart 请求超过模板上传限制。', $requestId);
}

$raw = $multipart ? '' : file_get_contents('php://input');
$body = [];
$files = [];
if ($multipart) {
    foreach ($_POST as $key => $value) {
        if (is_string($key) && is_string($value)) {
            $body[$key] = $value;
        }
    }
    foreach ($_FILES as $key => $file) {
        if (!is_string($key) || !is_array($file)
            || is_array($file['name'] ?? null)
            || is_array($file['tmp_name'] ?? null)
        ) {
            emitApiError(422, 'template_upload_count', '不支持批量或嵌套文件上传。', $requestId);
        }
        $files[$key] = [
            'name' => (string) ($file['name'] ?? ''),
            'tmp_name' => (string) ($file['tmp_name'] ?? ''),
            'size' => (int) ($file['size'] ?? 0),
            'error' => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
        ];
    }
}
if (is_string($raw) && $raw !== '') {
    if (strlen($raw) > 1024 * 1024) {
        emitApiError(413, 'request_too_large', '请求体超过 1 MiB 限制。', $requestId);
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
    $templateGrants = new TemplateGrantService($repository, $authorization);
    $templateManagement = new TemplateManagementService(
        $repository,
        PrivateTemplateStorage::forProject($root),
        $authorization,
        grants: $templateGrants,
    );
    $secretCipher = AeadSecretCipher::fromEnvironment();
    $cloudflareClient = new CloudflareReadOnlyClient();
    $cloudflareConnections = new CloudflareConnectionService(
        $repository,
        $secretCipher,
        $authorization,
    );
    $domains = new DomainResourceService($repository, $authorization);
    $cloudflareVerifications = new CloudflareVerificationService(
        $repository,
        $secretCipher,
        $cloudflareClient,
        $authorization,
    );
    $dnsPrechecks = new CloudflareDnsPrecheckService(
        $repository,
        $secretCipher,
        $cloudflareClient,
        $authorization,
        CloudflareDnsPrecheckService::confirmedIpv4FromEnvironment(),
    );
    $baotaConfigs = new BaotaConfigService($repository, $secretCipher, $authorization);
    $baotaReadOnly = new BaotaReadOnlyService(
        $repository,
        $baotaConfigs,
        new BaotaReadOnlyClient(),
        $authorization,
    );
    $kernel = new ControlPlaneKernel(
        new SessionService($repository),
        new AccountService($repository, $authorization),
        new ResourceAccessService($repository, $authorization),
        new OwnershipTransferService($repository, $authorization),
        templates: $templateManagement,
        templateGrants: $templateGrants,
        cloudflareConnections: $cloudflareConnections,
        domains: $domains,
        cloudflareVerifications: $cloudflareVerifications,
        dnsPrechecks: $dnsPrechecks,
        baotaConfigs: $baotaConfigs,
        baotaReadOnly: $baotaReadOnly,
    );

    $response = $kernel->handle(new Request(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
        $headers,
        array_map('strval', $_COOKIE),
        $body,
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        $files,
    ));
    http_response_code($response->status);
    foreach ($response->headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($response->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable) {
    emitApiError(500, 'bootstrap_error', '控制面初始化失败。', $requestId);
}
