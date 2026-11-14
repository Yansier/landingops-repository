<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Service;

use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\Principal;
use LandingOps\ControlPlane\Cloudflare\CloudflareReadOnlyClient;
use LandingOps\ControlPlane\Cloudflare\WildcardDnsPolicyEvaluator;
use LandingOps\ControlPlane\Domain\DomainName;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Repository\ControlPlaneRepository;
use LandingOps\ControlPlane\Security\AeadSecretCipher;
use Throwable;

final class CloudflareDnsPrecheckService
{
    private const MAX_ANCESTOR_DEPTH = 10;

    public function __construct(
        private readonly ControlPlaneRepository $repository,
        private readonly ?AeadSecretCipher $cipher,
        private readonly CloudflareReadOnlyClient $client = new CloudflareReadOnlyClient(),
        private readonly AuthorizationService $authorization = new AuthorizationService(),
        private readonly ?string $confirmedPublicIpv4 = null,
        private readonly WildcardDnsPolicyEvaluator $evaluator = new WildcardDnsPolicyEvaluator(),
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
        return $this->checkForDeployment($primaryDomainId, $candidateHostname);
    }

    /** @return array<string,mixed> */
    public function checkForDeployment(string $primaryDomainId, string $candidateHostname): array
    {
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
        $zoneName = strtolower((string) $zone['normalized_zone_name']);
        $zoneExternalId = (string) $zone['external_zone_id'];
        $zoneEvidence = $this->client->zone($token, $zoneExternalId);
        if (!hash_equals($zoneName, $zoneEvidence['name'])) {
            throw new ApiException(409, 'cloudflare_zone_mismatch', 'Cloudflare Zone 名称与登记资源不一致。');
        }
        $ancestors = WildcardDnsPolicyEvaluator::ancestorNames($hostname, $zoneName);
        $wildcardCandidates = WildcardDnsPolicyEvaluator::wildcardCandidates($hostname, $zoneName);
        if (count($ancestors) > self::MAX_ANCESTOR_DEPTH) {
            throw new ApiException(422, 'dns_evidence_budget_exceeded', '候选名称层级超过只读证据预算。');
        }
        $exact = $this->client->dnsRecords($token, $zoneExternalId, $hostname);
        $wildcards = [];
        foreach ($wildcardCandidates as $candidate) {
            $wildcards[$candidate] = $this->client->dnsRecords($token, $zoneExternalId, $candidate);
        }
        $ancestorRecords = [];
        $emptyNonTerminals = [];
        foreach ($ancestors as $name) {
            $ancestorRecords[$name] = $this->client->dnsRecords($token, $zoneExternalId, $name);
            $below = $this->client->dnsRecordsEndingWith($token, $zoneExternalId, $name);
            if ($ancestorRecords[$name] === [] && array_filter(
                $below,
                static fn (array $record): bool => $record['name'] !== $name && str_ends_with($record['name'], '.' . $name),
            ) !== []) {
                $emptyNonTerminals[] = $name;
            }
        }
        $observedAt = gmdate('c');
        $result = $this->evaluator->evaluate(
            $hostname,
            $zoneName,
            $this->confirmedPublicIpv4,
            $exact,
            $wildcards,
            $ancestorRecords,
            $emptyNonTerminals,
            $zoneEvidence['nameserver_mode'],
            true,
            $observedAt,
        );
        $result['resource_identity'] = [
            'connection_id' => $connection['id'],
            'zone_id' => $zone['id'],
            'zone_external_id' => $zoneExternalId,
            'credential_context_version' => $domain['connection_credential_context_version'],
            'zone_verified_credential_context_version' => $domain['zone_verified_credential_context_version'],
        ];
        $result['limitations'] = [
            '只读证据不构成网站所有权或部署许可。',
            'CNAME 最终目标、DNSSEC 与父 Zone 外部委派仍需在正式执行前重新检查。',
        ];
        return $result;
    }

}
