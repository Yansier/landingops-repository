<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Baota;

final class BaotaSiteOwnershipEvaluator
{
    /** @param array<string,mixed> $site @param list<array<string,mixed>> $instances @return array{classification:string,instance_id:?string,reasons:list<string>} */
    public function evaluate(array $site, array $instances): array
    {
        $externalId = $site['external_id'] ?? null;
        $matches = array_values(array_filter($instances, static fn (array $instance): bool =>
            $externalId !== null && (string) ($instance['baota_site_external_id'] ?? '') === (string) $externalId));
        if ($matches === []) {
            return ['classification' => 'external', 'instance_id' => null, 'reasons' => ['没有匹配外部站点 ID 的 LandingOps 实例。']];
        }
        if (count($matches) !== 1) {
            return ['classification' => 'conflict', 'instance_id' => null, 'reasons' => ['外部站点 ID 对应多个实例。']];
        }
        $instance = $matches[0];
        $reasons = [];
        $domains = array_map('strval', is_array($site['domains'] ?? null) ? $site['domains'] : []);
        $fqdn = strtolower(rtrim((string) ($instance['normalized_fqdn'] ?? ''), '.'));
        sort($domains, SORT_STRING);
        if ($fqdn === '' || $domains !== [$fqdn]) {
            $reasons[] = '实例 FQDN 及全部绑定信息未获得严格一致证据。';
        }
        try {
            $observedPath = BaotaPath::normalize((string) ($site['path'] ?? ''));
            $expectedPath = BaotaPath::normalize((string) ($instance['baota_site_root_path'] ?? ''));
            if (!hash_equals($expectedPath, $observedPath)) {
                $reasons[] = '站点根目录与实例证据不匹配。';
            }
        } catch (\Throwable) {
            $reasons[] = '站点根目录无法安全规范化。';
        }
        $expectedMarker = $instance['ownership_marker_hash'] ?? null;
        $observedMarker = $site['ownership_marker_hash'] ?? null;
        if (!is_string($expectedMarker) || !is_string($observedMarker)
            || strlen($expectedMarker) !== 64 || !hash_equals($expectedMarker, $observedMarker)) {
            $reasons[] = '缺少或不匹配 LandingOps 可靠所有权标记。';
        }
        if ($reasons !== []) {
            return ['classification' => 'unverified', 'instance_id' => (string) $instance['id'], 'reasons' => $reasons];
        }
        return ['classification' => 'managed', 'instance_id' => (string) $instance['id'], 'reasons' => []];
    }
}
