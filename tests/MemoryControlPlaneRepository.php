<?php

declare(strict_types=1);

namespace LandingOps\Tests;

use LandingOps\ControlPlane\Repository\ControlPlaneRepository;
use RuntimeException;
use Throwable;

/**
 * 仅用于不依赖数据库驱动的服务层契约测试；不宣称模拟 MySQL 行为。
 */
class MemoryControlPlaneRepository implements ControlPlaneRepository
{
    /** @var array<string,array<string,mixed>> */
    public array $users = [];
    /** @var array<string,array<string,mixed>> */
    public array $teams = [];
    /** @var array<string,array<string,mixed>> */
    public array $sessions = [];
    /** @var array<string,array<string,mixed>> */
    public array $instances = [];
    /** @var list<array<string,mixed>> */
    public array $ownerHistory = [];
    /** @var list<array<string,mixed>> */
    public array $audits = [];
    /** @var array<string,array<string,mixed>> */
    public array $templates = [];
    /** @var array<string,array<string,mixed>> */
    public array $templateVersions = [];
    /** @var array<string,array<string,mixed>> */
    public array $templateGrants = [];
    /** @var array<string,array<string,mixed>> */
    public array $cloudflareConnections = [];
    /** @var array<string,array<string,mixed>> */
    public array $cloudflareZones = [];
    /** @var array<string,array<string,mixed>> */
    public array $primaryDomains = [];
    /** @var array<string,array<string,mixed>|bool> */
    public array $domainGrants = [];
    /** @var array<string,array<string,mixed>> */
    public array $domainReservations = [];
    /** @var array<string,mixed>|null */
    public ?array $baotaConfig = null;

    public function transaction(callable $callback): mixed
    {
        $snapshot = serialize([
            $this->users,
            $this->teams,
            $this->sessions,
            $this->instances,
            $this->ownerHistory,
            $this->audits,
            $this->templates,
            $this->templateVersions,
            $this->templateGrants,
            $this->cloudflareConnections,
            $this->cloudflareZones,
            $this->primaryDomains,
            $this->domainGrants,
            $this->domainReservations,
            $this->baotaConfig,
        ]);
        try {
            return $callback();
        } catch (Throwable $exception) {
            [
                $this->users,
                $this->teams,
                $this->sessions,
                $this->instances,
                $this->ownerHistory,
                $this->audits,
                $this->templates,
                $this->templateVersions,
                $this->templateGrants,
                $this->cloudflareConnections,
                $this->cloudflareZones,
                $this->primaryDomains,
                $this->domainGrants,
                $this->domainReservations,
                $this->baotaConfig,
            ] = unserialize($snapshot, ['allowed_classes' => false]);
            throw $exception;
        }
    }

    public function countUsersByRole(string $role): int
    {
        return count(array_filter($this->users, static fn (array $user): bool => $user['role'] === $role));
    }

    public function findUserByEmail(string $normalizedEmail): ?array
    {
        foreach ($this->users as $user) {
            if ($user['normalized_email'] === $normalizedEmail) {
                return $user;
            }
        }
        return null;
    }

    public function findUserById(string $id): ?array
    {
        return $this->users[$id] ?? null;
    }

    public function insertUser(array $user): void
    {
        foreach ($this->users as $existing) {
            if ($existing['normalized_email'] === $user['normalized_email']) {
                throw new RuntimeException('duplicate normalized_email');
            }
        }
        $this->users[(string) $user['id']] = $user;
    }

    public function insertTeam(array $team): void
    {
        foreach ($this->teams as $existing) {
            if ($existing['slug'] === $team['slug']) {
                throw new RuntimeException('duplicate team slug');
            }
        }
        $this->teams[(string) $team['id']] = $team;
    }

    public function findTeamById(string $id): ?array
    {
        return $this->teams[$id] ?? null;
    }

    public function listTeams(): array
    {
        return array_values($this->teams);
    }

    public function listUsers(?string $teamId): array
    {
        return array_values(array_filter(
            $this->users,
            static fn (array $user): bool => $teamId === null || $user['team_id'] === $teamId,
        ));
    }

    public function updateLastLogin(string $userId, string $at): void
    {
        $this->users[$userId]['last_login_at'] = $at;
    }

    public function updateUserProfile(string $userId, string $displayName, string $at): void
    {
        $this->users[$userId]['display_name'] = $displayName;
        $this->users[$userId]['updated_at'] = $at;
        $this->users[$userId]['version']++;
    }

