<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Service;

use LandingOps\ControlPlane\Audit\AuditLogger;
use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\Principal;
use LandingOps\ControlPlane\Baota\BaotaPath;
use LandingOps\ControlPlane\Baota\BaotaReadOnlyGateway;
use LandingOps\ControlPlane\Baota\BaotaSiteOwnershipEvaluator;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Repository\ControlPlaneRepository;
use LandingOps\ControlPlane\Support\IdGenerator;
use LandingOps\ControlPlane\Support\UtcClock;

final class BaotaReadOnlyService
{
    private const DETAIL_QUERY_BUDGET = 20;
    private const SNAPSHOT_TTL_SECONDS = 300;
    private readonly AuditLogger $audit;

    public function __construct(
        private readonly ControlPlaneRepository $repository,
        private readonly BaotaConfigService $configs,
        private readonly BaotaReadOnlyGateway $gateway,
        private readonly AuthorizationService $authorization = new AuthorizationService(),
        private readonly BaotaSiteOwnershipEvaluator $ownership = new BaotaSiteOwnershipEvaluator(),
        private readonly IdGenerator $ids = new IdGenerator(),
        private readonly UtcClock $clock = new UtcClock(),
        ?AuditLogger $audit = null,
    ) {
        $this->audit = $audit ?? new AuditLogger($repository, $ids, $clock);
    }

    /** @return array<string,mixed> */
    public function verify(Principal $actor, int $expectedVersion, string $requestId): array
    {
        $this->authorization->assertSuperAdmin($actor);
        $config = $this->configs->requireEnabled($actor);
        if ($expectedVersion < 1 || (int) $config['version'] !== $expectedVersion) {
            throw new ApiException(409, 'baota_config_version_conflict', '宝塔连接版本已变化，请刷新后重新确认。');
        }
        $now = $this->clock->sql();
        try {
            $key = $this->configs->decryptApiKey($config);
            $system = $this->gateway->systemSummary((string) $config['base_url'], $key);
            $inventory = $this->gateway->siteInventory((string) $config['base_url'], $key);
            $php = $this->gateway->phpVersions((string) $config['base_url'], $key);
            $capabilities = [
                'system_summary' => 'supported',
                'panel_version' => $system['version_status'] ?? 'unsupported',
                'site_list' => ($inventory['complete'] ?? false) ? 'supported' : 'incomplete',
                'site_domains' => 'supported_by_official_api_not_sampled',
                'php_versions' => $php['status'] ?? 'unsupported',
                'site_php_version' => 'supported_by_official_api_not_sampled',
                'directory_metadata' => 'unsupported',
                'unix_user_isolation' => 'unsupported',
                'per_site_fpm_pool' => 'unsupported',
            ];
            $result = [
                'status' => 'valid', 'code' => 'readonly_checks_passed',
                'panel_version' => $system['panel_version'] ?? null,
                'site_count' => count($inventory['sites'] ?? []),
                'inventory_complete' => (bool) ($inventory['complete'] ?? false),
                'inventory_reason' => $inventory['reason'] ?? null,
                'checked_at' => $now,
            ];
            $changes = [
                'verification_status' => 'valid', 'status' => 'active', 'last_verified_at' => $now,
                'last_verification_attempt_at' => $now,
                'last_verification_result_json' => self::json($result),
                'panel_version' => $system['panel_version'] ?? null,
                'capabilities_json' => self::json($capabilities),
                'updated_by_user_id' => $actor->id, 'updated_at' => $now,
            ];
            $this->repository->transaction(function () use ($config, $changes, $expectedVersion, $actor, $requestId, $result): void {
                if (!$this->repository->updateBaotaConfig((string) $config['id'], $changes, $expectedVersion)) {
                    throw new ApiException(409, 'baota_config_version_conflict', '验证期间连接已变化，结果未写入。');
                }
                $this->audit->record($actor, 'baota.readonly_verify', 'baota_config', (string) $config['id'], $requestId, [
                    'status' => 'valid', 'panel_version' => $result['panel_version'], 'site_count' => $result['site_count'],
                ]);
            });
            return ['config' => BaotaConfigService::safe($this->repository->findBaotaConfig() ?? []), 'result' => $result];
        } catch (ApiException $exception) {
            $result = ['status' => 'invalid', 'code' => $exception->errorCode, 'checked_at' => $now];
            $this->repository->transaction(function () use ($config, $expectedVersion, $actor, $requestId, $now, $result): void {
                if ($this->repository->updateBaotaConfig((string) $config['id'], [
                    'verification_status' => 'invalid', 'status' => 'unverified', 'last_verified_at' => null,
                    'last_verification_attempt_at' => $now, 'last_verification_result_json' => self::json($result),
                    'panel_version' => null, 'capabilities_json' => null,
                    'updated_by_user_id' => $actor->id, 'updated_at' => $now,
                ], $expectedVersion)) {
                    $this->audit->record($actor, 'baota.readonly_verify_failed', 'baota_config', (string) $config['id'], $requestId, [
                        'code' => $result['code'],
                    ]);
                }
            });
            throw $exception;
        }
    }

