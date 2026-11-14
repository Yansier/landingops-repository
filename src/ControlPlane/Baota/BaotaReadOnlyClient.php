<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Baota;

use Closure;
use JsonException;
use LandingOps\ControlPlane\Exception\ApiException;

final class BaotaReadOnlyClient implements BaotaReadOnlyGateway
{
    public const SITE_LIMIT = 200;
    private const PAGE_SIZE = 100;
    private const PAGE_REQUEST_BUDGET = 3;
    /** @var Closure():int */
    private readonly Closure $time;
    /** @var Closure(int):void */
    private readonly Closure $sleeper;

    public function __construct(
        private readonly BaotaHttpTransport $transport = new StreamBaotaHttpTransport(),
        ?Closure $time = null,
        ?Closure $sleeper = null,
    ) {
        $this->time = $time ?? static fn (): int => time();
        $this->sleeper = $sleeper ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    public function systemSummary(string $baseUrl, string $apiKey): array
    {
        $payload = $this->request($baseUrl, $apiKey, '/system', ['action' => 'GetSystemTotal']);
        return [
            'panel_version' => is_string($payload['version'] ?? null) ? $payload['version'] : null,
            'system' => is_string($payload['system'] ?? null) ? $payload['system'] : null,
            'version_status' => is_string($payload['version'] ?? null) ? 'supported' : 'unsupported',
        ];
    }

    public function sites(string $baseUrl, string $apiKey): array
    {
        return $this->siteInventory($baseUrl, $apiKey)['sites'];
    }

    public function siteInventory(string $baseUrl, string $apiKey): array
    {
        $sites = [];
        $pages = 0;
        for ($page = 1; $page <= self::PAGE_REQUEST_BUDGET; $page++) {
            try {
                $payload = $this->request($baseUrl, $apiKey, '/data', [
                    'action' => 'getData', 'table' => 'sites', 'type' => '-1', 'p' => $page,
                    'limit' => self::PAGE_SIZE, 'search' => '',
                ]);
            } catch (ApiException $exception) {
                if ($page === 1) {
                    throw $exception;
                }
                return self::inventory($sites, false, $pages, 'pagination_failed');
            }
            $pages++;
            $rows = $payload['data'] ?? null;
            if (!is_array($rows) || !array_is_list($rows)) {
                if ($page === 1) {
                    throw new ApiException(502, 'baota_response_invalid', '宝塔站点列表响应格式无效。');
                }
                return self::inventory($sites, false, $pages, 'pagination_response_invalid');
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    return self::inventory($sites, false, $pages, 'site_record_invalid');
                }
                if (count($sites) >= self::SITE_LIMIT) {
                    return self::inventory($sites, false, $pages, 'site_limit_reached');
                }
                $id = is_int($row['id'] ?? null) ? $row['id'] : (ctype_digit((string) ($row['id'] ?? '')) ? (int) $row['id'] : null);
                $sites[] = [
                    'external_id' => $id,
                    'name' => is_string($row['name'] ?? null) ? strtolower(rtrim($row['name'], '.')) : null,
                    'path' => is_string($row['path'] ?? null) ? $row['path'] : null,
                    'status' => is_scalar($row['status'] ?? null) ? (string) $row['status'] : null,
                    'metadata_complete' => $id !== null && is_string($row['name'] ?? null) && is_string($row['path'] ?? null),
                ];
            }
            if (count($rows) < self::PAGE_SIZE) {
                return self::inventory($sites, true, $pages, null);
            }
        }
        return self::inventory($sites, false, $pages, 'pagination_budget_exhausted');
    }