    public function updatePassword(string $userId, string $hash, string $at): void
    {
        $this->users[$userId]['password_hash'] = $hash;
        $this->users[$userId]['password_changed_at'] = $at;
        $this->users[$userId]['updated_at'] = $at;
        $this->users[$userId]['version']++;
    }

    public function disableUser(string $userId, string $at): void
    {
        $this->users[$userId]['status'] = 'disabled';
        $this->users[$userId]['updated_at'] = $at;
        $this->users[$userId]['version']++;
    }

    public function insertSession(array $session): void
    {
        $this->sessions[(string) $session['id']] = $session + ['revoked_at' => null, 'revoke_reason' => null];
    }

    public function findSessionByHash(string $tokenHash): ?array
    {
        foreach ($this->sessions as $session) {
            if ($session['revoked_at'] === null && hash_equals($session['token_hash'], $tokenHash)) {
                $user = $this->users[(string) $session['user_id']] ?? null;
                if ($user === null) {
                    return null;
                }
                return $user + [
                    'session_id' => $session['id'],
                    'csrf_hash' => $session['csrf_hash'],
                    'expires_at' => $session['expires_at'],
                ];
            }
        }
        return null;
    }

    public function updateSessionCsrf(string $sessionId, string $csrfHash, string $at): void
    {
        if (isset($this->sessions[$sessionId]) && $this->sessions[$sessionId]['revoked_at'] === null) {
            $this->sessions[$sessionId]['csrf_hash'] = $csrfHash;
            $this->sessions[$sessionId]['last_seen_at'] = $at;
        }
    }

    public function revokeSession(string $sessionId, string $reason, string $at): void
    {
        if (isset($this->sessions[$sessionId]) && $this->sessions[$sessionId]['revoked_at'] === null) {
            $this->sessions[$sessionId]['revoked_at'] = $at;
            $this->sessions[$sessionId]['revoke_reason'] = $reason;
        }
    }

    public function revokeUserSessions(string $userId, string $reason, string $at): void
    {
        foreach ($this->sessions as $id => $session) {
            if ($session['user_id'] === $userId) {
                $this->revokeSession($id, $reason, $at);
            }
        }
    }

    public function listInstances(?string $teamId, ?string $ownerUserId): array
    {
        return array_values(array_filter($this->instances, static function (array $instance) use ($teamId, $ownerUserId): bool {
            return ($teamId === null || $instance['team_id'] === $teamId)
                && ($ownerUserId === null || $instance['current_owner_user_id'] === $ownerUserId);
        }));
    }

    public function findInstance(string $id, bool $forUpdate = false): ?array
    {
        return $this->instances[$id] ?? null;
    }

    public function findInstanceByNormalizedFqdn(string $normalizedFqdn): ?array
    {
        foreach ($this->instances as $instance) {
            if (($instance['normalized_fqdn'] ?? null) === $normalizedFqdn) {
                return $instance;
            }
        }
        return null;
    }

    public function findInstanceBySitePathKey(string $sitePathKey): ?array
    {
        foreach ($this->instances as $instance) {
            if (($instance['site_path_key'] ?? null) === $sitePathKey) {
                return $instance;
            }
        }
        return null;
    }

    public function hasActiveDomainReservation(string $normalizedFqdn): bool
    {
        foreach ($this->domainReservations as $reservation) {
            if (($reservation['normalized_fqdn'] ?? null) === $normalizedFqdn
                && in_array($reservation['status'] ?? null, ['held', 'committed'], true)) {
                return true;
            }
        }
        return false;
    }

    public function updateInstanceOwner(string $instanceId, string $ownerId, int $expectedVersion, string $at): bool
    {
        if (!isset($this->instances[$instanceId]) || $this->instances[$instanceId]['version'] !== $expectedVersion) {
            return false;
        }
        $this->instances[$instanceId]['current_owner_user_id'] = $ownerId;
        $this->instances[$instanceId]['version']++;
        $this->instances[$instanceId]['updated_at'] = $at;
        return true;
    }

    public function insertOwnerHistory(array $history): void
    {
        $this->ownerHistory[] = $history;
    }

    public function findTemplateVersionAuthorization(string $templateVersionId): ?array
    {
        return $this->templateVersions[$templateVersionId] ?? null;
    }

