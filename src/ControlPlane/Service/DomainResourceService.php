<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Service;

use LandingOps\Contract\Json;
use LandingOps\ControlPlane\Audit\AuditLogger;
use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\Principal;
use LandingOps\ControlPlane\Auth\Role;
use LandingOps\ControlPlane\Domain\DomainName;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Repository\ControlPlaneRepository;
use LandingOps\ControlPlane\Support\IdGenerator;
use LandingOps\ControlPlane\Support\UtcClock;
use Throwable;

final class DomainResourceService
{
    private readonly AuditLogger $audit;

    public function __construct(
        private readonly ControlPlaneRepository $repository,
        private readonly AuthorizationService $authorization = new AuthorizationService(),
        private readonly IdGenerator $ids = new IdGenerator(),
        private readonly UtcClock $clock = new UtcClock(),
        ?AuditLogger $audit = null,
    ) {
        $this->audit = $audit ?? new AuditLogger($repository, $ids, $clock);
    }

    /** @return list<array<string,mixed>> */
    public function listZones(Principal $actor): array
    {
        $this->authorization->assertSuperAdmin($actor);
        return array_map(self::safeZone(...), $this->repository->listCloudflareZones());
    }

    /** @return array<string,mixed> */
    public function createZone(Principal $actor, array $input, string $requestId): array
    {
        $this->authorization->assertSuperAdmin($actor);
        $connectionId = self::requiredId($input, 'connection_id');
        $connection = $this->repository->findCloudflareConnection($connectionId);
        if ($connection === null || ($connection['admin_state'] ?? null) === 'disabled') {
            throw new ApiException(404, 'cloudflare_connection_not_available', 'Cloudflare 连接不存在或已停用。');
        }
        $externalId = self::externalId($input['external_zone_id'] ?? null);
        $name = DomainName::normalize(self::requiredString($input, 'zone_name'), 'zone_name');
        if ($this->repository->findCloudflareZoneByName($name) !== null
            || $this->repository->findCloudflareZoneByExternal($connectionId, $externalId) !== null
        ) {
            throw new ApiException(409, 'cloudflare_zone_conflict', 'Zone 名称或连接内外部 ID 已经存在。');
        }
        $now = $this->clock->sql();
        $row = [
            'id' => $this->ids->generate(), 'connection_id' => $connectionId, 'external_zone_id' => $externalId,
            'zone_name' => $name, 'normalized_zone_name' => $name, 'status' => 'unverified',
            'admin_state' => 'enabled', 'verification_status' => 'unverified',
            'verified_credential_context_version' => null,
            'cloudflare_status' => null,
            'last_synced_at' => null, 'last_verification_result_json' => null, 'version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
        try {
            $this->repository->transaction(function () use ($row, $actor, $requestId): void {
                $this->repository->insertCloudflareZone($row);
                $this->audit->record($actor, 'cloudflare.zone_create', 'cloudflare_zone', (string) $row['id'], $requestId, [
                    'connection_id' => $row['connection_id'], 'zone_name' => $row['normalized_zone_name'], 'status' => 'unverified',
                ]);
            });
        } catch (Throwable $exception) {
            if ($this->repository->findCloudflareZoneByName($name) !== null
                || $this->repository->findCloudflareZoneByExternal($connectionId, $externalId) !== null
            ) {
                throw new ApiException(409, 'cloudflare_zone_conflict', 'Zone 名称或连接内外部 ID 已经存在。');
            }
            throw $exception;
        }
        return self::safeZone($this->repository->findCloudflareZone((string) $row['id']) ?? $row);
    }

    /** @return array<string,mixed> */
    public function updateZone(Principal $actor, string $id, array $input, string $requestId): array
    {
        $this->authorization->assertSuperAdmin($actor);
        $zone = $this->repository->findCloudflareZone($id);
        if ($zone === null) {
            throw new ApiException(404, 'cloudflare_zone_not_found', 'Cloudflare Zone 不存在。');
        }
        $adminState = $input['admin_state'] ?? null;
        $expectedVersion = $input['expected_version'] ?? null;
        if (!in_array($adminState, ['enabled', 'disabled'], true)) {
            throw new ApiException(422, 'cloudflare_zone_admin_state_invalid', 'Zone 管理员状态必须是 enabled 或 disabled。');
        }
        if (!is_int($expectedVersion) || $expectedVersion < 1) {
            throw new ApiException(422, 'expected_version_invalid', '必须提交有效的 expected_version。');
        }
        $status = self::legacyStatus((string) $adminState, (string) $zone['verification_status']);
        $this->repository->transaction(function () use ($id, $adminState, $status, $expectedVersion, $zone, $actor, $requestId): void {
            if (!$this->repository->updateCloudflareZone($id, [
                'admin_state' => $adminState, 'status' => $status, 'updated_at' => $this->clock->sql(),
            ], $expectedVersion)) {
                throw new ApiException(409, 'cloudflare_zone_version_conflict', 'Zone 已被其他管理员更新，请刷新后重试。');
            }
            $action = $adminState === 'disabled' && ($zone['admin_state'] ?? null) !== 'disabled'
                ? 'cloudflare.zone_disable'
                : ($adminState === 'enabled' && ($zone['admin_state'] ?? null) !== 'enabled'
                    ? 'cloudflare.zone_enable' : 'cloudflare.zone_update');
            $this->audit->record($actor, $action, 'cloudflare_zone', $id, $requestId, ['admin_state' => $adminState]);
        });
        return self::safeZone($this->repository->findCloudflareZone($id) ?? []);
    }

    /** @return list<array<string,mixed>> */
    public function listDomains(Principal $actor): array
    {
        $this->authorization->assertActive($actor);
        $teamId = $actor->role === Role::SUPER_ADMIN ? null : $actor->teamId;
        if ($actor->role !== Role::SUPER_ADMIN && $teamId === null) {
            return [];
        }
        $usableOnly = $actor->role === Role::STAFF;
        $rows = $this->repository->listPrimaryDomains($teamId, $usableOnly);
        foreach ($rows as &$row) {
            $row['available'] = self::available($row);
            $row['zone_verification_result'] = self::decodeResult($row['zone_verification_result_json'] ?? null);
            unset($row['zone_verification_result_json']);
            $row['grants'] = $actor->role === Role::SUPER_ADMIN
                ? $this->repository->listDomainGrants((string) $row['id']) : [];
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,mixed> */
    public function createDomain(Principal $actor, array $input, string $requestId): array
    {
        $this->authorization->assertSuperAdmin($actor);
        $zoneId = self::requiredId($input, 'zone_id');
        $zone = $this->repository->findCloudflareZone($zoneId);
        if ($zone === null || ($zone['admin_state'] ?? null) === 'disabled'
            || ($zone['connection_admin_state'] ?? null) === 'disabled') {
            throw new ApiException(404, 'cloudflare_zone_not_available', 'Zone 不存在，或 Zone/连接已停用。');
        }
        $name = DomainName::normalize(self::requiredString($input, 'domain_name'));
        if (!DomainName::belongsToZone($name, (string) $zone['normalized_zone_name'])) {
            throw new ApiException(422, 'domain_zone_mismatch', '主域名不属于所选 Zone。');
        }
        if ($this->repository->findPrimaryDomainByName($name) !== null) {
            throw new ApiException(409, 'primary_domain_conflict', '规范化主域名已经存在。');
        }
        $now = $this->clock->sql();
        $row = [
            'id' => $this->ids->generate(), 'zone_id' => $zoneId, 'domain_name' => $name,
            'normalized_domain_name' => $name, 'status' => 'active',
            'dns_policy_json' => Json::encode((object) []), 'created_by_user_id' => $actor->id,
            'created_at' => $now, 'updated_at' => $now,
        ];
        try {
            $this->repository->transaction(function () use ($row, $actor, $requestId): void {
                $this->repository->insertPrimaryDomain($row);
                $this->audit->record($actor, 'domain.primary_create', 'primary_domain', (string) $row['id'], $requestId, [
                    'zone_id' => $row['zone_id'], 'domain_name' => $row['normalized_domain_name'],
                ]);
            });
        } catch (Throwable $exception) {
            if ($this->repository->findPrimaryDomainByName($name) !== null) {
                throw new ApiException(409, 'primary_domain_conflict', '规范化主域名已经存在。');
            }
            throw $exception;
        }
        return $this->findDomainForSuper((string) $row['id']);
    }

    /** @return array<string,mixed> */
    public function updateDomain(Principal $actor, string $id, array $input, string $requestId): array
    {
        $this->authorization->assertSuperAdmin($actor);
        $domain = $this->repository->findPrimaryDomain($id);
        if ($domain === null) {
            throw new ApiException(404, 'primary_domain_not_found', '主域名不存在。');
        }
        $status = $input['status'] ?? null;
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new ApiException(422, 'primary_domain_status_invalid', '主域名状态无效。');
        }
        if ($status === 'active' && (($domain['zone_admin_state'] ?? null) === 'disabled'
            || ($domain['connection_admin_state'] ?? null) === 'disabled')) {
            throw new ApiException(409, 'primary_domain_upstream_disabled', 'Zone 或连接停用时不能重新启用主域名。');
        }
        $this->repository->transaction(function () use ($id, $status, $actor, $requestId): void {
            $this->repository->updatePrimaryDomainStatus($id, (string) $status, $this->clock->sql());
            $this->audit->record($actor, 'domain.primary_status', 'primary_domain', $id, $requestId, ['status' => $status]);
        });
        return $this->findDomainForSuper($id);
    }

    public function grant(Principal $actor, string $domainId, string $teamId, string $requestId): void
    {
        $this->authorization->assertSuperAdmin($actor);
        $domain = $this->repository->findPrimaryDomain($domainId);
        if ($domain === null || ($domain['status'] ?? null) !== 'active'
            || ($domain['zone_admin_state'] ?? null) === 'disabled'
            || ($domain['connection_admin_state'] ?? null) === 'disabled'
        ) {
            throw new ApiException(404, 'primary_domain_not_available', '主域名不存在或其上游资源已停用。');
        }
        $team = $this->repository->findTeamById($teamId);
        if ($team === null || ($team['status'] ?? null) !== 'active') {
            throw new ApiException(404, 'team_not_found', '授权目标团队不存在。');
        }
        $now = $this->clock->sql();
        $this->repository->transaction(function () use ($actor, $domainId, $teamId, $requestId, $now): void {
            $this->repository->grantPrimaryDomain([
                'id' => $this->ids->generate(), 'primary_domain_id' => $domainId, 'team_id' => $teamId,
                'granted_by_user_id' => $actor->id, 'granted_at' => $now,
            ]);
            $this->audit->record($actor, 'domain.grant_set', 'primary_domain', $domainId, $requestId, ['team_id' => $teamId]);
        });
    }

    public function revoke(Principal $actor, string $domainId, string $teamId, string $requestId): void
    {
        $this->authorization->assertSuperAdmin($actor);
        if ($this->repository->findPrimaryDomain($domainId) === null || $this->repository->findTeamById($teamId) === null) {
            throw new ApiException(404, 'domain_grant_not_found', '主域名或团队不存在。');
        }
        $now = $this->clock->sql();
        $this->repository->transaction(function () use ($actor, $domainId, $teamId, $requestId, $now): void {
            if (!$this->repository->revokePrimaryDomainGrant($domainId, $teamId, $now)) {
                throw new ApiException(409, 'domain_grant_not_active', '主域名授权不存在或已经撤销。');
            }
            $this->audit->record($actor, 'domain.grant_revoke', 'primary_domain', $domainId, $requestId, ['team_id' => $teamId]);
        });
    }

    /** @return array<string,mixed> */
    private function findDomainForSuper(string $id): array
    {
        foreach ($this->repository->listPrimaryDomains(null, false) as $row) {
            if (($row['id'] ?? null) === $id) {
                $row['available'] = self::available($row);
                $row['zone_verification_result'] = self::decodeResult($row['zone_verification_result_json'] ?? null);
                unset($row['zone_verification_result_json']);
                $row['grants'] = $this->repository->listDomainGrants($id);
                return $row;
            }
        }
        throw new ApiException(404, 'primary_domain_not_found', '主域名不存在。');
    }

    /** @param array<string,mixed> $input */
    private static function requiredString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ApiException(400, 'input_invalid', '缺少或无效字段：' . $key);
        }
        return trim($value);
    }