    public function siteDomains(string $baseUrl, string $apiKey, int $siteId): array
    {
        if ($siteId < 1) {
            return ['status' => 'unsupported', 'domains' => []];
        }
        $payload = $this->request($baseUrl, $apiKey, '/site', ['action' => 'GetSiteDomains', 'id' => $siteId]);
        $rows = $payload['domains'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            return ['status' => 'unsupported', 'domains' => []];
        }
        $domains = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['name'] ?? null)) {
                $domains[] = strtolower(rtrim($row['name'], '.'));
            }
        }
        return ['status' => 'supported', 'domains' => array_values(array_unique($domains))];
    }

    public function phpVersions(string $baseUrl, string $apiKey): array
    {
        $payload = $this->request($baseUrl, $apiKey, '/site', ['action' => 'GetPHPVersion']);
        if (!array_is_list($payload)) {
            return ['status' => 'unsupported', 'versions' => []];
        }
        $versions = [];
        foreach ($payload as $row) {
            if (is_array($row) && is_string($row['version'] ?? null)) {
                $versions[] = [
                    'version' => $row['version'],
                    'name' => is_string($row['name'] ?? null) ? $row['name'] : null,
                    'running' => is_bool($row['status'] ?? null) ? $row['status'] : null,
                ];
            }
        }
        return ['status' => 'supported', 'versions' => $versions];
    }

    public function sitePhpVersion(string $baseUrl, string $apiKey, string $siteName): array
    {
        if ($siteName === '' || strlen($siteName) > 253) {
            return ['status' => 'unsupported', 'php_version' => null];
        }
        $payload = $this->request($baseUrl, $apiKey, '/site', ['action' => 'GetSitePHPVersion', 'siteName' => $siteName]);
        return [
            'status' => is_string($payload['phpversion'] ?? null) ? 'supported' : 'unsupported',
            'php_version' => is_string($payload['phpversion'] ?? null) ? $payload['phpversion'] : null,
        ];
    }

    public function directoryObservation(string $normalizedPath): array
    {
        return [
            'status' => 'unsupported', 'path' => $normalizedPath, 'entry_count' => null,
            'has_unknown_entries' => null, 'is_symlink' => null,
        ];
    }

    /** @param array<string,string|int> $fields @return array<string,mixed> */
    private function request(string $baseUrl, string $apiKey, string $path, array $fields): array
    {
        if (!in_array($path, ['/system', '/data', '/site'], true)) {
            throw new ApiException(422, 'baota_path_rejected', '宝塔只读接口路径不在白名单中。');
        }
        $allowedActions = [
            '/system' => ['GetSystemTotal'],
            '/data' => ['getData'],
            '/site' => ['GetSiteDomains', 'GetPHPVersion', 'GetSitePHPVersion'],
        ];
        $action = $fields['action'] ?? null;
        if (!is_string($action) || !in_array($action, $allowedActions[$path], true)
            || ($path === '/data' && ($fields['table'] ?? null) !== 'sites')) {
            throw new ApiException(422, 'baota_action_rejected', '宝塔操作不在只读白名单中。');
        }
        BaotaEndpointPolicy::assertAllowed($baseUrl);
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $requestTime = (string) ($this->time)();
            $signed = ['request_time' => $requestTime, 'request_token' => md5($requestTime . md5($apiKey))] + $fields;
            try {
                $response = $this->transport->post(BaotaEndpointPolicy::normalize($baseUrl), $path, $signed, 5.0);
            } catch (BaotaTransportException $exception) {
                if ($attempt === 1 && $exception->reason !== 'path_rejected') {
                    ($this->sleeper)(200000);
                    continue;
                }
                throw new ApiException(504, 'baota_timeout', '宝塔只读请求超时或网络不可用。', [], true);
            }
            if (in_array($response->status, [401, 403], true)) {
                throw new ApiException(422, 'baota_auth_failed', '宝塔 API 鉴权失败或来源 IP 未获授权。');
            }
            $isHtml = str_contains(strtolower((string) $response->contentType), 'text/html')
                || str_starts_with(ltrim($response->body), '<');
            if ($response->status === 404 && $isHtml) {
                throw new ApiException(409, 'baota_route_or_security_entry_not_found', '宝塔返回 HTML 404；官方 API 路由被安全入口或面板访问策略拦截。', [
                    'status' => 404, 'content_type' => $response->contentType,
                ]);
            }
            if ($response->status >= 300 && $response->status < 400) {
                throw new ApiException(409, 'baota_redirect_rejected', '宝塔 API 返回重定向；LandingOps 未跟随该响应。', [
                    'status' => $response->status,
                ]);
            }
            if ($response->status === 429) {
                if ($attempt === 1) {
                    ($this->sleeper)(200000);
                    continue;
                }
                throw new ApiException(429, 'baota_rate_limited', '宝塔 API 正在限流，请稍后重试。', [], true);
            }
            if ($response->status >= 500) {
                if ($attempt === 1) {
                    ($this->sleeper)(200000);
                    continue;
                }
                throw new ApiException(502, 'baota_unavailable', '宝塔 API 暂时不可用。', [], true);
            }
            if ($response->status !== 200 || str_starts_with((string) $response->status, '3')) {
                throw new ApiException(502, 'baota_http_error', '宝塔只读请求失败。', ['status' => $response->status]);
            }
            if ($isHtml) {
                throw new ApiException(409, 'baota_security_page_returned', '宝塔 API 返回 HTML 页面而非 JSON；请检查安全入口、白名单或面板防护。', [
                    'status' => $response->status, 'content_type' => $response->contentType,
                ]);
            }
            try {
                $payload = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new ApiException(502, 'baota_response_invalid', '宝塔响应不是有效 JSON 对象或数组。', [
                    'status' => $response->status, 'content_type' => $response->contentType,
                ]);
            }
            if (is_string($payload)) {
                $trimmedPayload = trim($payload);
                if (str_starts_with($trimmedPayload, '{') || str_starts_with($trimmedPayload, '[')) {
                    try {
                        $nestedPayload = json_decode($trimmedPayload, true, 32, JSON_THROW_ON_ERROR);
                        if (is_array($nestedPayload)) {
                            $payload = $nestedPayload;
                        }
                    } catch (JsonException) {
                        // Keep the outer scalar for sanitized rejection classification.
                    }
                }
            }
            if (!is_array($payload)) {
                if ($attempt === 1 && (!is_string($payload) || self::scalarShape($payload) !== 'html_or_script')) {
                    ($this->sleeper)(200000);
                    continue;
                }
                throw self::rejection($payload, $response);
            }
            if (($payload['status'] ?? null) === false) {
                throw self::rejection($payload['msg'] ?? null, $response);
            }
            return $payload;
        }
        throw new ApiException(502, 'baota_unavailable', '宝塔 API 暂时不可用。', [], true);
    }

    private static function rejection(mixed $payload, BaotaHttpResponse $response): ApiException
    {
        $message = is_string($payload) ? $payload : '';
        $normalizedMessage = strtolower(trim($message));
        $shape = self::scalarShape($message);
        $details = [
            'status' => $response->status,
            'content_type' => $response->contentType,
            'payload_type' => get_debug_type($payload),
            'message_length' => strlen($message),
            'message_fingerprint' => $message === '' ? null : substr(hash('sha256', $message), 0, 16),
            'payload_shape' => $shape,
        ];
        if ($shape === 'html_or_script') {
            return new ApiException(409, 'baota_security_page_returned', '宝塔以 JSON 字符串包装了 HTML 或脚本页面；请检查面板访问策略。', $details);
        }
        if ($message !== '' && preg_match('/(?:\bip\b|(?:\d{1,3}\.){3}\d{1,3}|白名单|授权地址|访问地址|来源地址)/iu', $message) === 1) {
            return new ApiException(422, 'baota_source_ip_not_allowed', '宝塔拒绝当前请求来源；请核对 API IP 白名单与实际公网出口。', $details);
        }
        if ($message !== '' && preg_match('/(?:api.?key|密钥|签名|token|request_token|校验失败|验证失败|时间戳|请求时间)/iu', $message) === 1) {
            return new ApiException(422, 'baota_auth_failed', '宝塔拒绝 API 签名或密钥；请核对当前有效 Key 与服务器时间。', $details);
        }
        if ($normalizedMessage !== '' && preg_match('/(?:api.{0,8}(?:关闭|未开启|未启用|disabled)|接口.{0,8}(?:关闭|未开启|未启用))/iu', $message) === 1) {
            return new ApiException(422, 'baota_api_disabled', '宝塔面板拒绝 API 请求；请确认面板 API 接口已显式启用。', $details);
        }
        $exceptionType = self::pythonExceptionType($message);
        if ($exceptionType !== null) {
            $details['exception_type'] = $exceptionType;
            return new ApiException(502, 'baota_panel_internal_error', '宝塔只读接口返回面板内部 Python 异常；响应正文已脱敏。', $details);
        }
        return new ApiException(422, 'baota_api_policy_rejected', '宝塔以标量响应拒绝了只读 API 请求；响应原文已脱敏。', $details);
    }

    private static function scalarShape(string $message): string
    {
        $trimmed = ltrim($message);
        if ($trimmed === '') {
            return 'empty';
        }
        if (preg_match('/^(?:<!doctype\s+html|<html\b|<script\b|<\?xml\b)/iu', $trimmed) === 1
            || preg_match('/(?:<body\b|window\.location|document\.location)/iu', $trimmed) === 1) {
            return 'html_or_script';
        }
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            return 'structured_text_invalid';
        }
        if (preg_match('~^https?://~iu', $trimmed) === 1) {
            return 'url';
        }
        return 'text';
    }

    private static function pythonExceptionType(string $message): ?string
    {
        foreach (['AttributeError', 'TypeError', 'ValueError', 'KeyError', 'IndexError', 'NameError', 'RuntimeError', 'ImportError', 'ModuleNotFoundError', 'SyntaxError', 'JSONDecodeError', 'UnicodeDecodeError', 'UnicodeEncodeError', 'OSError', 'IOError'] as $exceptionClass) {
            if (preg_match('/(?:^|\R)' . preg_quote($exceptionClass, '/') . ':/', $message) === 1) {
                return $exceptionClass;
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $sites @return array{sites:list<array<string,mixed>>,complete:bool,pages:int,reason:?string,site_limit:int} */
    private static function inventory(array $sites, bool $complete, int $pages, ?string $reason): array
    {
        return [
            'sites' => $sites, 'complete' => $complete, 'pages' => $pages,
            'reason' => $reason, 'site_limit' => self::SITE_LIMIT,
        ];
    }
}
