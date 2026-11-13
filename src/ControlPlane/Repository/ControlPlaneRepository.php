<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Repository;

interface ControlPlaneRepository
{
    public function transaction(callable $callback): mixed;
    public function countUsersByRole(string $role): int;
    /** @return array<string,mixed>|null */
    public function findUserByEmail(string $normalizedEmail): ?array;
    /** @return array<string,mixed>|null */
    public function findUserById(string $id): ?array;
    /** @param array<string,mixed> $user */
    public function insertUser(array $user): void;
    /** @param array<string,mixed> $team */
    public function insertTeam(array $team): void;
    /** @return array<string,mixed>|null */
    public function findTeamById(string $id): ?array;
    /** @return list<array<string,mixed>> */
    public function listTeams(): array;
    /** @return list<array<string,mixed>> */
    public function listUsers(?string $teamId): array;
    public function updateLastLogin(string $userId, string $at): void;
    public function updateUserProfile(string $userId, string $displayName, string $at): void;
    public function updatePassword(string $userId, string $hash, string $at): void;
    public function disableUser(string $userId, string $at): void;
    /** @param array<string,mixed> $session */
    public function insertSession(array $session): void;
    /** @return array<string,mixed>|null */
    public function findSessionByHash(string $tokenHash): ?array;
    public function updateSessionCsrf(string $sessionId, string $csrfHash, string $at): void;
    public function revokeSession(string $sessionId, string $reason, string $at): void;
    public function revokeUserSessions(string $userId, string $reason, string $at): void;
    /** @return list<array<string,mixed>> */
    public function listInstances(?string $teamId, ?string $ownerUserId): array;
    /** @return array<string,mixed>|null */
    public function findInstance(string $id, bool $forUpdate = false): ?array;
    public function updateInstanceOwner(string $instanceId, string $ownerId, int $expectedVersion, string $at): bool;
    /** @param array<string,mixed> $history */
    public function insertOwnerHistory(array $history): void;
    /** @return array<string,mixed>|null */
    public function findTemplateVersionAuthorization(string $templateVersionId): ?array;
    /** @return array<string,mixed>|null */
    public function findTemplateBySlug(string $slug): ?array;
    /** @return array<string,mixed>|null */
    public function findTemplateVersionByHash(string $sha256): ?array;
    /** @return array<string,mixed>|null */
    public function findTemplateVersionByTemplateAndVersion(string $templateId, string $version): ?array;
    /** @param array<string,mixed> $template */
    public function insertTemplate(array $template): void;
    /** @param array<string,mixed> $version */
    public function insertTemplateVersion(array $version): void;
    /** @return list<array<string,mixed>> */
    public function listTemplateVersions(?string $teamId): array;
    /** @return array<string,mixed>|null */
    public function findTemplateVersionDetail(string $templateVersionId, ?string $teamId): ?array;
    /** @return list<array<string,mixed>> */
    public function listTemplateGrants(string $templateVersionId): array;
    public function hasTemplateGrant(string $templateVersionId, string $teamId): bool;
    /** @param array<string,mixed> $grant */
    public function createTemplateGrant(array $grant): bool;
    /** @param array<string,mixed> $grant */
    public function grantTemplateVersion(array $grant): void;
    public function revokeTemplateVersionGrant(
        string $templateVersionId,
        string $teamId,
        string $actorUserId,
        string $at,
    ): bool;
    /** @return list<array<string,mixed>> */
    public function listCloudflareConnections(): array;
    /** @return array<string,mixed>|null */
    public function findCloudflareConnection(string $id): ?array;
    /** @return array<string,mixed>|null */
    public function findCloudflareConnectionByLabel(string $label): ?array;
    /** @param array<string,mixed> $connection */
    public function insertCloudflareConnection(array $connection): void;
    /** @param array<string,mixed> $changes */
    public function updateCloudflareConnection(string $id, array $changes, int $expectedVersion): bool;
    public function invalidateCloudflareZonesForConnection(string $connectionId, string $resultJson, string $at): int;
    /** @return list<array<string,mixed>> */
    public function listCloudflareZones(): array;
    /** @return array<string,mixed>|null */
    public function findCloudflareZone(string $id): ?array;
    /** @return array<string,mixed>|null */
    public function findCloudflareZoneByName(string $normalizedName): ?array;
    /** @return array<string,mixed>|null */
    public function findCloudflareZoneByExternal(string $connectionId, string $externalZoneId): ?array;
    /** @param array<string,mixed> $zone */
    public function insertCloudflareZone(array $zone): void;
    /** @param array<string,mixed> $changes */
    public function updateCloudflareZone(string $id, array $changes, int $expectedVersion): bool;
    /** @return list<array<string,mixed>> */
    public function listPrimaryDomains(?string $teamId, bool $usableOnly): array;
    /** @return array<string,mixed>|null */
    public function findPrimaryDomain(string $id): ?array;
    /** @return array<string,mixed>|null */
    public function findPrimaryDomainByName(string $normalizedName): ?array;
    /** @param array<string,mixed> $domain */
    public function insertPrimaryDomain(array $domain): void;
    public function updatePrimaryDomainStatus(string $id, string $status, string $at): void;
    /** @return list<array<string,mixed>> */
    public function listDomainGrants(string $primaryDomainId): array;
    public function hasDomainGrant(string $primaryDomainId, string $teamId): bool;
    /** @param array<string,mixed> $grant */
    public function grantPrimaryDomain(array $grant): void;
    public function revokePrimaryDomainGrant(string $primaryDomainId, string $teamId, string $at): bool;
    /** @param array<string,mixed> $audit */
    public function insertAudit(array $audit): void;
}
