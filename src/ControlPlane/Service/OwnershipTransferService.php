<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Service;

use LandingOps\ControlPlane\Audit\AuditLogger;
use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\Principal;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Repository\ControlPlaneRepository;
use LandingOps\ControlPlane\Support\IdGenerator;
use LandingOps\ControlPlane\Support\UtcClock;

final class OwnershipTransferService
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

    /** @return array<string,mixed> */
    public function transfer(
        Principal $actor,
        string $instanceId,
        string $toUserId,
        int $expectedVersion,
        string $reason,
        string $requestId,
    ): array {
        $reason = trim($reason);
        if ($expectedVersion < 1 || $reason === '' || strlen($reason) > 500) {
            throw new ApiException(422, 'owner_transfer_invalid', '转交版本或原因无效。');
        }
        return $this->repository->transaction(function () use (
            $actor,
            $instanceId,
            $toUserId,
            $expectedVersion,
            $reason,
            $requestId,
        ): array {
            $instance = $this->repository->findInstance($instanceId, true);
            if ($instance === null) {
                throw new ApiException(404, 'resource_not_found', '实例不存在或不可访问。');
            }
            $target = $this->repository->findUserById($toUserId);
            if ($target === null) {
                throw new ApiException(422, 'owner_target_invalid', '新负责人无效。');
            }
            $this->authorization->assertCanTransfer($actor, $instance, $target);
            $now = $this->clock->sql();
            if (!$this->repository->updateInstanceOwner($instanceId, $toUserId, $expectedVersion, $now)) {
                throw new ApiException(409, 'instance_version_conflict', '实例已被其他操作更新。');
            }
            $this->repository->insertOwnerHistory([
                'id' => $this->ids->generate(),
                'instance_id' => $instanceId,
                'from_user_id' => $instance['current_owner_user_id'] ?? null,
                'to_user_id' => $toUserId,
                'changed_by_user_id' => $actor->id,
                'reason' => $reason,
                'created_at' => $now,
            ]);
            $this->audit->record($actor, 'instance.owner_transfer', 'instance', $instanceId, $requestId, [
                'from_user_id' => $instance['current_owner_user_id'] ?? null,
                'to_user_id' => $toUserId,
            ]);
            $updated = $this->repository->findInstance($instanceId);
            return $updated ?? [];
        });
    }
}
