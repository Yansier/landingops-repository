<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Service;

use LandingOps\ControlPlane\Audit\AuditLogger;
use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\Principal;
use LandingOps\ControlPlane\Auth\Role;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Repository\ControlPlaneRepository;
use LandingOps\ControlPlane\Support\IdGenerator;
use LandingOps\ControlPlane\Support\UtcClock;

/**
 * 管理模板使用授权的生命周期；模板归属只从数据库读取，不能由客户端声明。
 */
final class TemplateGrantService
{
    public function __construct(
        private readonly ControlPlaneRepository $repository,
        private readonly AuthorizationService $authorization = new AuthorizationService(),
        private readonly IdGenerator $ids = new IdGenerator(),
        private readonly UtcClock $clock = new UtcClock(),
        ?AuditLogger $audit = null,
    ) {
        $this->audit = $audit ?? new AuditLogger($repository);
    }

    private readonly AuditLogger $audit;

    /**
     * 由未来的上传编排在模板版本落库后调用。所属团队和上传者均由服务端记录核实。
     */
    public function grantUploaderTeam(Principal $uploader, string $templateVersionId, string $requestId): void
    {
        $this->authorization->assertActive($uploader);
        $version = $this->version($templateVersionId);
        if ($uploader->role !== Role::TEAM_ADMIN
            || $uploader->teamId === null
            || ($version['owner_team_id'] ?? null) !== $uploader->teamId
            || ($version['uploaded_by_user_id'] ?? null) !== $uploader->id
        ) {
            throw new ApiException(403, 'template_grant_forbidden', '不能为该模板版本建立上传团队授权。');
        }
        $now = $this->clock->sql();
        $this->repository->transaction(function () use ($uploader, $templateVersionId, $requestId, $now): void {
            $grant = [
                'id' => $this->ids->generate(),
                'template_version_id' => $templateVersionId,
                'team_id' => $uploader->teamId,
                'granted_by_user_id' => $uploader->id,
                'granted_at' => $now,
            ];
            $created = $this->repository->createTemplateGrant($grant);
            if (!$created && !$this->repository->hasTemplateGrant($templateVersionId, (string) $uploader->teamId)) {
                throw new ApiException(409, 'template_grant_revoked', '该团队的模板授权已由总管理员撤销。');
            }
            if ($created) {
                $this->audit->record(
                    $uploader,
                    'template.upload_default_grant',
                    'template_version',
                    $templateVersionId,
                    $requestId,
                    ['team_id' => $uploader->teamId],
                );
            }
        });
    }

    public function grantBySuperAdmin(
        Principal $actor,
        string $templateVersionId,
        string $teamId,
        string $requestId,
    ): void {
        $this->authorization->assertSuperAdmin($actor);
        $this->version($templateVersionId);
        $team = $this->repository->findTeamById($teamId);
        if ($team === null || ($team['status'] ?? null) !== 'active') {
            throw new ApiException(404, 'team_not_found', '授权目标团队不存在。');
        }
        $this->grant($actor, $templateVersionId, $teamId, $requestId);
    }

    public function revokeBySuperAdmin(
        Principal $actor,
        string $templateVersionId,
        string $teamId,
        string $requestId,
    ): void {
        $this->authorization->assertSuperAdmin($actor);
        $this->version($templateVersionId);
        if ($this->repository->findTeamById($teamId) === null) {
            throw new ApiException(404, 'team_not_found', '授权目标团队不存在。');
        }
        $now = $this->clock->sql();
        $this->repository->transaction(function () use ($actor, $templateVersionId, $teamId, $requestId, $now): void {
            if (!$this->repository->revokeTemplateVersionGrant(
                $templateVersionId,
                $teamId,
                $actor->id,
                $now,
            )) {
                throw new ApiException(409, 'template_grant_not_active', '模板授权不存在或已经撤销。');
            }
            $this->audit->record(
                $actor,
                'template.grant_revoke',
                'template_version',
                $templateVersionId,
                $requestId,
                ['team_id' => $teamId],
            );
        });
    }

    /** @return array<string,mixed> */
    private function version(string $templateVersionId): array
    {
        $version = $this->repository->findTemplateVersionAuthorization($templateVersionId);
        if ($version === null
            || ($version['validation_status'] ?? null) !== 'valid'
            || ($version['template_status'] ?? null) !== 'active'
        ) {
            throw new ApiException(404, 'template_not_available', '模板版本不存在或未授权。');
        }
        return $version;
    }

    private function grant(
        Principal $actor,
        string $templateVersionId,
        string $teamId,
        string $requestId,
    ): void {
        $now = $this->clock->sql();
        $this->repository->transaction(function () use (
            $actor,
            $templateVersionId,
            $teamId,
            $requestId,
            $now,
        ): void {
            $this->repository->grantTemplateVersion([
                'id' => $this->ids->generate(),
                'template_version_id' => $templateVersionId,
                'team_id' => $teamId,
                'granted_by_user_id' => $actor->id,
                'granted_at' => $now,
            ]);
            $this->audit->record(
                $actor,
                'template.grant_set',
                'template_version',
                $templateVersionId,
                $requestId,
                ['team_id' => $teamId],
            );
        });
    }
}