    public function findTemplateBySlug(string $slug): ?array
    {
        foreach ($this->templates as $template) {
            if (($template['slug'] ?? null) === $slug) {
                return $template;
            }
        }
        return null;
    }

    public function findTemplateVersionByHash(string $sha256): ?array
    {
        foreach ($this->templateVersions as $version) {
            if (($version['zip_sha256'] ?? null) === $sha256) {
                return $version;
            }
        }
        return null;
    }

    public function findTemplateVersionByTemplateAndVersion(string $templateId, string $version): ?array
    {
        foreach ($this->templateVersions as $row) {
            if (($row['template_id'] ?? null) === $templateId && ($row['version'] ?? null) === $version) {
                return $row;
            }
        }
        return null;
    }

    public function insertTemplate(array $template): void
    {
        if ($this->findTemplateBySlug((string) $template['slug']) !== null) {
            throw new RuntimeException('duplicate template slug');
        }
        $this->templates[(string) $template['id']] = $template;
    }

    public function insertTemplateVersion(array $version): void
    {
        if ($this->findTemplateVersionByHash((string) $version['zip_sha256']) !== null
            || $this->findTemplateVersionByTemplateAndVersion((string) $version['template_id'], (string) $version['version']) !== null
        ) {
            throw new RuntimeException('duplicate template version');
        }
        $template = $this->templates[(string) $version['template_id']] ?? [];
        $this->templateVersions[(string) $version['id']] = $version + [
            'owner_team_id' => $template['owner_team_id'] ?? null,
            'template_status' => $template['status'] ?? null,
        ];
    }

    public function listTemplateVersions(?string $teamId): array
    {
        $rows = [];
        foreach ($this->templateVersions as $version) {
            if (($version['validation_status'] ?? null) !== 'valid') {
                continue;
            }
            if ($teamId !== null && !$this->hasTemplateGrant((string) $version['id'], $teamId)) {
                continue;
            }
            $template = $this->templates[(string) $version['template_id']] ?? null;
            if ($template === null || ($template['status'] ?? null) !== 'active') {
                continue;
            }
            $creator = $this->users[(string) $template['created_by_user_id']] ?? [];
            $uploader = $this->users[(string) $version['uploaded_by_user_id']] ?? [];
            $ownerTeam = $template['owner_team_id'] === null ? null : ($this->teams[(string) $template['owner_team_id']] ?? []);
            $rows[] = $version + [
                'template_id' => $template['id'],
                'slug' => $template['slug'],
                'template_name' => $template['name'],
                'description' => $template['description'],
                'owner_team_id' => $template['owner_team_id'],
                'owner_team_name' => $ownerTeam['name'] ?? null,
                'created_by_user_id' => $template['created_by_user_id'],
                'creator_name' => $creator['display_name'] ?? '',
                'template_status' => $template['status'],
                'template_created_at' => $template['created_at'],
                'version_id' => $version['id'],
                'uploader_name' => $uploader['display_name'] ?? '',
                'version_created_at' => $version['created_at'],
            ];
        }
        return $rows;
    }

    public function findTemplateVersionDetail(string $templateVersionId, ?string $teamId): ?array
    {
        $version = $this->templateVersions[$templateVersionId] ?? null;
        if ($version === null || ($version['validation_status'] ?? null) !== 'valid') {
            return null;
        }
        $template = $this->templates[(string) ($version['template_id'] ?? '')] ?? null;
        if ($template === null || ($template['status'] ?? null) !== 'active') {
            return null;
        }
        if ($teamId !== null && !$this->hasTemplateGrant($templateVersionId, $teamId)) {
            return null;
        }
        return $version + [
            'slug' => $template['slug'], 'template_name' => $template['name'],
            'template_status' => $template['status'], 'owner_team_id' => $template['owner_team_id'],
        ];
    }

    public function listTemplateGrants(string $templateVersionId): array
    {
        $rows = [];
        foreach ($this->templateGrants as $grant) {
            if (($grant['template_version_id'] ?? null) !== $templateVersionId) {
                continue;
            }
            $team = $this->teams[(string) $grant['team_id']] ?? [];
            $rows[] = [
                'team_id' => $grant['team_id'],
                'team_name' => $team['name'] ?? '',
                'status' => $grant['status'],
                'granted_at' => $grant['granted_at'],
                'revoked_at' => $grant['revoked_at'] ?? null,
            ];
        }
        return $rows;
    }