    /** @return array{sites:list<array<string,mixed>>,inventory:array<string,mixed>} */
    public function sites(Principal $actor): array
    {
        [$config, $key] = $this->credentials($actor);
        return $this->discoverSites($config, $key);
    }

    /** @return array{sites:list<array<string,mixed>>,inventory:array<string,mixed>,batch:array<string,mixed>} */
    public function siteDetailBatch(
        Principal $actor,
        int $offset,
        int $limit,
        ?string $snapshotId = null,
        ?int $expectedSnapshotVersion = null,
    ): array
    {
        if ($offset < 0 || $limit < 1 || $limit > self::DETAIL_QUERY_BUDGET) {
            throw new ApiException(422, 'baota_detail_batch_invalid', '站点详情批次 offset 或 limit 无效。');
        }
        [$config, $key] = $this->credentials($actor);
        return $this->collectSnapshotBatch($config, $key, $offset, $limit, $snapshotId, $expectedSnapshotVersion);
    }

    /** @return array<string,mixed> */
    public function precheck(Principal $actor, string $hostname): array
    {
        $hostname = self::hostname($hostname);
        [$config, $key] = $this->credentials($actor);
        return $this->precheckWithCredentials($hostname, $config, $key);
    }

    /** @return array<string,mixed> */
    public function precheckForDeployment(string $hostname): array
    {
        $hostname = self::hostname($hostname);
        $config = $this->repository->findBaotaConfig();
        if ($config === null || ($config['admin_state'] ?? null) !== 'enabled'
            || ($config['verification_status'] ?? null) !== 'valid'
        ) {
            return [
                'hostname' => $hostname, 'candidate_path' => BaotaPath::candidateRoot($hostname),
                'classification' => 'needs_attention',
                'issues' => [['code' => 'baota_not_verified', 'message' => '宝塔连接未启用或未通过当前凭据验证。', 'site_id' => null]],
                'directory_observation' => ['status' => 'unsupported'],
                'message' => '无法完成宝塔只读预检查，正式提交前必须重新验证。',
                'mutations_performed' => false,
            ];
        }
        try {
            return $this->precheckWithCredentials($hostname, $config, $this->configs->decryptApiKey($config));
        } catch (ApiException $exception) {
            return [
                'hostname' => $hostname, 'candidate_path' => BaotaPath::candidateRoot($hostname),
                'classification' => 'needs_attention',
                'issues' => [['code' => $exception->errorCode, 'message' => '宝塔只读预检查未能完成。', 'site_id' => null]],
                'directory_observation' => ['status' => 'unsupported'],
                'message' => '外部只读证据不完整，正式提交前必须重新检查。',
                'mutations_performed' => false,
            ];
        }
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function precheckWithCredentials(string $hostname, array $config, string $key): array
    {
        $discovery = $this->snapshotDiscovery($config, $key);
        $sites = $discovery['sites'];
        $candidatePath = BaotaPath::candidateRoot($hostname);
        $issues = [];
        $knownConflict = false;
        $siteMetadataUnsupported = !($discovery['inventory']['complete'] ?? false);
        foreach ($sites as $site) {
            if (($site['metadata_complete'] ?? false) !== true || ($site['domain_capability'] ?? null) !== 'supported') {
                $siteMetadataUnsupported = true;
            }
            if (in_array($hostname, $site['domains'], true)) {
                $knownConflict = true;
                $issues[] = ['code' => 'fqdn_used_by_baota_site', 'message' => '候选 FQDN 已绑定到宝塔站点。', 'site_id' => $site['external_id']];
            }
            if ($candidatePath !== null && is_string($site['normalized_path'] ?? null)
                && hash_equals($candidatePath, $site['normalized_path'])) {
                $knownConflict = true;
                $issues[] = ['code' => 'path_used_by_baota_site', 'message' => '候选根目录已被宝塔站点使用。', 'site_id' => $site['external_id']];
            }
        }
        if ($this->repository->findInstanceByNormalizedFqdn($hostname) !== null) {
            $knownConflict = true;
            $issues[] = ['code' => 'fqdn_used_by_instance', 'message' => '候选 FQDN 已被 LandingOps 实例使用。', 'site_id' => null];
        }
        if ($this->repository->hasActiveDomainReservation($hostname)) {
            $knownConflict = true;
            $issues[] = ['code' => 'fqdn_reserved', 'message' => '候选 FQDN 存在有效预留。', 'site_id' => null];
        }
        if ($candidatePath !== null) {
            foreach ($this->repository->listInstances(null, null) as $instance) {
                if (!is_string($instance['baota_site_root_path'] ?? null)) {
                    continue;
                }
                try {
                    if (hash_equals($candidatePath, BaotaPath::normalize($instance['baota_site_root_path']))) {
                        $knownConflict = true;
                        $issues[] = ['code' => 'path_used_by_instance', 'message' => '候选根目录已由 LandingOps 实例登记。', 'site_id' => null];
                    }
                } catch (ApiException) {
                    $siteMetadataUnsupported = true;
                }
            }
        }
        $observation = $candidatePath === null
            ? ['status' => 'unsupported', 'path' => null, 'entry_count' => null, 'has_unknown_entries' => null, 'is_symlink' => null]
            : $this->gateway->directoryObservation($candidatePath);
        $staticCapabilities = [
            'target_parent_authorized' => ($observation['parent_authorized'] ?? null) === true,
            'nginx_vhost_isolation' => ($observation['nginx_vhost_isolation'] ?? null) === true,
            'atomic_release_supported' => ($observation['atomic_release_supported'] ?? null) === true,
            'static_release_supported' => ($observation['static_release_supported'] ?? null) === true,
        ];
        $staticDeploymentReady = !in_array(false, $staticCapabilities, true);
        if (($observation['is_symlink'] ?? null) === true) {
            $knownConflict = true;
            $issues[] = ['code' => 'candidate_path_symlink', 'message' => '候选目录是符号链接，拒绝继续。', 'site_id' => null];
        }
        if (($observation['has_unknown_entries'] ?? null) === true) {
            $knownConflict = true;
            $issues[] = ['code' => 'candidate_path_unknown_content', 'message' => '候选目录包含未知内容。', 'site_id' => null];
        }
        $classification = $knownConflict ? 'conflict' : ($siteMetadataUnsupported ? 'unsupported' : match ($observation['status'] ?? 'unsupported') {
            'absent' => 'clear',
            'unsupported', 'unknown', 'exists_empty' => 'needs_attention',
            default => 'needs_attention',
        });
        if (!$knownConflict && $siteMetadataUnsupported) {
            $issues[] = ['code' => 'site_metadata_unsupported', 'message' => '面板版本未提供完整站点或绑定字段，无法完成必要检查。', 'site_id' => null];
        }
        if (!$knownConflict && $candidatePath === null) {
            $issues[] = ['code' => 'site_root_not_configured', 'message' => '运行环境未配置候选站点根目录。', 'site_id' => null];
        } elseif (!$knownConflict && ($observation['status'] ?? null) !== 'absent') {
            $issues[] = ['code' => 'filesystem_not_confirmed', 'message' => '只读面板 API 无法确认目录、符号链接和未知文件，必须人工检查。', 'site_id' => null];
        }
        if (!$knownConflict && !$staticDeploymentReady) {
            $issues[] = ['code' => 'static_deployment_capabilities_incomplete', 'message' => '独立父目录、Nginx 边界或原子静态 release 能力尚未全部确认。', 'site_id' => null];
            if ($classification === 'clear') {
                $classification = 'needs_attention';
            }
        }
        return [
            'hostname' => $hostname, 'candidate_path' => $candidatePath, 'classification' => $classification,
            'issues' => $issues, 'directory_observation' => $observation,
            'message' => match ($classification) {
                'clear' => '未发现已知冲突，但这不是写入或建站许可。',
                'conflict' => '发现明确占用或不安全路径，禁止建站。',
                'unsupported' => '目标面板未提供完成预检查所需的只读信息。',
                default => '现有只读证据不足，需要人工检查。',
            },
            'inventory' => $discovery['inventory'],
            'static_deployment_capabilities' => $staticCapabilities,
            'static_deployment_ready' => $staticDeploymentReady,
            'mutations_performed' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function isolation(Principal $actor): array
    {
        [$config, $key] = $this->credentials($actor);
        $discovery = $this->discoverSites($config, $key);
        $sites = $discovery['sites'];
        $paths = [];
        $pathSafe = $sites !== [] && ($discovery['inventory']['complete'] ?? false) === true;
        foreach ($sites as $site) {
            if (!is_string($site['normalized_path'] ?? null) || in_array($site['normalized_path'], $paths, true)) {
                $pathSafe = false;
            } else {
                $paths[] = $site['normalized_path'];
            }
        }
        $php = $this->gateway->phpVersions((string) $config['base_url'], $key);
        return [
            'checks' => [
                ['key' => 'site_directory_organization', 'status' => $pathSafe ? 'supported' : 'needs_attention', 'message' => $pathSafe ? '发现的站点路径均为唯一绝对路径。' : '没有足够的唯一安全路径证据。'],
                ['key' => 'php_runtime_inventory', 'status' => $php['status'] ?? 'unsupported', 'message' => '仅确认 PHP 版本清单，不证明进程隔离。'],
                ['key' => 'unix_user_isolation', 'status' => 'unsupported', 'message' => '官方只读白名单不返回站点 Unix 用户或 ACL。'],
                ['key' => 'per_site_php_fpm_pool', 'status' => 'unsupported', 'message' => 'PHP 版本信息不能证明每站独立 FPM pool。'],
                ['key' => 'nginx_private_directory_isolation', 'status' => 'unsupported', 'message' => '未读取或修改 Nginx 配置，无法证明 private 目录隔离。'],
                ['key' => 'atomic_release_same_filesystem', 'status' => 'unsupported', 'message' => '面板 API 无法证明 release 与切换指针位于同一文件系统。'],
                ['key' => 'default_files_identification', 'status' => 'unsupported', 'message' => '未读取生产目录，无法安全识别宝塔默认文件。'],
                ['key' => 'authorized_parent_directory', 'status' => 'unsupported', 'message' => '尚未授权并只读核验 LandingOps 独立部署父目录。'],
                ['key' => 'disk_free_space', 'status' => 'unsupported', 'message' => '固定只读 API 未提供目标文件系统可用空间证据。'],
                ['key' => 'nginx_version_and_vhost_boundary', 'status' => 'unsupported', 'message' => '未读取 Nginx 配置或版本，无法证明虚拟主机与 private 边界。'],
            ],
            'php_deployment' => ['status' => 'disabled', 'message' => 'Unix 用户、FPM pool 与 private 隔离未全部确认，未来 PHP 部署保持禁用。'],
            'mutations_performed' => false,
        ];
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function collectSnapshotBatch(
        array $config,
        string $key,
        int $offset,
        int $limit,
        ?string $snapshotId,
        ?int $expectedSnapshotVersion,
    ): array {
        $inventory = $this->gateway->siteInventory((string) $config['base_url'], $key);
        $rows = $this->stableInventoryRows($inventory);
        $identity = $this->inventoryIdentity($rows);
        $now = $this->clock->now();
        $nowSql = $now->format('Y-m-d H:i:s.u');
        $expiresSql = $now->modify('+' . self::SNAPSHOT_TTL_SECONDS . ' seconds')->format('Y-m-d H:i:s.u');

        if ($snapshotId === null) {
            if ($offset !== 0) {
                throw new ApiException(422, 'baota_snapshot_required', '非首批详情查询必须提交服务端快照 ID。');
            }
            $snapshot = [
                'id' => $this->ids->generate(),
                'baota_config_id' => $config['id'],
                'config_version' => (int) $config['version'],
                'inventory_fingerprint' => $identity['fingerprint'],
                'site_ids_json' => self::json($identity['site_ids']),
                'observations_json' => self::json([]),
                'status' => 'collecting',
                'next_offset' => 0,
                'failure_code' => null,
                'observed_at' => $nowSql,
                'expires_at' => $expiresSql,
                'version' => 1,
                'created_at' => $nowSql,
                'updated_at' => $nowSql,
            ];
            $this->repository->insertBaotaInventorySnapshot($snapshot);
        } else {
            $snapshot = $this->repository->findBaotaInventorySnapshot($snapshotId);
            if ($snapshot === null) {
                throw new ApiException(404, 'baota_snapshot_not_found', '宝塔清单证据快照不存在。');
            }
            if ($expectedSnapshotVersion === null || (int) $snapshot['version'] !== $expectedSnapshotVersion) {
                throw new ApiException(409, 'baota_snapshot_version_conflict', '宝塔清单证据快照版本已变化。');
            }
            if ($snapshot['status'] !== 'collecting' || (int) $snapshot['next_offset'] !== $offset) {
                throw new ApiException(409, 'baota_snapshot_not_collecting', '该快照不能继续或批次 offset 不匹配。');
            }
            if ($snapshot['baota_config_id'] !== $config['id']
                || (int) $snapshot['config_version'] !== (int) $config['version']) {
                return $this->failSnapshot($snapshot, 'baota_config_context_changed', $rows, $inventory, $nowSql);
            }
            if (!hash_equals((string) $snapshot['inventory_fingerprint'], $identity['fingerprint'])
                || self::decodeList($snapshot['site_ids_json']) !== $identity['site_ids']) {
                return $this->failSnapshot($snapshot, 'site_set_changed', $rows, $inventory, $nowSql);
            }
            if ((string) $snapshot['expires_at'] <= $nowSql) {
                return $this->failSnapshot($snapshot, 'snapshot_expired', $rows, $inventory, $nowSql);
            }
        }

        if (($inventory['complete'] ?? false) !== true) {
            return $this->failSnapshot($snapshot, 'site_listing_incomplete', $rows, $inventory, $nowSql);
        }
        if ($offset > count($rows)) {
            throw new ApiException(422, 'baota_detail_batch_invalid', '站点详情批次 offset 超出当前清单。');
        }
        $observations = self::decodeObject($snapshot['observations_json']);
        $end = min(count($rows), $offset + $limit);
        $failureCode = null;
        for ($index = $offset; $index < $end; $index++) {
            $row = $rows[$index];
            $externalId = $row['external_id'];
            try {
                $domainResult = $this->gateway->siteDomains((string) $config['base_url'], $key, $externalId);
                $status = (string) ($domainResult['status'] ?? 'unsupported');
                if ($status !== 'supported' || !is_array($domainResult['domains'] ?? null)) {
                    $failureCode = 'site_domain_detail_incomplete';
                    break;
                }
                $domains = array_values(array_unique(array_map('strval', $domainResult['domains'])));
                if (is_string($row['name'] ?? null) && !in_array($row['name'], $domains, true)) {
                    $domains[] = $row['name'];
                }
                $observations['site_' . $externalId] = [
                    'external_id' => $externalId,
                    'domains' => $domains,
                    'status' => 'supported',
                    'observed_at' => $nowSql,
                ];
            } catch (ApiException) {
                $failureCode = 'site_domain_detail_failed';
                break;
            }
        }
        if ($failureCode !== null) {
            $snapshot['observations_json'] = self::json($observations);
            return $this->failSnapshot($snapshot, $failureCode, $rows, $inventory, $nowSql);
        }

        $nextOffset = $end < count($rows) ? $end : null;
        $complete = $nextOffset === null && count($observations) === count($identity['site_ids']);
        $changes = [
            'observations_json' => self::json($observations),
            'status' => $complete ? 'complete' : 'collecting',
            'next_offset' => $nextOffset,
            'failure_code' => null,
            'updated_at' => $nowSql,
        ];
        if (!$this->repository->updateBaotaInventorySnapshot((string) $snapshot['id'], $changes, (int) $snapshot['version'])) {
            throw new ApiException(409, 'baota_snapshot_version_conflict', '保存宝塔清单证据时发生并发冲突。');
        }
        $snapshot = array_replace($snapshot, $changes, ['version' => (int) $snapshot['version'] + 1]);
        return $this->snapshotResponse($snapshot, $rows, $inventory, $offset, $limit, $end - $offset);
    }

    /** @param array<string,mixed> $config @return array{sites:list<array<string,mixed>>,inventory:array<string,mixed>} */
    private function snapshotDiscovery(array $config, string $key): array
    {
        $now = $this->clock->sql();
        $snapshot = $this->repository->findLatestCompleteBaotaInventorySnapshot(
            (string) $config['id'],
            (int) $config['version'],
            $now,
        );
        if ($snapshot === null) {
            return ['sites' => [], 'inventory' => [
                'complete' => false, 'details_complete' => false, 'reason' => 'complete_server_snapshot_required',
                'snapshot_id' => null, 'observed_at' => null, 'expires_at' => null,
            ]];
        }
        $inventory = $this->gateway->siteInventory((string) $config['base_url'], $key);
        $rows = $this->stableInventoryRows($inventory);
        $identity = $this->inventoryIdentity($rows);
        if (($inventory['complete'] ?? false) !== true
            || !hash_equals((string) $snapshot['inventory_fingerprint'], $identity['fingerprint'])
            || self::decodeList($snapshot['site_ids_json']) !== $identity['site_ids']) {
            $this->repository->updateBaotaInventorySnapshot((string) $snapshot['id'], [
                'status' => 'failed', 'next_offset' => null, 'failure_code' => 'site_set_changed', 'updated_at' => $now,
            ], (int) $snapshot['version']);
            return ['sites' => [], 'inventory' => [
                'complete' => false, 'details_complete' => false, 'reason' => 'site_set_changed',
                'snapshot_id' => $snapshot['id'], 'observed_at' => $snapshot['observed_at'], 'expires_at' => $snapshot['expires_at'],
            ]];
        }
        return $this->snapshotResponse($snapshot, $rows, $inventory, 0, 0, 0);
    }

    /** @param array<string,mixed> $snapshot @param list<array<string,mixed>> $rows @param array<string,mixed> $inventory @return array<string,mixed> */
    private function failSnapshot(array $snapshot, string $code, array $rows, array $inventory, string $now): array
    {
        $changes = [
            'observations_json' => $snapshot['observations_json'],
            'status' => 'failed', 'next_offset' => null, 'failure_code' => $code, 'updated_at' => $now,
        ];
        if (!$this->repository->updateBaotaInventorySnapshot((string) $snapshot['id'], $changes, (int) $snapshot['version'])) {
            throw new ApiException(409, 'baota_snapshot_version_conflict', '保存失败证据时发生并发冲突。');
        }
        $snapshot = array_replace($snapshot, $changes, ['version' => (int) $snapshot['version'] + 1]);
        return $this->snapshotResponse($snapshot, $rows, $inventory, (int) ($snapshot['next_offset'] ?? 0), 0, 0);
    }

    /** @param array<string,mixed> $snapshot @param list<array<string,mixed>> $rows @param array<string,mixed> $inventory @return array<string,mixed> */
    private function snapshotResponse(array $snapshot, array $rows, array $inventory, int $offset, int $limit, int $processed): array
    {
        $observations = self::decodeObject($snapshot['observations_json']);
        $instances = $this->repository->listInstances(null, null);
        $sites = [];
        foreach ($rows as $row) {
            $observation = $observations['site_' . $row['external_id']] ?? null;
            $domains = is_array($observation) && is_array($observation['domains'] ?? null) ? $observation['domains'] : [];
            $normalizedPath = null;
            if (is_string($row['path'] ?? null)) {
                try {
                    $normalizedPath = BaotaPath::normalize($row['path']);
                } catch (ApiException) {
                    $normalizedPath = null;
                }
            }
            $site = array_merge($row, [
                'domains' => $domains,
                'domain_capability' => is_array($observation) ? ($observation['status'] ?? 'unsupported') : 'not_in_snapshot',
                'normalized_path' => $normalizedPath,
                'php_capability' => 'not_queried', 'php_version' => null, 'ownership_marker_hash' => null,
            ]);
            $site['ownership'] = $this->ownership->evaluate($site, $instances);
            $sites[] = $site;
        }
        $complete = $snapshot['status'] === 'complete'
            && count($observations) === count(self::decodeList($snapshot['site_ids_json']));
        return [
            'sites' => $sites,
            'inventory' => [
                'complete' => $complete,
                'listing_complete' => (bool) ($inventory['complete'] ?? false),
                'listing_reason' => $inventory['reason'] ?? null,
                'details_complete' => $complete,
                'reason' => $complete ? null : ($snapshot['failure_code'] ?? 'detail_batches_remaining'),
                'pages' => (int) ($inventory['pages'] ?? 0),
                'site_limit' => (int) ($inventory['site_limit'] ?? 200),
                'detail_query_budget' => self::DETAIL_QUERY_BUDGET,
                'detail_queries_used' => count($observations),
                'detail_failures' => $snapshot['status'] === 'failed' ? 1 : 0,
                'snapshot_id' => $snapshot['id'],
                'snapshot_version' => (int) $snapshot['version'],
                'snapshot_status' => $snapshot['status'],
                'inventory_fingerprint' => $snapshot['inventory_fingerprint'],
                'observed_at' => $snapshot['observed_at'],
                'expires_at' => $snapshot['expires_at'],
            ],
            'batch' => [
                'offset' => $offset, 'limit' => $limit, 'processed' => $processed,
                'complete' => $complete, 'next_offset' => $snapshot['next_offset'],
                'total_sites' => count($rows), 'observed_at' => $snapshot['observed_at'],
                'snapshot_id' => $snapshot['id'], 'snapshot_version' => (int) $snapshot['version'],
                'snapshot_status' => $snapshot['status'], 'failure_code' => $snapshot['failure_code'],
                'write_operations_performed' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $inventory @return list<array<string,mixed>> */
    private function stableInventoryRows(array $inventory): array
    {
        $rows = is_array($inventory['sites'] ?? null) ? array_values($inventory['sites']) : [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_int($row['external_id'] ?? null)) {
                throw new ApiException(502, 'baota_site_identity_incomplete', '站点清单缺少稳定外部 ID。');
            }
        }
        usort($rows, static fn (array $a, array $b): int => $a['external_id'] <=> $b['external_id']);
        $ids = array_column($rows, 'external_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new ApiException(502, 'baota_site_identity_duplicate', '站点清单包含重复外部 ID。');
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $rows @return array{site_ids:list<int>,fingerprint:string} */
    private function inventoryIdentity(array $rows): array
    {
        $identity = array_map(static fn (array $row): array => [
            'external_id' => $row['external_id'],
            'name' => is_string($row['name'] ?? null) ? $row['name'] : null,
            'path' => is_string($row['path'] ?? null) ? $row['path'] : null,
            'status' => is_string($row['status'] ?? null) ? $row['status'] : null,
            'metadata_complete' => ($row['metadata_complete'] ?? false) === true,
        ], $rows);
        return [
            'site_ids' => array_values(array_map('intval', array_column($identity, 'external_id'))),
            'fingerprint' => hash('sha256', self::json($identity)),
        ];
    }

    /** @param array<string,mixed> $config @return array{sites:list<array<string,mixed>>,inventory:array<string,mixed>} */
    private function discoverSites(array $config, string $key, int $detailOffset = 0, int $detailLimit = self::DETAIL_QUERY_BUDGET): array
    {
        $inventory = $this->gateway->siteInventory((string) $config['base_url'], $key);
        $rows = is_array($inventory['sites'] ?? null) ? $inventory['sites'] : [];
        if ($detailOffset > count($rows)) {
            throw new ApiException(422, 'baota_detail_batch_invalid', '站点详情批次 offset 超出当前清单。');
        }
        $instances = $this->repository->listInstances(null, null);
        $result = [];
        $detailQueries = 0;
        $detailFailures = 0;
        foreach ($rows as $index => $row) {
            $domains = [];
            $inBatch = $index >= $detailOffset && $index < ($detailOffset + $detailLimit);
            $domainStatus = $inBatch ? 'unsupported' : 'not_in_batch';
            if ($inBatch && is_int($row['external_id'] ?? null) && $detailQueries < $detailLimit) {
                $detailQueries++;
                try {
                    $domainResult = $this->gateway->siteDomains((string) $config['base_url'], $key, $row['external_id']);
                    $domains = is_array($domainResult['domains'] ?? null) ? $domainResult['domains'] : [];
                    $domainStatus = (string) ($domainResult['status'] ?? 'unsupported');
                } catch (ApiException) {
                    $domainStatus = 'incomplete';
                    $detailFailures++;
                }
            }
            if (is_string($row['name'] ?? null) && !in_array($row['name'], $domains, true)) {
                $domains[] = $row['name'];
            }
            $normalizedPath = null;
            if (is_string($row['path'] ?? null)) {
                try {
                    $normalizedPath = BaotaPath::normalize($row['path']);
                } catch (ApiException) {
                    $normalizedPath = null;
                }
            }
            $site = array_merge($row, [
                'domains' => array_values(array_unique(array_map('strval', $domains))),
                'domain_capability' => $domainStatus, 'normalized_path' => $normalizedPath,
                'php_capability' => 'not_queried', 'php_version' => null,
                'ownership_marker_hash' => null,
            ]);
            $site['ownership'] = $this->ownership->evaluate($site, $instances);
            $result[] = $site;
        }
        $batchEnd = min(count($rows), $detailOffset + $detailLimit);
        $batchComplete = $detailOffset <= count($rows)
            && $detailQueries === ($batchEnd - $detailOffset)
            && $detailFailures === 0;
        $detailsComplete = $detailOffset === 0 && count($rows) <= $detailLimit && $batchComplete;
        $observedAt = $this->clock->sql();
        return [
            'sites' => $result,
            'inventory' => [
                'complete' => (bool) ($inventory['complete'] ?? false) && $detailsComplete,
                'listing_complete' => (bool) ($inventory['complete'] ?? false),
                'listing_reason' => $inventory['reason'] ?? null,
                'pages' => (int) ($inventory['pages'] ?? 0),
                'site_limit' => (int) ($inventory['site_limit'] ?? 200),
                'detail_query_budget' => $detailLimit,
                'detail_queries_used' => $detailQueries,
                'detail_failures' => $detailFailures,
                'details_complete' => $detailsComplete,
                'reason' => !($inventory['complete'] ?? false)
                    ? ($inventory['reason'] ?? 'listing_incomplete')
                    : (!$detailsComplete ? ($detailFailures > 0 ? 'detail_query_failed' : 'detail_batches_remaining') : null),
            ],
            'batch' => [
                'offset' => $detailOffset,
                'limit' => $detailLimit,
                'processed' => $detailQueries,
                'complete' => $batchComplete,
                'next_offset' => !$batchComplete ? $detailOffset : ($batchEnd < count($rows) ? $batchEnd : null),
                'total_sites' => count($rows),
                'observed_at' => $observedAt,
                'write_operations_performed' => false,
            ],
        ];
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function credentials(Principal $actor): array
    {
        $this->authorization->assertSuperAdmin($actor);
        $config = $this->configs->requireEnabled($actor);
        if (($config['verification_status'] ?? null) !== 'valid') {
            throw new ApiException(409, 'baota_not_verified', '宝塔连接尚未通过当前凭据的只读验证。');
        }
        return [$config, $this->configs->decryptApiKey($config)];
    }

    private static function hostname(string $value): string
    {
        $value = strtolower(rtrim(trim($value), '.'));
        if (strlen($value) > 253 || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $value) !== 1) {
            throw new ApiException(422, 'fqdn_invalid', '候选完整域名格式无效。');
        }
        return $value;
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string,mixed> */
    private static function decodeObject(mixed $json): array
    {
        if (!is_string($json)) {
            return [];
        }
        $value = json_decode($json, true, 64);
        return is_array($value) ? $value : [];
    }

    /** @return list<int> */
    private static function decodeList(mixed $json): array
    {
        if (!is_string($json)) {
            return [];
        }
        $value = json_decode($json, true, 64);
        return is_array($value) && array_is_list($value) ? array_values(array_map('intval', $value)) : [];
    }
}
