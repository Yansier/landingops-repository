<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Service;

use LandingOps\ControlPlane\Audit\AuditLogger;
use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\PasswordHasher;
use LandingOps\ControlPlane\Auth\Principal;
use LandingOps\ControlPlane\Auth\Role;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Repository\ControlPlaneRepository;
use LandingOps\ControlPlane\Support\IdGenerator;
use LandingOps\ControlPlane\Support\UtcClock;

final class AccountService
{
    public function __construct(
        private readonly ControlPlaneRepository $repository,
        private readonly AuthorizationService $authorization = new AuthorizationService(),
        private readonly PasswordHasher $passwords = new PasswordHasher(),
        private readonly IdGenerator $ids = new IdGenerator(),
        private readonly UtcClock $clock = new UtcClock(),
        ?AuditLogger $audit = null,
    ) {
        $this->audit = $audit ?? new AuditLogger($repository);
    }

    private readonly AuditLogger $audit;

    /** @return array<string,mixed> */
    public function createTeam(Principal $actor, string $name, string $slug, string $requestId): array
    {
        $this->authorization->assertSuperAdmin($actor);
        $name = trim($name);
        $slug = strtolower(trim($slug));
        if ($name === '' || preg_match('/^[a-z0-9][a-z0-9-]{1,78}[a-z0-9]$/D', $slug) !== 1) {
            throw new ApiException(422, 'team_invalid', '团队名称或 slug 无效。');
        }
        $now = $this->clock->sql();
        $team = [
            'id' => $this->ids->generate(),
            'name' => $name,
            'slug' => $slug,
            'status' => 'active',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->repository->transaction(function () use ($team, $actor, $requestId): void {
            $this->repository->insertTeam($team);
            $this->audit->record($actor, 'team.create', 'team', $team['id'], $requestId);
        });
        return $team;
    }

    /** @return list<array<string,mixed>> */
    public function listTeams(Principal $actor): array
    {
        $this->authorization->assertSuperAdmin($actor);
        return $this->repository->listTeams();
    }

    /** @return array<string,mixed> */
    public function createUser(
        Principal $actor,
        string $email,
        string $displayName,
        string $role,
        ?string $teamId,
        string $password,
        string $requestId,
    ): array {
        $normalized = strtolower(trim($email));
        $displayName = trim($displayName);
        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false || $displayName === '') {
            throw new ApiException(422, 'user_invalid', '邮箱或显示名称无效。');
        }
        if ($actor->role === Role::TEAM_ADMIN) {
            $teamId = $actor->teamId;
            $role = Role::STAFF;
        }
        $this->authorization->assertCanCreateUser($actor, $role, $teamId);
        if ($this->repository->findUserByEmail($normalized) !== null) {
            throw new ApiException(409, 'email_conflict', '邮箱已存在。');
        }
        $now = $this->clock->sql();
        $user = [
            'id' => $this->ids->generate(),
            'team_id' => $teamId,
            'email' => trim($email),
            'normalized_email' => $normalized,
            'display_name' => $displayName,
            'password_hash' => $this->passwords->hash($password),
            'role' => $role,
            'status' => 'active',
            'version' => 1,
            'password_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->repository->transaction(function () use ($user, $actor, $requestId): void {
            $this->repository->insertUser($user);
            $this->audit->record($actor, 'user.create', 'user', $user['id'], $requestId, [
                'role' => $user['role'],
                'team_id' => $user['team_id'],
            ]);
        });
        return self::safeUser($user);
    }

    /** @return list<array<string,mixed>> */
    public function listUsers(Principal $actor): array
    {
        $this->authorization->assertActive($actor);
        if ($actor->role === Role::STAFF) {
            throw new ApiException(403, 'forbidden', '员工不能查询用户列表，请使用当前用户接口。');
        }
        $teamId = $actor->role === Role::SUPER_ADMIN ? null : $actor->teamId;
        return array_map(self::safeUser(...), $this->repository->listUsers($teamId));
    }

    public function disableUser(Principal $actor, string $userId, string $requestId): void
    {
        if ($actor->id === $userId) {
            throw new ApiException(422, 'self_disable_forbidden', '不能通过此接口停用当前账号。');
        }
        $target = $this->repository->findUserById($userId);
        if ($target === null) {
            throw new ApiException(404, 'resource_not_found', '用户不存在或不可访问。');
        }
        $this->authorization->assertCanManageUser($actor, $target);
        $now = $this->clock->sql();
        $this->repository->transaction(function () use ($target, $actor, $requestId, $now): void {
            $this->repository->disableUser((string) $target['id'], $now);
            $this->repository->revokeUserSessions((string) $target['id'], 'account_disabled', $now);
            $this->audit->record($actor, 'user.disable', 'user', (string) $target['id'], $requestId);
        });
    }

    /** @return array<string,mixed> */
    public function updateOwnProfile(Principal $actor, string $displayName, string $requestId): array
    {
        $this->authorization->assertActive($actor);
        $displayName = trim($displayName);
        if ($displayName === '' || strlen($displayName) > 120) {
            throw new ApiException(422, 'display_name_invalid', '显示名称无效。');
        }
        $this->repository->updateUserProfile($actor->id, $displayName, $this->clock->sql());
        $this->audit->record($actor, 'user.profile_update', 'user', $actor->id, $requestId);
        $row = $this->repository->findUserById($actor->id);
        return $row === null ? [] : self::safeUser($row);
    }

    public function changeOwnPassword(
        Principal $actor,
        string $currentPassword,
        string $newPassword,
        string $requestId,
    ): void {
        $row = $this->repository->findUserById($actor->id);
        if ($row === null || !$this->passwords->verify($currentPassword, (string) $row['password_hash'])) {
            throw new ApiException(422, 'current_password_invalid', '当前密码错误。');
        }
        $hash = $this->passwords->hash($newPassword);
        $now = $this->clock->sql();
        $this->repository->transaction(function () use ($actor, $hash, $now, $requestId): void {
            $this->repository->updatePassword($actor->id, $hash, $now);
            $this->repository->revokeUserSessions($actor->id, 'password_changed', $now);
            $this->audit->record($actor, 'user.password_change', 'user', $actor->id, $requestId);
        });
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    public static function safeUser(array $user): array
    {
        return [
            'id' => $user['id'],
            'team_id' => $user['team_id'] ?? null,
            'email' => $user['email'],
            'display_name' => $user['display_name'],
            'role' => $user['role'],
            'status' => $user['status'],
            'version' => (int) ($user['version'] ?? 1),
            'last_login_at' => $user['last_login_at'] ?? null,
            'created_at' => $user['created_at'] ?? null,
        ];
    }
}