    public function hasTemplateGrant(string $templateVersionId, string $teamId): bool
    {
        $grant = $this->templateGrants[$templateVersionId . ':' . $teamId] ?? null;
        return is_array($grant) && ($grant['status'] ?? null) === 'active';
    }

    public function createTemplateGrant(array $grant): bool
    {
        $key = $grant['template_version_id'] . ':' . $grant['team_id'];
        if (isset($this->templateGrants[$key])) {
            return false;
        }
        $this->templateGrants[$key] = $grant + [
            'status' => 'active',
            'revoked_by_user_id' => null,
            'revoked_at' => null,
        ];
        return true;
    }

    public function grantTemplateVersion(array $grant): void
    {
        $key = $grant['template_version_id'] . ':' . $grant['team_id'];
        $this->templateGrants[$key] = $grant + [
            'status' => 'active',
            'revoked_by_user_id' => null,
            'revoked_at' => null,
        ];
    }

    public function revokeTemplateVersionGrant(
        string $templateVersionId,
        string $teamId,
        string $actorUserId,
        string $at,
    ): bool {
        $key = $templateVersionId . ':' . $teamId;
        if (!isset($this->templateGrants[$key]) || $this->templateGrants[$key]['status'] !== 'active') {
            return false;
        }
        $this->templateGrants[$key]['status'] = 'revoked';
        $this->templateGrants[$key]['revoked_by_user_id'] = $actorUserId;
        $this->templateGrants[$key]['revoked_at'] = $at;
        return true;
    }

    public function listCloudflareConnections(): array
    {
        return array_values(array_map(static fn (array $row): array => array_diff_key(
            $row,
            ['token_ciphertext' => true, 'secret_key_version' => true],
        ), $this->cloudflareConnections));
    }

    public function findCloudflareConnection(string $id): ?array
    {
        return $this->cloudflareConnections[$id] ?? null;
    }

    public function findCloudflareConnectionByLabel(string $label): ?array
    {
        foreach ($this->cloudflareConnections as $row) {
            if (($row['label'] ?? null) === $label) {
                return $row;
            }
        }
        return null;
    }

    public function insertCloudflareConnection(array $connection): void
    {
        if ($this->findCloudflareConnectionByLabel((string) $connection['label']) !== null) {
            throw new RuntimeException('duplicate cloudflare connection');
        }
        $this->cloudflareConnections[(string) $connection['id']] = $connection;
    }

    public function updateCloudflareConnection(string $id, array $changes, int $expectedVersion): bool
    {
        if (isset($changes['label'])) {
            $other = $this->findCloudflareConnectionByLabel((string) $changes['label']);
            if ($other !== null && ($other['id'] ?? null) !== $id) {
                throw new RuntimeException('duplicate cloudflare connection');
            }
        }
        if (!isset($this->cloudflareConnections[$id])
            || (int) ($this->cloudflareConnections[$id]['version'] ?? 1) !== $expectedVersion) {
            return false;
        }
        $changes['version'] = $expectedVersion + 1;
        $this->cloudflareConnections[$id] = array_replace($this->cloudflareConnections[$id] ?? [], $changes);
        return true;
    }

    public function invalidateCloudflareZonesForConnection(string $connectionId, string $resultJson, string $at): int
    {
        $count = 0;
        foreach ($this->cloudflareZones as &$zone) {
            if (($zone['connection_id'] ?? null) !== $connectionId) {
                continue;
            }
            $zone['verification_status'] = 'unverified';
            $zone['verified_credential_context_version'] = null;
            $zone['cloudflare_status'] = null;
            $zone['status'] = ($zone['admin_state'] ?? null) === 'disabled' ? 'disabled' : 'unverified';
            $zone['last_synced_at'] = null;
            $zone['last_verification_result_json'] = $resultJson;
            $zone['updated_at'] = $at;
            $zone['version'] = (int) ($zone['version'] ?? 1) + 1;
            $count++;
        }
        unset($zone);
        return $count;
    }

    public function listCloudflareZones(): array
    {
        $rows = [];
        foreach ($this->cloudflareZones as $zone) {
            $connection = $this->cloudflareConnections[(string) $zone['connection_id']] ?? [];
            $rows[] = $zone + [
                'connection_label' => $connection['label'] ?? null,
                'connection_status' => $connection['status'] ?? null,
                'connection_admin_state' => $connection['admin_state'] ?? null,
                'connection_verification_status' => $connection['verification_status'] ?? null,
                'connection_credential_context_version' => $connection['credential_context_version'] ?? null,
            ];
        }
        return $rows;
    }

