<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Service;

use LandingOps\Contract\ConfigValidator;
use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\Principal;
use LandingOps\ControlPlane\Auth\Role;
use LandingOps\ControlPlane\Baota\BaotaPath;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Repository\ControlPlaneRepository;
use stdClass;

final class DeploymentDryRunService
{
    public function __construct(
        private readonly ControlPlaneRepository $repository,
        private readonly ?BaotaReadOnlyService $baota = null,
        private readonly AuthorizationService $authorization = new AuthorizationService(),
        private readonly ConfigValidator $validator = new ConfigValidator(),
        private readonly ConfigFormSchemaBuilder $forms = new ConfigFormSchemaBuilder(),
    ) {
    }

    /** @return array<string,mixed> */
    public function configForm(Principal $actor, string $versionId): array
    {
        $version = $this->version($actor, $versionId);
        $schema = $this->jsonObject($version['config_schema_json'] ?? null, 'config_schema_invalid');
        return ['template_version_id' => $versionId] + $this->forms->build($schema);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function run(Principal $actor, array $input): array
    {
        $this->authorization->assertActive($actor);
        $versionId = $this->requiredString($input, 'template_version_id');
        $domainId = $this->requiredString($input, 'primary_domain_id');
        $prefix = strtolower(trim($this->requiredString($input, 'subdomain_prefix'), ". \t\n\r\0\x0B"));
        $configInput = $input['config'] ?? null;
        if (!is_array($configInput) || array_is_list($configInput)) {
            throw new ApiException(422, 'config_object_required', '站点配置必须是 JSON 对象。');
        }

        $blockers = [];
        $risks = [];
        $version = $this->version($actor, $versionId);
        $domain = $this->domain($actor, $domainId);
        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/D', $prefix) !== 1) {
            throw new ApiException(422, 'subdomain_prefix_invalid', '子域名前缀格式无效。');
        }
        $fqdn = $prefix . '.' . strtolower((string) $domain['normalized_domain_name']);
        if (strlen($fqdn) > 253) {
            throw new ApiException(422, 'fqdn_invalid', '生成的完整域名过长。');
        }
        if ($this->repository->findInstanceByNormalizedFqdn($fqdn) !== null) {
            $blockers[] = self::item('fqdn_used_by_instance', 'FQDN 已被现有实例使用。');
        }
        if ($this->repository->hasActiveDomainReservation($fqdn)) {
            $blockers[] = self::item('fqdn_reserved', 'FQDN 已存在有效预留。');
        }

        $schema = $this->jsonObject($version['config_schema_json'] ?? null, 'config_schema_invalid');
        $config = json_decode(json_encode($configInput, JSON_THROW_ON_ERROR), false, 128, JSON_THROW_ON_ERROR);
        $validation = $this->validator->validate($config, $schema)->toArray();
        if (!$validation['valid']) {
            $blockers[] = self::item('config_invalid', '站点配置未通过模板 Schema 校验。');
        }
        $manifest = $this->jsonObject($version['manifest_json'] ?? null, 'template_manifest_invalid');
        if (($manifest->runtime->php->required ?? false) === true) {
            $blockers[] = self::item('php_isolation_unverified', 'PHP 模板部署保持禁用，尚无足够的运行隔离证据。');
        }

        $baota = $this->baota?->precheckForDeployment($fqdn) ?? [
            'classification' => 'needs_attention',
            'candidate_path' => BaotaPath::candidateRoot($fqdn),
            'issues' => [self::item('baota_live_check_unavailable', '本次预演未配置宝塔只读检查。')],
            'mutations_performed' => false,
        ];
        if (($baota['classification'] ?? null) === 'conflict') {
            $blockers[] = self::item('baota_conflict', '宝塔只读预检查发现站点或路径冲突。');
        } elseif (($baota['classification'] ?? null) !== 'clear') {
            $risks[] = self::item('baota_evidence_incomplete', '宝塔或文件系统证据不足，正式提交前必须重新检查。');
        }
        $risks[] = self::item('dns_write_precheck_required', '正式提交前必须重新执行 DNS 精确记录、通配符及委派检查。');
        $risks[] = self::item('dry_run_not_reservation', '预演不预留域名、实例、任务或任何外部资源。');

        $targetPath = $baota['candidate_path'] ?? BaotaPath::candidateRoot($fqdn);
        $status = $blockers !== [] ? 'blocked' : ($risks !== [] ? 'needs_attention' : 'ready');
        return [
            'status' => $status,
            'dry_run' => true,
            'mutations_performed' => false,
            'template' => [
                'id' => $version['template_id'], 'slug' => $version['slug'], 'name' => $version['template_name'],
                'version_id' => $version['id'], 'version' => $version['version'], 'zip_sha256' => $version['zip_sha256'],
            ],
            'domain' => ['primary_domain_id' => $domainId, 'fqdn' => $fqdn, 'authorization' => 'valid'],
            'configuration' => [
                'valid' => $validation['valid'], 'errors' => $validation['errors'], 'warnings' => $validation['warnings'],
                'schema_hash' => $this->forms->build($schema)['schema_hash'],
                'submitted_field_count' => count($configInput),
                'values_returned' => false,
            ],
            'external_checks' => ['baota' => $baota, 'dns' => ['status' => 'needs_attention', 'mutations_performed' => false]],
            'plan' => [
                'target_path' => $targetPath,
                'steps' => [
                    ['order' => 1, 'kind' => 'revalidate', 'action' => '重新校验权限、Schema、实例、预留和外部状态', 'execute' => false],
                    ['order' => 2, 'kind' => 'baota', 'action' => '创建隔离站点并确认根目录', 'execute' => false],
                    ['order' => 3, 'kind' => 'build', 'action' => '离线构建不可变 release 与公开配置投影', 'execute' => false],
                    ['order' => 4, 'kind' => 'dns', 'action' => '按已确认 IPv4 创建 DNS A 记录', 'execute' => false],
                    ['order' => 5, 'kind' => 'ssl', 'action' => 'DNS 生效后申请并验证 HTTPS', 'execute' => false],
                    ['order' => 6, 'kind' => 'publish', 'action' => '原子切换 release 并保留回滚版本', 'execute' => false],
                ],
                'dns' => ['type' => 'A', 'name' => $fqdn, 'content' => 'ENV:LANDINGOPS_CONFIRMED_PUBLIC_IPV4', 'proxied' => false, 'ttl' => 1, 'execute' => false],
                'baota' => ['hostname' => $fqdn, 'document_root' => $targetPath, 'execute' => false],
                'files' => ['strategy' => 'immutable_release_and_atomic_pointer', 'execute' => false],
                'ssl' => ['requires_dns_ready' => true, 'requires_fresh_precheck' => true, 'execute' => false],
            ],
            'blockers' => $blockers,
            'risks' => $risks,
            'disclaimer' => 'Dry Run 不是创建许可；正式提交必须在同一受控流程中再次验证全部条件。',
        ];
    }