    /** @param array<string,mixed> $input */
    private static function requiredId(array $input, string $key): string
    {
        $value = self::requiredString($input, $key);
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $value) !== 1) {
            throw new ApiException(422, 'id_invalid', '资源 ID 格式无效。', ['field' => $key]);
        }
        return $value;
    }

    private static function externalId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $value) !== 1) {
            throw new ApiException(422, 'cloudflare_zone_id_invalid', 'Cloudflare Zone 外部标识格式无效。');
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function available(array $row): bool
    {
        return ($row['domain_status'] ?? null) === 'active'
            && ($row['zone_admin_state'] ?? null) === 'enabled'
            && ($row['zone_verification_status'] ?? null) === 'valid'
            && ($row['zone_verified_credential_context_version'] ?? null) === ($row['connection_credential_context_version'] ?? null)
            && ($row['zone_cloudflare_status'] ?? $row['cloudflare_status'] ?? null) === 'active'
            && ($row['connection_admin_state'] ?? null) === 'enabled'
            && ($row['connection_verification_status'] ?? null) === 'valid';
    }

    private static function legacyStatus(string $adminState, string $verificationStatus): string
    {
        if ($adminState === 'disabled') {
            return 'disabled';
        }
        return $verificationStatus === 'valid' ? 'active' : 'unverified';
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function safeZone(array $row): array
    {
        $row['last_verification_result'] = self::decodeResult($row['last_verification_result_json'] ?? null);
        unset($row['last_verification_result_json']);
        return $row;
    }

    /** @return array<string,mixed>|null */
    private static function decodeResult(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : null;
    }
}
