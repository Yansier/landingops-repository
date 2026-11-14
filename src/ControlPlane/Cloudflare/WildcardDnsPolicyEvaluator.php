<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Cloudflare;

/**
 * Pure, fail-closed DNS evidence evaluator. It never performs network or write operations.
 */
final class WildcardDnsPolicyEvaluator
{
    private const MAX_EVIDENCE_AGE_SECONDS = 300;

    /**
     * @param list<array{id:string,type:string,name:string,content:string,proxied:?bool,ttl:?int}> $exact
     * @param list<array{id:string,type:string,name:string,content:string,proxied:?bool,ttl:?int}> $wildcard
     * @param list<array{id:string,type:string,name:string,content:string,proxied:?bool,ttl:?int}> $delegations
     * @return array<string,mixed>
     */
    public function evaluate(
        string $hostname,
        string $zoneName,
        ?string $confirmedIpv4,
        array $exact,
        array $wildcard,
        array $delegations,
        bool $complete,
        string $observedAt,
        ?int $nowTimestamp = null,
    ): array {
        $nowTimestamp ??= time();
        $observed = strtotime($observedAt);
        $fresh = $observed !== false && $observed <= $nowTimestamp + 5
            && ($nowTimestamp - $observed) <= self::MAX_EVIDENCE_AGE_SECONDS;
        $wildcardName = self::applicableWildcard($hostname, $zoneName);
        $issues = [];

        if (!$complete) {
            $issues[] = self::issue('dns_evidence_incomplete', 'DNS 查询未完整结束，不能确认通配符覆盖。');
        }
        if (!$fresh) {
            $issues[] = self::issue('dns_evidence_stale', 'DNS 证据已过期或观察时间无效。');
        }
        if ($confirmedIpv4 === null || filter_var($confirmedIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $issues[] = self::issue('confirmed_ipv4_missing', '尚未配置管理员确认的服务器公网 IPv4。');
        }
        if ($wildcardName === null) {
            $issues[] = self::issue('wildcard_not_applicable', '候选名称没有可安全计算的适用通配符。');
        }
        if ($delegations !== []) {
            $issues[] = self::issue('subdomain_delegation_present', '候选名称路径上存在 NS 委派，不能确认由当前 Zone 通配符覆盖。');
        }
        if ($exact !== []) {
            $issues[] = self::issue('exact_record_overrides_wildcard', '存在精确同名记录，将覆盖通配符行为。');
        }

        $matching = [];
        foreach ($wildcard as $record) {
            if ($wildcardName === null || !hash_equals($wildcardName, strtolower(rtrim($record['name'], '.')))) {
                $issues[] = self::issue('wildcard_name_mismatch', '返回记录不是候选名称实际适用的通配符。', $record['id']);
                continue;
            }
            if ($record['type'] !== 'A') {
                $issues[] = self::issue('wildcard_record_type_conflict', '适用通配符包含非 A 记录。', $record['id']);
                continue;
            }
            if ($confirmedIpv4 === null) {
                continue;
            }
            if (!hash_equals($confirmedIpv4, $record['content'])) {
                $issues[] = self::issue('wildcard_points_to_other_server', '通配符 A 记录与管理员确认的服务器 IPv4 不一致。', $record['id']);
                continue;
            }
            if ($record['proxied'] !== false) {
                $issues[] = self::issue(
                    $record['proxied'] === null ? 'wildcard_proxy_metadata_missing' : 'wildcard_proxy_policy_mismatch',
                    $record['proxied'] === null ? '通配符缺少代理状态元数据。' : '通配符启用了 Cloudflare 代理，尚未通过 V1 HTTPS 策略验收。',
                    $record['id'],
                );
                continue;
            }
            if ($record['ttl'] !== 1) {
                $issues[] = self::issue(
                    $record['ttl'] === null ? 'wildcard_ttl_metadata_missing' : 'wildcard_ttl_policy_mismatch',
                    $record['ttl'] === null ? '通配符缺少 TTL 元数据。' : '通配符 TTL 不是 Cloudflare Auto（1）。',
                    $record['id'],
                );
                continue;
            }
            $matching[] = $record;
        }
        if ($wildcard === []) {
            $issues[] = self::issue('applicable_wildcard_missing', '未发现候选名称实际适用的通配符记录。');
        }

        $reusable = $issues === [] && count($matching) === 1;
        if (!$reusable && count($matching) > 1) {
            $issues[] = self::issue('multiple_matching_wildcards', '存在多个匹配的通配符 A 记录，无法选择唯一证据。');
        }
        $classification = $reusable ? 'wildcard_covered' : self::classification($issues);

        return [
            'hostname' => $hostname,
            'zone_name' => $zoneName,
            'classification' => $classification,
            'dns_strategy' => $reusable ? 'reuse_existing_wildcard' : 'manual_dns_prerequisite',
            'dns_write_required' => false,
            'dns_records_to_create' => 0,
            'dns_records_to_update' => 0,
            'dns_records_to_delete' => 0,
            'applicable_wildcard' => $wildcardName,
            'confirmed_public_ipv4' => $confirmedIpv4,
            'evidence' => [
                'source' => 'cloudflare_api_read_only',
                'observed_at' => $observedAt,
                'expires_at' => $observed === false ? null : gmdate('c', $observed + self::MAX_EVIDENCE_AGE_SECONDS),
                'complete' => $complete,
                'fresh' => $fresh,
                'exact_record_count' => count($exact),
                'wildcard_record_count' => count($wildcard),
                'delegation_record_count' => count($delegations),
            ],
            'records' => ['exact' => $exact, 'wildcard' => $wildcard, 'delegations' => $delegations],
            'issues' => $issues,
            'message' => $reusable
                ? '已确认适用通配符指向管理员确认的服务器，且未发现精确覆盖或委派；无需 DNS 写入。'
                : '通配符复用证据不足或存在冲突；不会创建精确 A 记录，也不会修改现有通配符。',
            'write_operations_performed' => false,
        ];
    }

    public static function applicableWildcard(string $hostname, string $zoneName): ?string
    {
        $hostname = strtolower(rtrim($hostname, '.'));
        $zoneName = strtolower(rtrim($zoneName, '.'));
        if ($hostname === $zoneName || !str_ends_with($hostname, '.' . $zoneName)) {
            return null;
        }
        $labels = explode('.', $hostname);
        if (count($labels) < 3) {
            return '*.' . $zoneName;
        }
        array_shift($labels);
        return '*.' . implode('.', $labels);
    }

    /** @param list<array{code:string,message:string,record_id:?string}> $issues */
    private static function classification(array $issues): string
    {
        $codes = array_column($issues, 'code');
        if (array_intersect($codes, [
            'exact_record_overrides_wildcard', 'subdomain_delegation_present', 'wildcard_record_type_conflict',
        ]) !== []) {
            return 'conflict';
        }
        if (in_array('wildcard_points_to_other_server', $codes, true)) {
            return 'other_server';
        }
        if (array_intersect($codes, ['dns_evidence_incomplete', 'dns_evidence_stale', 'wildcard_not_applicable']) !== []) {
            return 'unsupported';
        }
        return 'needs_attention';
    }

    /** @return array{code:string,message:string,record_id:?string} */
    private static function issue(string $code, string $message, ?string $recordId = null): array
    {
        return ['code' => $code, 'message' => $message, 'record_id' => $recordId];
    }
}
