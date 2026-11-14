<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Service;

use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\Principal;
use LandingOps\ControlPlane\Cloudflare\CloudflareReadOnlyClient;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Repository\ControlPlaneRepository;
use LandingOps\ControlPlane\Security\AeadSecretCipher;
use Throwable;

final class CloudflareZoneDiscoveryService
{
    private const DNS_SCREEN_LIMIT = 20;

    public function __construct(
        private readonly ControlPlaneRepository $repository,
        private readonly ?AeadSecretCipher $cipher,
        private readonly CloudflareReadOnlyClient $client = new CloudflareReadOnlyClient(),
        private readonly AuthorizationService $authorization = new AuthorizationService(),
        private readonly ?string $confirmedPublicIpv4 = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function discover(Principal $actor, string $connectionId, int $expectedVersion): array
    {
        $this->authorization->assertSuperAdmin($actor);
        $connection = $this->repository->findCloudflareConnection($connectionId);
        if ($connection === null) {
            throw new ApiException(404, 'cloudflare_connection_not_found', 'Cloudflare 连接不存在。');
        }
        if ((int) ($connection['version'] ?? 0) !== $expectedVersion) {
            throw new ApiException(409, 'cloudflare_connection_version_conflict', '连接版本已变化，请刷新后重试。');
        }
        if (($connection['admin_state'] ?? null) !== 'enabled') {
            throw new ApiException(409, 'cloudflare_connection_disabled', '连接已由管理员停用。');
        }
        if ($this->cipher === null) {
            throw new ApiException(503, 'secret_key_unavailable', '控制面主密钥尚未配置。');
        }
        try {
            $token = $this->cipher->decrypt(
                (string) $connection['token_ciphertext'],
                (string) $connection['secret_key_version'],
                'landingops:cloudflare-token:' . $connection['id'],
            );
            $zones = $this->client->zones($token);
        } catch (ApiException $exception) {
            if ($exception->errorCode === 'cloudflare_permission_denied') {
                return [
                    'classification' => 'manual_registration_required',
                    'reason' => 'zone_list_permission_unavailable',
                    'zones' => [],
                    'manual_registration_available' => true,
                    'write_operations_performed' => false,
                ];
            }
            throw $exception;
        } catch (Throwable) {
            throw new ApiException(503, 'cloudflare_token_decrypt_failed', 'Cloudflare Token 无法安全解密。');
        }

        $current = $this->repository->findCloudflareConnection($connectionId);
        if ($current === null || (int) ($current['version'] ?? 0) !== $expectedVersion) {
            throw new ApiException(409, 'cloudflare_connection_version_conflict', '发现期间连接已变化，结果已丢弃。');
        }
        $items = [];
        foreach ($zones as $index => $zone) {
            $classification = 'needs_attention';
            $wildcardCount = null;
            $expectedAccount = is_string($connection['account_external_id'] ?? null)
                ? $connection['account_external_id'] : null;
            if ($expectedAccount !== null && ($zone['account_id'] === null || !hash_equals($expectedAccount, $zone['account_id']))) {
                $classification = 'needs_attention';
            } elseif ($index >= self::DNS_SCREEN_LIMIT) {
                $classification = 'unsupported';
            } elseif ($zone['status'] !== 'active') {
                $classification = 'needs_attention';
            } elseif ($this->confirmedPublicIpv4 === null) {
                $classification = 'needs_attention';
            } else {
                $records = $this->client->dnsRecords($token, $zone['id'], '*.' . $zone['name']);
                $wildcardCount = count($records);
                $matching = array_filter($records, fn (array $record): bool => $record['type'] === 'A'
                    && hash_equals($this->confirmedPublicIpv4 ?? '', $record['content'])
                    && $record['proxied'] === false && $record['ttl'] === 1);
                $otherA = array_filter($records, fn (array $record): bool => $record['type'] === 'A'
                    && !hash_equals($this->confirmedPublicIpv4 ?? '', $record['content']));
                $classification = count($records) === 1 && count($matching) === 1
                    ? 'matched_server'
                    : ($otherA !== [] ? 'other_server' : 'needs_attention');
            }
            $items[] = $zone + [
                'classification' => $classification,
                'wildcard_record_count' => $wildcardCount,
                'already_registered' => $this->repository->findCloudflareZoneByExternal($connectionId, $zone['id']) !== null,
                'team_grants_created' => 0,
            ];
        }
        return [
            'classification' => count($zones) > self::DNS_SCREEN_LIMIT ? 'incomplete' : 'complete',
            'zones' => $items,
            'screen_limit' => self::DNS_SCREEN_LIMIT,
            'confirmed_public_ipv4_configured' => $this->confirmedPublicIpv4 !== null,
            'observed_at' => gmdate('c'),
            'manual_registration_available' => true,
            'write_operations_performed' => false,
        ];
    }
}
