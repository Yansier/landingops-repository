<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Cloudflare;

/** Pure, fail-closed evaluation of an already collected Cloudflare DNS evidence graph. */
final class WildcardDnsPolicyEvaluator
{
    private const MAX_EVIDENCE_AGE_SECONDS = 300;

    /**
     * @param list<array{id:string,type:string,name:string,content:string,proxied:?bool,ttl:?int}> $exact
     * @param array<string,list<array{id:string,type:string,name:string,content:string,proxied:?bool,ttl:?int}>> $wildcards
     * @param array<string,list<array{id:string,type:string,name:string,content:string,proxied:?bool,ttl:?int}>> $ancestorRecords
     * @param list<string> $emptyNonTerminals
     * @return array<string,mixed>
     */
    public function evaluate(
        string $hostname,
        string $zoneName,
        ?string $confirmedIpv4,
        array $exact,
        array $wildcards,
        array $ancestorRecords,
        array $emptyNonTerminals,
        string $nameserverMode,
        bool $complete,
        string $observedAt,
        ?int $nowTimestamp = null,
    ): array {
        $hostname = self::normalize($hostname);
        $zoneName = self::normalize($zoneName);
        $nowTimestamp ??= time();
        $observed = strtotime($observedAt);
        $fresh = $observed !== false && $observed <= $nowTimestamp + 5
            && ($nowTimestamp - $observed) <= self::MAX_EVIDENCE_AGE_SECONDS;
        $issues = [];
        $candidates = self::wildcardCandidates($hostname, $zoneName);

        if (!$complete) {
            $issues[] = self::issue('dns_evidence_incomplete', 'DNS 查询未完整结束，不能确认通配符覆盖。');
        }
        if (!$fresh) {
            $issues[] = self::issue('dns_evidence_stale', 'DNS 证据已过期或观察时间无效。');
        }
        if ($confirmedIpv4 === null || filter_var($confirmedIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $issues[] = self::issue('confirmed_ipv4_missing', '尚未配置管理员确认的服务器公网 IPv4。');
        }
        if (!in_array($nameserverMode, ['standard', 'advanced', 'unknown'], true)) {
            $issues[] = self::issue('nameserver_mode_invalid', '名称服务器模式证据无效。');
            $nameserverMode = 'unknown';
        }
        if ($exact !== []) {
            $issues[] = self::issue('exact_record_overrides_wildcard', '存在精确同名记录，将覆盖所有通配符行为。');
        }

        $selectedName = null;
        $selectedRecords = [];
        foreach ($candidates as $candidate) {
            $records = $wildcards[$candidate] ?? [];
            if ($records !== []) {
                $selectedName = $candidate;
                $selectedRecords = $records;
                break;
            }
        }
        if ($selectedName === null) {
            $issues[] = self::issue('applicable_wildcard_missing', '所有相关祖先层级均未发现可适用的通配符记录。');
        }

        $selectedBase = $selectedName === null ? null : substr($selectedName, 2);
        $pathNames = self::ancestorNames($hostname, $zoneName);
        $pathBeforeBase = [];
        foreach ($pathNames as $name) {
            if ($selectedBase !== null && hash_equals($selectedBase, $name)) {
                break;
            }
            $pathBeforeBase[] = $name;
        }
        foreach ($pathBeforeBase as $name) {
            foreach ($ancestorRecords[$name] ?? [] as $record) {
                $code = $record['type'] === 'NS' ? 'subdomain_delegation_present' : 'specific_ancestor_record_present';
                $message = $record['type'] === 'NS'
                    ? '通配符路径上存在子域 NS 委派。'
                    : '通配符路径上的具体 DNS 节点会遮蔽该节点及其下名称。';
                $issues[] = self::issue($code, $message, $record['id'], $name);
            }
        }
        if ($selectedBase !== null && !hash_equals($selectedBase, $zoneName)) {
            foreach ($ancestorRecords[$selectedBase] ?? [] as $record) {
                if ($record['type'] === 'NS') {
                    $issues[] = self::issue('subdomain_delegation_present', '适用通配符基点存在 NS 委派。', $record['id'], $selectedBase);
                }
            }
        }

        $emptyNonTerminals = array_map([self::class, 'normalize'], $emptyNonTerminals);
        $relevantEnts = array_values(array_intersect($pathBeforeBase, $emptyNonTerminals));
        if ($relevantEnts !== []) {
            if ($nameserverMode === 'advanced') {
                $issues[] = self::issue('advanced_nameserver_ent_blocks_wildcard', 'Advanced Nameservers 按 RFC 4592 处理 empty non-terminal，较宽通配符不适用。', null, $relevantEnts[0]);
            } elseif ($nameserverMode === 'unknown') {
                $issues[] = self::issue('nameserver_mode_unknown_for_ent', '检测到 empty non-terminal，但无法确认标准或 Advanced Nameservers 行为。', null, $relevantEnts[0]);
            }
        }

        $matching = [];
        foreach ($selectedRecords as $record) {
            if ($selectedName === null || !hash_equals($selectedName, self::normalize($record['name']))) {
                $issues[] = self::issue('wildcard_name_mismatch', '返回记录不属于最终选择的通配符名称。', $record['id']);
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
        if (count($matching) > 1) {
            $issues[] = self::issue('multiple_matching_wildcards', '最终适用名称存在多个匹配的通配符 A 记录。');
        }

        $reusable = $issues === [] && count($matching) === 1;
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
            'applicable_wildcard' => $selectedName,
            'wildcard_candidates' => $candidates,
            'nameserver_mode' => $nameserverMode,
            'confirmed_public_ipv4' => $confirmedIpv4,
            'evidence' => [
                'source' => 'cloudflare_api_read_only',
                'observed_at' => $observedAt,
                'expires_at' => $observed === false ? null : gmdate('c', $observed + self::MAX_EVIDENCE_AGE_SECONDS),
                'complete' => $complete,
                'fresh' => $fresh,
                'exact_record_count' => count($exact),
                'wildcard_record_count' => array_sum(array_map('count', $wildcards)),
                'ancestor_record_count' => array_sum(array_map('count', $ancestorRecords)),
                'empty_non_terminal_count' => count($emptyNonTerminals),
            ],
            'records' => ['exact' => $exact, 'wildcards' => $wildcards, 'ancestors' => $ancestorRecords],
            'issues' => $issues,
            'message' => $reusable
                ? '已确认最具体可用通配符覆盖候选名称，且目标、遮蔽、委派和名称服务器证据均满足要求；无需 DNS 写入。'
                : '通配符复用证据不足或存在遮蔽/委派冲突；不会创建精确 A 记录，也不会修改现有通配符。',
            'write_operations_performed' => false,
        ];
    }

    /** @return list<string> Most specific first, including the zone-level multi-label wildcard. */
    public static function wildcardCandidates(string $hostname, string $zoneName): array
    {
        return array_map(
            static fn (string $name): string => '*.' . $name,
            self::ancestorNames($hostname, $zoneName, true),
        );
    }

    /** @return list<string> Direct parent first; zone is optional. */
    public static function ancestorNames(string $hostname, string $zoneName, bool $includeZone = false): array
    {
        $hostname = self::normalize($hostname);
        $zoneName = self::normalize($zoneName);
        if ($hostname === $zoneName || !str_ends_with($hostname, '.' . $zoneName)) {
            return [];
        }
        $labels = explode('.', $hostname);
        $zoneLabels = explode('.', $zoneName);
        $names = [];
        while (count($labels) > count($zoneLabels)) {
            array_shift($labels);
            $name = implode('.', $labels);
            if ($name === $zoneName && !$includeZone) {
                break;
            }
            $names[] = $name;
        }
        return $names;
    }

    /** @param list<array{code:string,message:string,record_id:?string,name:?string}> $issues */
    private static function classification(array $issues): string
    {
        $codes = array_column($issues, 'code');
        if (array_intersect($codes, [
            'exact_record_overrides_wildcard', 'subdomain_delegation_present', 'specific_ancestor_record_present',
            'advanced_nameserver_ent_blocks_wildcard', 'wildcard_record_type_conflict',
        ]) !== []) {
            return 'conflict';
        }
        if (in_array('wildcard_points_to_other_server', $codes, true)) {
            return 'other_server';
        }
        if (array_intersect($codes, [
            'dns_evidence_incomplete', 'dns_evidence_stale', 'nameserver_mode_unknown_for_ent', 'nameserver_mode_invalid',
        ]) !== []) {
            return 'unsupported';
        }
        return 'needs_attention';
    }

    /** @return array{code:string,message:string,record_id:?string,name:?string} */
    private static function issue(string $code, string $message, ?string $recordId = null, ?string $name = null): array
    {
        return ['code' => $code, 'message' => $message, 'record_id' => $recordId, 'name' => $name];
    }

    private static function normalize(string $name): string
    {
        return strtolower(rtrim($name, '.'));
    }
}