    public function findCloudflareZone(string $id): ?array
    {
        $zone = $this->cloudflareZones[$id] ?? null;
        if ($zone === null) {
            return null;
        }
        $connection = $this->cloudflareConnections[(string) $zone['connection_id']] ?? [];
        return $zone + [
            'connection_label' => $connection['label'] ?? null,
            'connection_status' => $connection['status'] ?? null,
            'connection_admin_state' => $connection['admin_state'] ?? null,
            'connection_verification_status' => $connection['verification_status'] ?? null,
            'connection_credential_context_version' => $connection['credential_context_version'] ?? null,
        ];
    }

    public function findCloudflareZoneByName(string $normalizedName): ?array
    {
        foreach ($this->cloudflareZones as $row) {
            if (($row['normalized_zone_name'] ?? null) === $normalizedName) {
                return $row;
            }
        }
        return null;
    }

    public function findCloudflareZoneByExternal(string $connectionId, string $externalZoneId): ?array
    {
        foreach ($this->cloudflareZones as $row) {
            if (($row['connection_id'] ?? null) === $connectionId
                && ($row['external_zone_id'] ?? null) === $externalZoneId) {
                return $row;
            }
        }
        return null;
    }

    public function insertCloudflareZone(array $zone): void
    {
        if (!isset($this->cloudflareConnections[(string) $zone['connection_id']])
            || $this->findCloudflareZoneByName((string) $zone['normalized_zone_name']) !== null
        ) {
            throw new RuntimeException('invalid or duplicate cloudflare zone');
        }
        if ($this->findCloudflareZoneByExternal((string) $zone['connection_id'], (string) $zone['external_zone_id']) !== null) {
            throw new RuntimeException('duplicate external zone');
        }
        $this->cloudflareZones[(string) $zone['id']] = $zone;
    }

    public function updateCloudflareZone(string $id, array $changes, int $expectedVersion): bool
    {
        if (!isset($this->cloudflareZones[$id])
            || (int) ($this->cloudflareZones[$id]['version'] ?? 1) !== $expectedVersion) {
            return false;
        }
        $changes['version'] = $expectedVersion + 1;
        $this->cloudflareZones[$id] = array_replace($this->cloudflareZones[$id], $changes);
        return true;
    }

    public function listPrimaryDomains(?string $teamId, bool $usableOnly): array
    {
        $rows = [];
        foreach ($this->primaryDomains as $domain) {
            if ($teamId !== null && !$this->hasDomainGrant((string) $domain['id'], $teamId)) {
                continue;
            }
            $zone = $this->findCloudflareZone((string) $domain['zone_id']);
            if ($zone === null) {
                continue;
            }
            if ($usableOnly && (($domain['status'] ?? null) !== 'active'
                || ($zone['admin_state'] ?? null) !== 'enabled'
                || ($zone['verification_status'] ?? null) !== 'valid'
                || ($zone['verified_credential_context_version'] ?? null) !== ($zone['connection_credential_context_version'] ?? null)
                || ($zone['connection_admin_state'] ?? null) !== 'enabled'
                || ($zone['connection_verification_status'] ?? null) !== 'valid')) {
                continue;
            }
            $rows[] = [
                'id' => $domain['id'], 'zone_id' => $domain['zone_id'], 'domain_name' => $domain['domain_name'],
                'normalized_domain_name' => $domain['normalized_domain_name'], 'domain_status' => $domain['status'],
                'created_at' => $domain['created_at'], 'updated_at' => $domain['updated_at'],
                'zone_name' => $zone['zone_name'], 'normalized_zone_name' => $zone['normalized_zone_name'],
                'zone_status' => $zone['status'], 'connection_id' => $zone['connection_id'],
                'connection_label' => $zone['connection_label'], 'connection_status' => $zone['connection_status'],
                'zone_admin_state' => $zone['admin_state'], 'zone_verification_status' => $zone['verification_status'],
                'zone_verified_credential_context_version' => $zone['verified_credential_context_version'] ?? null,
                'zone_cloudflare_status' => $zone['cloudflare_status'] ?? null,
                'zone_verification_result_json' => $zone['last_verification_result_json'] ?? null,
                'connection_admin_state' => $zone['connection_admin_state'],
                'connection_verification_status' => $zone['connection_verification_status'],
                'connection_credential_context_version' => $zone['connection_credential_context_version'] ?? null,
            ];
        }
        return $rows;
    }

