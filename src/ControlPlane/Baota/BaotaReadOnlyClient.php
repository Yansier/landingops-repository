<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Baota;

use Closure;
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
        $this->sleeper = $sleeper ?? static fn (int $microseconds): int => usleep($microseconds);
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
                    'limit' => self::PAGE_SIZE, 'list' => 'true',
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
            $payload = json_decode($response->body, true, 32);
            if (!is_array($payload)) {
                throw new ApiException(502, 'baota_response_invalid', '宝塔响应不是有效 JSON 对象或数组。');
            }
            if (($payload['status'] ?? null) === false) {
                throw new ApiException(422, 'baota_auth_or_capability_failed', '宝塔拒绝了只读请求；请检查 API Key、IP 白名单和面板版本。');
            }
            return $payload;
        }
        throw new ApiException(502, 'baota_unavailable', '宝塔 API 暂时不可用。', [], true);
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