    /** @return array<string,mixed> */
    private function version(Principal $actor, string $id): array
    {
        $teamId = $actor->role === Role::SUPER_ADMIN ? null : $actor->teamId;
        $version = $this->repository->findTemplateVersionDetail($id, $teamId);
        if ($version === null || ($version['validation_status'] ?? null) !== 'valid') {
            throw new ApiException(404, 'template_not_available', '模板版本不存在、无效或当前团队未获授权。');
        }
        return $version;
    }

    /** @return array<string,mixed> */
    private function domain(Principal $actor, string $id): array
    {
        $domain = $this->repository->findPrimaryDomain($id);
        if ($domain === null) {
            throw new ApiException(404, 'primary_domain_not_available', '主域名不存在或不可用。');
        }
        if ($actor->role !== Role::SUPER_ADMIN
            && ($actor->teamId === null || !$this->repository->hasDomainGrant($id, $actor->teamId))) {
            throw new ApiException(404, 'primary_domain_not_available', '主域名不存在或当前团队未获授权。');
        }
        $usable = ($domain['status'] ?? null) === 'active'
            && ($domain['zone_admin_state'] ?? null) === 'enabled'
            && ($domain['zone_verification_status'] ?? null) === 'valid'
            && ($domain['zone_cloudflare_status'] ?? null) === 'active'
            && (string) ($domain['zone_verified_credential_context_version'] ?? '') === (string) ($domain['connection_credential_context_version'] ?? '')
            && ($domain['connection_admin_state'] ?? null) === 'enabled'
            && ($domain['connection_verification_status'] ?? null) === 'valid';
        if (!$usable) {
            throw new ApiException(409, 'primary_domain_not_usable', '主域名、Zone 或连接的当前验证上下文不可用。');
        }
        return $domain;
    }

    private function jsonObject(mixed $json, string $code): stdClass
    {
        try {
            $value = is_string($json) ? json_decode($json, false, 128, JSON_THROW_ON_ERROR) : $json;
        } catch (\JsonException) {
            throw new ApiException(422, $code, '模板契约 JSON 无效。');
        }
        if (!$value instanceof stdClass) {
            throw new ApiException(422, $code, '模板契约必须是 JSON 对象。');
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function requiredString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ApiException(400, 'input_invalid', '缺少或无效字段：' . $key);
        }
        return trim($value);
    }

    /** @return array{code:string,message:string} */
    private static function item(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
