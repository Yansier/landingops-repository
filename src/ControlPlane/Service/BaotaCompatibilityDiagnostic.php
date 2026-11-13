<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Service;

use LandingOps\ControlPlane\Baota\BaotaReadOnlyGateway;
use LandingOps\ControlPlane\Exception\ApiException;
use Throwable;

final class BaotaCompatibilityDiagnostic
{
    private const DETAIL_BUDGET = 20;

    public function __construct(private readonly BaotaReadOnlyGateway $gateway)
    {
    }

    /** @return array<string,mixed> */
    public function run(string $baseUrl, string $apiKey): array
    {
        try {
            $system = $this->gateway->systemSummary($baseUrl, $apiKey);
            $inventory = $this->gateway->siteInventory($baseUrl, $apiKey);
            $php = $this->gateway->phpVersions($baseUrl, $apiKey);
            $detailQueries = 0;
            $detailFailures = 0;
            $missingRoots = 0;
            foreach ($inventory['sites'] as $site) {
                if (!is_string($site['path'] ?? null) || trim($site['path']) === '') {
                    $missingRoots++;
                }
                if (!is_int($site['external_id'] ?? null) || $detailQueries >= self::DETAIL_BUDGET) {
                    continue;
                }
                $detailQueries++;
                try {
                    $domains = $this->gateway->siteDomains($baseUrl, $apiKey, $site['external_id']);
                    if (($domains['status'] ?? null) !== 'supported') {
                        $detailFailures++;
                    }
                } catch (Throwable) {
                    $detailFailures++;
                }
            }
            $detailsComplete = count($inventory['sites']) <= self::DETAIL_BUDGET && $detailFailures === 0;
            $complete = $inventory['complete'] && $detailsComplete && $missingRoots === 0;
            return [
                'classification' => $complete ? 'compatible_read_only' : 'needs_attention',
                'panel_version' => $system['panel_version'] ?? null,
                'system_summary' => [
                    'version_status' => $system['version_status'] ?? 'unsupported',
                    'system' => $system['system'] ?? null,
                ],
                'sites' => [
                    'count' => count($inventory['sites']),
                    'listing_complete' => $inventory['complete'],
                    'pages' => $inventory['pages'],
                    'reason' => $inventory['reason'],
                    'site_limit' => $inventory['site_limit'],
                    'root_fields_missing' => $missingRoots,
                    'detail_query_budget' => self::DETAIL_BUDGET,
                    'detail_queries_used' => $detailQueries,
                    'detail_failures' => $detailFailures,
                    'details_complete' => $detailsComplete,
                ],
                'php' => [
                    'status' => $php['status'] ?? 'unsupported',
                    'versions_count' => is_array($php['versions'] ?? null) ? count($php['versions']) : 0,
                ],
                'write_operations_performed' => false,
                'limitations' => [
                    '未读取站点文件或生产目录内容。',
                    '未调用建站、删除、配置修改或 SSL 接口。',
                    '详情超过预算或响应不完整时必须阻断后续写入。',
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
}
