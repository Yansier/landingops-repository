<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Service;

use LandingOps\ControlPlane\Audit\AuditLogger;
use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\Principal;
use LandingOps\ControlPlane\Cloudflare\CloudflareReadOnlyClient;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Repository\ControlPlaneRepository;
use LandingOps\ControlPlane\Security\AeadSecretCipher;
use LandingOps\ControlPlane\Support\IdGenerator;
use LandingOps\ControlPlane\Support\UtcClock;
use Throwable;

final class CloudflareVerificationService
{
    private const MIN_INTERVAL_SECONDS = 10;
    private readonly AuditLogger $audit;

    public function __construct(
        private readonly ControlPlaneRepository $repository,
        private readonly ?AeadSecretCipher $cipher,
        private readonly CloudflareReadOnlyClient $client = new CloudflareReadOnlyClient(),
        private readonly AuthorizationService $authorization = new AuthorizationService(),
        private readonly UtcClock $clock = new UtcClock(),
        ?AuditLogger $audit = null,
    ) {
        $this->audit = $audit ?? new AuditLogger($repository, new IdGenerator(), $clock);
    }

    /** @return array<string,mixed> */
    public function verify(Principal $actor, string $connectionId, string $zoneId, int $expectedConnectionVersion, int $expectedZoneVersion, string $requestId): array
    {
        $this->authorization->assertSuperAdmin($actor);
        $connection = $this->repository->findCloudflareConnection($connectionId);
        $zone = $this->repository->findCloudflareZone($zoneId);
        if ($connection === null) {
            throw new ApiException(404, 'cloudflare_connection_not_found', 'Cloudflare 连接不存在。');
        }
        if ($zone === null || ($zone['connection_id'] ?? null) !== $connectionId) {
            throw new ApiException(404, 'cloudflare_zone_not_available', 'Zone 不存在或不属于该连接。');
        }
        if ((int) ($connection['version'] ?? 0) !== $expectedConnectionVersion
            || (int) ($zone['version'] ?? 0) !== $expectedZoneVersion) {
            throw new ApiException(409, 'cloudflare_resource_version_conflict', '连接或 Zone 已更新，请刷新后重新验证。');
        }
        $lastAttempt = is_string($connection['last_verification_attempt_at'] ?? null)
            ? strtotime((string) $connection['last_verification_attempt_at'] . ' UTC') : false;
        if (is_int($lastAttempt) && time() - $lastAttempt < self::MIN_INTERVAL_SECONDS) {
            throw new ApiException(429, 'cloudflare_verification_too_frequent', '同一连接的验证间隔不得少于 10 秒。', [], true);
        }
        $now = $this->clock->sql();
        $pending = self::json(['status' => 'pending', 'attempted_at' => $now]);
        if (!$this->repository->updateCloudflareConnection($connectionId, [
            'last_verification_attempt_at' => $now,
            'last_verification_result_json' => $pending,
            'updated_at' => $now,
        ], $expectedConnectionVersion)) {
            throw new ApiException(409, 'cloudflare_connection_version_conflict', '连接已被其他管理员更新，请刷新后重新验证。');
        }
        $claimedVersion = $expectedConnectionVersion + 1;

        try {
            $token = $this->decrypt($connection);
            $tokenResult = $this->client->verifyToken($token);
            $zoneResult = $this->client->zone($token, (string) $zone['external_zone_id']);
            if (!hash_equals((string) $zone['external_zone_id'], $zoneResult['id'])
                || !hash_equals((string) $zone['normalized_zone_name'], $zoneResult['name'])) {
                throw new ApiException(422, 'cloudflare_zone_mismatch', 'Cloudflare 返回的 Zone 与登记资源不匹配。');
            }
            if (is_string($connection['account_external_id'] ?? null)
                && $connection['account_external_id'] !== ''
                && $zoneResult['account_id'] !== $connection['account_external_id']) {
                throw new ApiException(422, 'cloudflare_account_mismatch', 'Cloudflare Zone 不属于登记的账号标识。');
            }
            // 对登记 Zone 发起一次精确名称的 DNS 列表请求，实际证明 Token 具有 DNS Read，结果不持久化。
            $this->client->dnsRecords($token, (string) $zone['external_zone_id'], (string) $zone['normalized_zone_name']);
            $result = [
                'status' => 'success', 'verified_at' => $now,
                'token_status' => $tokenResult['status'], 'token_expires_on' => $tokenResult['expires_on'],
                'zone' => ['id' => $zoneResult['id'], 'name' => $zoneResult['name'], 'status' => $zoneResult['status']],
                'dns_read' => true,
            ];
            $this->repository->transaction(function () use (
                $connectionId, $zoneId, $connection, $zone, $claimedVersion, $expectedZoneVersion,
                $zoneResult, $result, $now, $actor, $requestId,
            ): void {
                $connectionLegacy = ($connection['admin_state'] ?? null) === 'disabled' ? 'disabled' : 'active';
                if (!$this->repository->updateCloudflareConnection($connectionId, [
                    'verification_status' => 'valid', 'status' => $connectionLegacy,
                    'last_verified_at' => $now, 'last_verification_result_json' => self::json($result), 'updated_at' => $now,
                ], $claimedVersion)) {
                    throw new ApiException(409, 'cloudflare_connection_version_conflict', '连接在验证期间被更新，验证结果未提交。');
                }
                $zoneLegacy = ($zone['admin_state'] ?? null) === 'disabled'
                    ? 'disabled' : ($zoneResult['status'] === 'active' ? 'active' : 'unverified');
                if (!$this->repository->updateCloudflareZone($zoneId, [
                    'verification_status' => 'valid', 'cloudflare_status' => $zoneResult['status'], 'status' => $zoneLegacy,
                    'last_synced_at' => $now, 'last_verification_result_json' => self::json($result), 'updated_at' => $now,
                ], $expectedZoneVersion)) {
                    throw new ApiException(409, 'cloudflare_zone_version_conflict', 'Zone 在验证期间被更新，验证结果未提交。');
                }
                $this->audit->record($actor, 'cloudflare.connection_verify', 'cloudflare_connection', $connectionId, $requestId, [
                    'zone_id' => $zoneId, 'result' => 'success', 'zone_status' => $zoneResult['status'],
                ]);
            });
            return $result;
        } catch (Throwable $exception) {
            $this->recordFailure($connectionId, $claimedVersion, $connection, $exception, $now, $actor, $requestId);
            throw $exception;
        }
    }