    public function findPrimaryDomain(string $id): ?array
    {
        $domain = $this->primaryDomains[$id] ?? null;
        if ($domain === null) {
            return null;
        }
        $zone = $this->findCloudflareZone((string) $domain['zone_id']);
        return $zone === null ? null : $domain + [
            'zone_status' => $zone['status'], 'normalized_zone_name' => $zone['normalized_zone_name'],
            'connection_id' => $zone['connection_id'], 'connection_status' => $zone['connection_status'],
            'zone_admin_state' => $zone['admin_state'], 'zone_verification_status' => $zone['verification_status'],
            'zone_verified_credential_context_version' => $zone['verified_credential_context_version'] ?? null,
            'zone_cloudflare_status' => $zone['cloudflare_status'] ?? null,
            'connection_admin_state' => $zone['connection_admin_state'],
            'connection_verification_status' => $zone['connection_verification_status'],
            'connection_credential_context_version' => $zone['connection_credential_context_version'] ?? null,
        ];
    }

    public function findPrimaryDomainByName(string $normalizedName): ?array
    {
        foreach ($this->primaryDomains as $row) {
            if (($row['normalized_domain_name'] ?? null) === $normalizedName) {
                return $row;
            }
        }
        return null;
    }

    public function insertPrimaryDomain(array $domain): void
    {
        if (!isset($this->cloudflareZones[(string) $domain['zone_id']])
            || $this->findPrimaryDomainByName((string) $domain['normalized_domain_name']) !== null) {
            throw new RuntimeException('invalid or duplicate primary domain');
        }
        $this->primaryDomains[(string) $domain['id']] = $domain;
    }

    public function updatePrimaryDomainStatus(string $id, string $status, string $at): void
    {
        $this->primaryDomains[$id]['status'] = $status;
        $this->primaryDomains[$id]['updated_at'] = $at;
    }

    public function listDomainGrants(string $primaryDomainId): array
    {
        $rows = [];
        foreach ($this->domainGrants as $grant) {
            if (!is_array($grant) || ($grant['primary_domain_id'] ?? null) !== $primaryDomainId) {
                continue;
            }
            $team = $this->teams[(string) $grant['team_id']] ?? [];
            $rows[] = [
                'team_id' => $grant['team_id'], 'team_name' => $team['name'] ?? '', 'status' => $grant['status'],
                'granted_at' => $grant['granted_at'], 'revoked_at' => $grant['revoked_at'] ?? null,
            ];
        }
        return $rows;
    }

    public function hasDomainGrant(string $primaryDomainId, string $teamId): bool
    {
        $grant = $this->domainGrants[$primaryDomainId . ':' . $teamId] ?? null;
        return $grant === true || (is_array($grant) && ($grant['status'] ?? null) === 'active');
    }

    public function grantPrimaryDomain(array $grant): void
    {
        $key = $grant['primary_domain_id'] . ':' . $grant['team_id'];
        $this->domainGrants[$key] = $grant + ['status' => 'active', 'revoked_at' => null];
    }

    public function revokePrimaryDomainGrant(string $primaryDomainId, string $teamId, string $at): bool
    {
        $key = $primaryDomainId . ':' . $teamId;
        $grant = $this->domainGrants[$key] ?? null;
        if (!is_array($grant) || ($grant['status'] ?? null) !== 'active') {
            return false;
        }
        $this->domainGrants[$key]['status'] = 'revoked';
        $this->domainGrants[$key]['revoked_at'] = $at;
        return true;
    }

    public function findBaotaConfig(): ?array
    {
        return $this->baotaConfig;
    }

    public function insertBaotaConfig(array $config): void
    {
        if ($this->baotaConfig !== null) {
            throw new RuntimeException('baota singleton conflict');
        }
        $this->baotaConfig = $config;
    }

    public function updateBaotaConfig(string $id, array $changes, int $expectedVersion): bool
    {
        if ($this->baotaConfig === null || ($this->baotaConfig['id'] ?? null) !== $id
            || (int) ($this->baotaConfig['version'] ?? 0) !== $expectedVersion) {
            return false;
        }
        $this->baotaConfig = array_replace($this->baotaConfig, $changes);
        $this->baotaConfig['version'] = $expectedVersion + 1;
        return true;
    }

    public function insertAudit(array $audit): void
    {
        $this->audits[] = $audit;
    }
}
