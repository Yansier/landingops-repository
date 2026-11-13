<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Service;

use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\Principal;
use LandingOps\ControlPlane\Cloudflare\CloudflareReadOnlyClient;
use LandingOps\ControlPlane\Domain\DomainName;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Repository\ControlPlaneRepository;
use LandingOps\ControlPlane\Security\AeadSecretCipher;
use Throwable;

final class CloudflareDnsPrecheckService
{
    public function __construct(
        private readonly ControlPlaneRepository $repository,
        private readonly ?AeadSecretCipher $cipher,
        private readonly CloudflareReadOnlyClient $client = new CloudflareReadOnlyClient(),
        private readonly AuthorizationService $authorization = new AuthorizationService(),
        private readonly ?string $confirmedPublicIpv4 = null,
    ) {
        if ($confirmedPublicIpv4 !== null && filter_var($confirmedPublicIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \InvalidArgumentException('管理员确认的公网 IPv4 配置无效。');
        }
    }

    public static function confirmedIpv4FromEnvironment(): ?string
    {
        $value = getenv('LANDINGOPS_CONFIRMED_PUBLIC_IPV4');
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return array<string,mixed> */
    public function check(Principal $actor, string $primaryDomainId, string $candidateHostname): array
    {
        $this->authorization->assertSuperAdmin($actor);
        $domain = $this->repository->findPrimaryDomain($primaryDomainId);
        if ($domain === null) {
            throw new ApiException(404, 'primary_domain_not_found', '主域名不存在。');
        }
        $hostname = DomainName::normalize($candidateHostname, 'hostname');
        if (!DomainName::belongsToZone($hostname, (string) $domain['normalized_domain_name'])) {
            throw new ApiException(422, 'dns_hostname_out_of_scope', '候选域名不在所选主域名范围内。');
        }
        if (($domain['status'] ?? null) !== 'active'
            || ($domain['zone_admin_state'] ?? null) !== 'enabled'
            || ($domain['zone_verification_status'] ?? null) !== 'valid'
            || ($domain['zone_verified_credential_context_version'] ?? null) !== ($domain['connection_credential_context_version'] ?? null)
            || ($domain['zone_cloudflare_status'] ?? null) !== 'active'
            || ($domain['connection_admin_state'] ?? null) !== 'enabled'
            || ($domain['connection_verification_status'] ?? null) !== 'valid') {
            throw new ApiException(409, 'dns_precheck_resource_not_ready', '连接、Zone 或主域名尚未验证或已停用。');
        }
        $connection = $this->repository->findCloudflareConnection((string) $domain['connection_id']);
        if ($connection === null || $this->cipher === null) {
            throw new ApiException(503, 'secret_key_unavailable', '控制面主密钥尚未配置。');
        }
        try {
            $token = $this->cipher->decrypt(
                (string) $connection['token_ciphertext'],
                (string) $connection['secret_key_version'],
                'landingops:cloudflare-token:' . $connection['id'],
            );
        } catch (Throwable) {
            throw new ApiException(503, 'cloudflare_token_decrypt_failed', 'Cloudflare Token 无法安全解密。');
        }
        $zone = $this->repository->findCloudflareZone((string) $domain['zone_id']);
        if ($zone === null) {
            throw new ApiException(409, 'dns_precheck_resource_not_ready', '关联 Zone 不存在。');
        }
        $records = $this->client->dnsRecords($token, (string) $zone['external_zone_id'], $hostname);
        return $this->classify($hostname, $records);
    }

    /** @param list<array{id:string,type:string,name:string,content:string,proxied:?bool,ttl:?int}> $records @return array<string,mixed> */
    private function classify(string $hostname, array $records): array
    {
        $limitations = [
            '只查询精确同名记录；没有检查通配符记录。',
            '没有检查父级或子级 NS 委派、CNAME 链最终目标及其他权威 DNS 风险。',
            '正式 DNS 写入前必须重新检查通配符、委派和当时的完整记录集。',
        ];
        if ($records === []) {
            return [
                'hostname' => $hostname, 'classification' => 'clear', 'records' => [],
                'confirmed_public_ipv4' => $this->confirmedPublicIpv4,
                'issues' => [], 'limitations' => $limitations,
                'message' => '未发现精确同名 DNS 记录；未全面排除通配符、委派或其他 DNS 风险，本检查未创建任何记录。',
            ];
        }
        $issues = [];
        $uncertain = false;
        if ($this->confirmedPublicIpv4 === null) {
            $issues[] = ['code' => 'confirmed_ipv4_missing', 'record_id' => null, 'message' => '尚未配置管理员确认的服务器公网 IPv4。'];
            $uncertain = true;
        }
        foreach ($records as $record) {
            if ($record['type'] !== 'A') {
                $issues[] = ['code' => 'record_type_mismatch', 'record_id' => $record['id'], 'message' => '存在非 A 类型的精确同名记录。'];
            } elseif ($this->confirmedPublicIpv4 !== null && !hash_equals($this->confirmedPublicIpv4, $record['content'])) {
                $issues[] = ['code' => 'record_content_mismatch', 'record_id' => $record['id'], 'message' => 'A 记录地址与管理员确认的公网 IPv4 不一致。'];
            }
            if ($record['proxied'] === null) {
                $issues[] = ['code' => 'proxied_metadata_missing', 'record_id' => $record['id'], 'message' => '响应缺少 proxied 元数据，无法安全确认。'];
                $uncertain = true;
            } elseif ($record['proxied'] !== false) {
                $issues[] = ['code' => 'proxied_mismatch', 'record_id' => $record['id'], 'message' => '记录已开启 Cloudflare 代理，不符合 proxied=false。'];
            }
            if ($record['ttl'] === null) {
                $issues[] = ['code' => 'ttl_metadata_missing', 'record_id' => $record['id'], 'message' => '响应缺少 TTL 元数据，无法安全确认。'];
                $uncertain = true;
            } elseif ($record['ttl'] !== 1) {
                $issues[] = ['code' => 'ttl_mismatch', 'record_id' => $record['id'], 'message' => '记录 TTL 不是 Cloudflare Auto（1）。'];
            }
        }
        $hasConflict = count(array_filter($issues, static fn (array $issue): bool => !in_array(
            $issue['code'],
            ['confirmed_ipv4_missing', 'proxied_metadata_missing', 'ttl_metadata_missing'],
            true,
        ))) > 0;
        $classification = $hasConflict ? 'conflict' : ($uncertain ? 'needs_configuration' : 'compatible');
        return [
            'hostname' => $hostname, 'classification' => $classification, 'records' => $records,
            'confirmed_public_ipv4' => $this->confirmedPublicIpv4,
            'issues' => $issues, 'limitations' => $limitations,
            'message' => match ($classification) {
                'compatible' => '现有精确同名记录完整符合 A、确认 IPv4、proxied=false、ttl=1；未作修改。',
                'needs_configuration' => '现有记录缺少管理员配置或必要元数据，无法安全确认兼容性。',
                default => '现有记录不完整符合 V1 默认目标策略，需要管理员人工确认。',
            },
        ];
    }
}
