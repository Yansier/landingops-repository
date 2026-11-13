<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Cloudflare;

use Closure;
use LandingOps\ControlPlane\Exception\ApiException;

final class CloudflareReadOnlyClient
{
    /** @var Closure(int):void */
    private readonly Closure $sleeper;

    public function __construct(
        private readonly CloudflareHttpTransport $transport = new StreamCloudflareHttpTransport(),
        ?Closure $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static fn (int $microseconds): int => usleep($microseconds);
    }

    /** @return array{id:string,status:string,expires_on:?string} */
    public function verifyToken(string $token): array
    {
        $payload = $this->request('/user/tokens/verify', $token);
        $result = $payload['result'] ?? null;
        if (!is_array($result) || ($result['status'] ?? null) !== 'active' || !is_string($result['id'] ?? null)) {
            throw new ApiException(422, 'cloudflare_token_inactive', 'Cloudflare Token 不是 active 状态。');
        }
        return [
            'id' => $result['id'], 'status' => 'active',
            'expires_on' => is_string($result['expires_on'] ?? null) ? $result['expires_on'] : null,
        ];
    }

    /** @return array{id:string,name:string,status:string,account_id:?string} */
    public function zone(string $token, string $zoneId): array
    {
        self::identifier($zoneId);
        $payload = $this->request('/zones/' . rawurlencode($zoneId), $token);
        $result = $payload['result'] ?? null;
        if (!is_array($result) || !is_string($result['id'] ?? null)
            || !is_string($result['name'] ?? null) || !is_string($result['status'] ?? null)) {
            throw new ApiException(502, 'cloudflare_response_invalid', 'Cloudflare Zone 响应格式无效。');
        }
        return [
            'id' => $result['id'], 'name' => strtolower(rtrim($result['name'], '.')), 'status' => $result['status'],
            'account_id' => is_array($result['account'] ?? null) && is_string($result['account']['id'] ?? null)
                ? $result['account']['id'] : null,
        ];
    }

    /** @return list<array{id:string,type:string,name:string,content:string,proxied:?bool,ttl:?int}> */
    public function dnsRecords(string $token, string $zoneId, string $hostname): array
    {
        self::identifier($zoneId);
        $path = '/zones/' . rawurlencode($zoneId) . '/dns_records?name.exact=' . rawurlencode($hostname) . '&per_page=100';
        $payload = $this->request($path, $token);
        $info = $payload['result_info'] ?? null;
        if (is_array($info) && (int) ($info['total_pages'] ?? 1) > 1) {
            throw new ApiException(502, 'cloudflare_dns_result_incomplete', 'DNS 只读结果超过单页限制，未作安全判断。');
        }
        $result = $payload['result'] ?? null;
        if (!is_array($result) || !array_is_list($result)) {
            throw new ApiException(502, 'cloudflare_response_invalid', 'Cloudflare DNS 响应格式无效。');
        }
        $records = [];
        foreach ($result as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || !is_string($row['type'] ?? null)
                || !is_string($row['name'] ?? null) || !is_string($row['content'] ?? null)) {
                throw new ApiException(502, 'cloudflare_response_invalid', 'Cloudflare DNS 记录格式无效。');
            }
            $records[] = [
                'id' => $row['id'], 'type' => strtoupper($row['type']),
                'name' => strtolower(rtrim($row['name'], '.')), 'content' => $row['content'],
                'proxied' => is_bool($row['proxied'] ?? null) ? $row['proxied'] : null,
                'ttl' => is_int($row['ttl'] ?? null) ? $row['ttl'] : null,
            ];
        }
        return $records;
    }

    /** @return array<string,mixed> */
    private function request(string $path, string $token): array
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = $this->transport->get($path, $token, 5.0);
            } catch (CloudflareTransportException $exception) {
                if ($attempt === 1 && $exception->reason !== 'path_rejected') {
                    ($this->sleeper)(200000);
                    continue;
                }
                throw new ApiException(504, 'cloudflare_timeout', 'Cloudflare 只读请求超时或网络不可用。', [], true);
            }
            if ($response->status === 401) {
                throw new ApiException(422, 'cloudflare_token_invalid', 'Cloudflare Token 无效或已失效。');
            }
            if ($response->status === 403) {
                throw new ApiException(422, 'cloudflare_permission_denied', 'Cloudflare Token 缺少所选 Zone 的只读权限。');
            }
            if ($response->status === 429) {
                if ($attempt === 1) {
                    ($this->sleeper)(200000);
                    continue;
                }
                throw new ApiException(429, 'cloudflare_rate_limited', 'Cloudflare API 正在限流，请稍后重试。', [], true);
            }
            if ($response->status >= 500) {
                if ($attempt === 1) {
                    ($this->sleeper)(200000);
                    continue;
                }
                throw new ApiException(502, 'cloudflare_unavailable', 'Cloudflare API 暂时不可用。', [], true);
            }
            if ($response->status !== 200) {
                throw new ApiException(502, 'cloudflare_http_error', 'Cloudflare 只读请求失败。', ['status' => $response->status]);
            }
            $payload = json_decode($response->body, true, 32);
            if (!is_array($payload) || ($payload['success'] ?? false) !== true) {
                throw new ApiException(502, 'cloudflare_response_invalid', 'Cloudflare 响应未通过安全校验。');
            }
            return $payload;
        }
        throw new ApiException(502, 'cloudflare_unavailable', 'Cloudflare API 暂时不可用。', [], true);
    }

    private static function identifier(string $value): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $value) !== 1) {
            throw new ApiException(422, 'cloudflare_identifier_invalid', 'Cloudflare 资源标识无效。');
        }
    }
}
