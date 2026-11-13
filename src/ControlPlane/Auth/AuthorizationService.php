<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Auth;

use LandingOps\ControlPlane\Exception\ApiException;

final class AuthorizationService
{
    public function assertActive(Principal $actor): void
    {
        if ($actor->status !== 'active') {
            throw new ApiException(401, 'account_disabled', '账号已停用。');
        }
    }

    public function assertSuperAdmin(Principal $actor): void
    {
        $this->assertActive($actor);
        if ($actor->role !== Role::SUPER_ADMIN) {
            throw new ApiException(403, 'forbidden', '没有平台级管理权限。');
        }
    }

    public function assertCanCreateUser(Principal $actor, string $role, ?string $teamId): void
    {
        $this->assertActive($actor);
        if (!Role::valid($role)) {
            throw new ApiException(422, 'role_invalid', '角色无效。');
        }
        if ($actor->role === Role::SUPER_ADMIN) {
            if (($role === Role::SUPER_ADMIN) !== ($teamId === null)) {
                throw new ApiException(422, 'role_team_mismatch', '角色与团队归属不一致。');
            }
            return;
        }
        if ($actor->role !== Role::TEAM_ADMIN
            || $role !== Role::STAFF
            || $actor->teamId === null
            || $teamId !== $actor->teamId
        ) {
            throw new ApiException(403, 'forbidden', '团队管理员只能创建本团队员工。');
        }
    }

    /** @param array<string,mixed> $target */
    public function assertCanManageUser(Principal $actor, array $target): void
    {
        $this->assertActive($actor);
        if ($actor->role === Role::SUPER_ADMIN) {
            return;
        }
        if ($actor->role !== Role::TEAM_ADMIN
            || ($target['role'] ?? null) !== Role::STAFF
            || $actor->teamId === null
            || ($target['team_id'] ?? null) !== $actor->teamId
        ) {
            throw new ApiException(404, 'resource_not_found', '用户不存在或不可访问。');
        }
    }

    /** @param array<string,mixed> $instance */
    public function assertInstanceAccess(Principal $actor, array $instance): void
    {
        $this->assertActive($actor);
        if ($actor->role === Role::SUPER_ADMIN) {
            return;
        }
        if ($actor->teamId === null || ($instance['team_id'] ?? null) !== $actor->teamId) {
            throw new ApiException(404, 'resource_not_found', '实例不存在或不可访问。');
        }
        if ($actor->role === Role::TEAM_ADMIN) {
            return;
        }
        if ($actor->role !== Role::STAFF || ($instance['current_owner_user_id'] ?? null) !== $actor->id) {
            throw new ApiException(404, 'resource_not_found', '实例不存在或不可访问。');
        }
    }

    /** @param array<string,mixed> $instance @param array<string,mixed> $target */
    public function assertCanTransfer(Principal $actor, array $instance, array $target): void
    {
        $this->assertInstanceAccess($actor, $instance);
        if (!in_array($actor->role, [Role::SUPER_ADMIN, Role::TEAM_ADMIN], true)) {
            throw new ApiException(403, 'owner_transfer_forbidden', '员工不能转交负责人。');
        }
        if (($target['status'] ?? null) !== 'active'
            || ($target['team_id'] ?? null) !== ($instance['team_id'] ?? null)
            || !in_array($target['role'] ?? null, [Role::TEAM_ADMIN, Role::STAFF], true)
        ) {
            throw new ApiException(422, 'owner_target_invalid', '新负责人必须是实例同团队的启用用户。');
        }
    }

    public function assertTemplateGrant(
        Principal $actor,
        string $teamId,
        ?string $ownerTeamId,
        bool $hasActiveGrant,
    ): void {
        $this->assertActive($actor);
        if ($actor->role === Role::SUPER_ADMIN) {
            return;
        }
        if ($actor->teamId !== $teamId || ($ownerTeamId !== $teamId && !$hasActiveGrant)) {
            throw new ApiException(404, 'template_not_available', '模板版本不存在或未授权。');
        }
    }

    public function assertDomainGrant(Principal $actor, string $teamId, bool $hasActiveGrant): void
    {
        $this->assertActive($actor);
        if ($actor->role !== Role::SUPER_ADMIN
            && ($actor->teamId !== $teamId || !$hasActiveGrant)
        ) {
            throw new ApiException(404, 'domain_not_available', '主域名不存在或未授权。');
        }
    }

    /** @return array{team_id:?string,owner_user_id:?string} */
    public function instanceScope(Principal $actor): array
    {
        $this->assertActive($actor);
        return match ($actor->role) {
            Role::SUPER_ADMIN => ['team_id' => null, 'owner_user_id' => null],
            Role::TEAM_ADMIN => ['team_id' => $actor->teamId, 'owner_user_id' => null],
            Role::STAFF => ['team_id' => $actor->teamId, 'owner_user_id' => $actor->id],
            default => throw new ApiException(403, 'forbidden', '角色无权查询实例。'),
        };
    }
}