    /** @param array<string,mixed> $connection */
    private function decrypt(array $connection): string
    {
        if ($this->cipher === null) {
            throw new ApiException(503, 'secret_key_unavailable', '控制面主密钥尚未配置，不能验证 Cloudflare Token。');
        }
        try {
            return $this->cipher->decrypt(
                (string) $connection['token_ciphertext'],
                (string) $connection['secret_key_version'],
                'landingops:cloudflare-token:' . $connection['id'],
            );
        } catch (Throwable) {
            throw new ApiException(503, 'cloudflare_token_decrypt_failed', 'Cloudflare Token 无法安全解密。');
        }
    }

    /** @param array<string,mixed> $connection */
    private function recordFailure(string $connectionId, int $expectedVersion, array $connection, Throwable $exception, string $now, Principal $actor, string $requestId): void
    {
        $code = $exception instanceof ApiException ? $exception->errorCode : 'cloudflare_verification_failed';
        $persistentInvalid = in_array($code, [
            'cloudflare_token_invalid', 'cloudflare_token_inactive', 'cloudflare_permission_denied',
            'cloudflare_zone_mismatch', 'cloudflare_account_mismatch',
        ], true);
        $changes = [
            'last_verification_result_json' => self::json(['status' => 'failed', 'attempted_at' => $now, 'code' => $code]),
            'updated_at' => $now,
        ];
        if ($persistentInvalid) {
            $changes['verification_status'] = 'invalid';
            $changes['status'] = ($connection['admin_state'] ?? null) === 'disabled' ? 'disabled' : 'unverified';
        }
        if ($this->repository->updateCloudflareConnection($connectionId, $changes, $expectedVersion)) {
            $this->audit->record($actor, 'cloudflare.connection_verify', 'cloudflare_connection', $connectionId, $requestId, [
                'result' => 'failed', 'code' => $code,
            ]);
        }
    }

    /** @param array<string,mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
