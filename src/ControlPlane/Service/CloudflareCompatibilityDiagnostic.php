<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Service;

use LandingOps\ControlPlane\Cloudflare\CloudflareReadOnlyClient;
use LandingOps\ControlPlane\Domain\DomainName;
use LandingOps\ControlPlane\Exception\ApiException;

final class CloudflareCompatibilityDiagnostic
{
    public function __construct(private readonly CloudflareReadOnlyClient $client = new CloudflareReadOnlyClient())
    {
    }

    /** @return array<string,mixed> */
    public function run(
        string $token,
        string $zoneId,
        string $expectedZoneName,
        ?string $expectedAccountId,
        string $hostname,
    ): array {
        try {
            $expectedZoneName = DomainName::normalize($expectedZoneName, 'zone_name');
            $hostname = DomainName::normalize($hostname, 'hostname');
            if (!DomainName::belongsToZone($hostname, $expectedZoneName)) {
                throw new ApiException(422, 'cloudflare_hostname_out_of_scope', '诊断主机名不属于指定 Zone。');
            }
            $tokenStatus = $this->client->verifyToken($token);
            $zone = $this->client->zone($token, $zoneId);
            if (!hash_equals($expectedZoneName, $zone['name'])
                || ($expectedAccountId !== null && !hash_equals($expectedAccountId, (string) $zone['account_id']))) {
                throw new ApiException(422, 'cloudflare_zone_identity_mismatch', 'Zone 名称或 Account ID 与明确选择不一致。');
            }
            $exact = $this->client->dnsRecords($token, $zoneId, $hostname);
            $wildcard = $this->client->dnsRecords($token, $zoneId, '*.' . $expectedZoneName);
            $apex = $this->client->dnsRecords($token, $zoneId, $expectedZoneName);
            return [
                'classification' => 'verified_read_only',
                'token' => ['status' => $tokenStatus['status'], 'expires_on' => $tokenStatus['expires_on']],
                'zone' => ['id' => $zone['id'], 'name' => $zone['name'], 'status' => $zone['status'], 'account_matches' => true],
                'dns_read' => [
                    'exact_record_count' => count($exact),
                    'exact_record_types' => self::types($exact),
                    'wildcard_exact_record_count' => count($wildcard),
                    'wildcard_record_types' => self::types($wildcard),
                    'apex_ns_record_count' => count(array_filter($apex, static fn (array $row): bool => $row['type'] === 'NS')),
                    'precheck_scope' => 'exact_name_plus_zone_wildcard_and_apex_ns_only',
                ],
                'write_operations_performed' => false,
                'limitations' => [
                    '只读查询不能全面证明父级或子级委派、通配符继承及 CNAME 最终目标安全。',
                    '正式写入前必须重新获取完整相关记录并执行专门的委派规则检查。',
                ],
            ];
        } catch (ApiException $exception) {
            return [
                'classification' => 'failed',
                'error_code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'write_operations_performed' => false,
            ];
        }
    }

    /** @param list<array{id:string,type:string,name:string,content:string,proxied:?bool,ttl:?int}> $records @return list<string> */
    private static function types(array $records): array
    {
        $types = array_values(array_unique(array_map(static fn (array $row): string => $row['type'], $records)));
        sort($types);
        return $types;
    }
}
