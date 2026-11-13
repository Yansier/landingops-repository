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
    public function revokeSession(string $sessionId, string $reason, string $at): void;
    public function revokeUserSessions(string $userId, string $reason, string $at): void;
    /** @return list<array<string,mixed>> */
    public function listInstances(?string $teamId, ?string $ownerUserId): array;
    /** @return array<string,mixed>|null */
    public function findInstance(string $id, bool $forUpdate = false): ?array;
    public function updateInstanceOwner(string $instanceId, string $ownerId, int $expectedVersion, string $at): bool;
    /** @param array<string,mixed> $history */
    public function insertOwnerHistory(array $history): void;
    public function hasTemplateGrant(string $templateVersionId, string $teamId): bool;
    public function hasDomainGrant(string $primaryDomainId, string $teamId): bool;
    /** @param array<string,mixed> $audit */
    public function insertAudit(array $audit): void;
}
