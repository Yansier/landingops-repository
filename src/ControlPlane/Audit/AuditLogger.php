<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Audit;

use LandingOps\ControlPlane\Auth\Principal;
use LandingOps\ControlPlane\Repository\ControlPlaneRepository;
use LandingOps\ControlPlane\Support\IdGenerator;
use LandingOps\ControlPlane\Support\UtcClock;

final class AuditLogger
{
    public function __construct(
        private readonly ControlPlaneRepository $repository,
        private readonly IdGenerator $ids = new IdGenerator(),
        private readonly UtcClock $clock = new UtcClock(),
    ) {
    }

    /** @param array<string,mixed> $metadata */
    public function record(
        ?Principal $actor,
        string $action,
        string $resourceType,
        string $resourceId,
        string $requestId,
        array $metadata = [],
        ?string $beforeDigest = null,
        ?string $afterDigest = null,
    ): void {
        $metadata = self::sanitize($metadata);
        $this->repository->insertAudit([
            'id' => $this->ids->generate(),
            'actor_user_id' => $actor?->id,
            'actor_type' => $actor === null ? 'system' : 'user',
            'team_id' => $actor?->teamId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'request_id' => $requestId,
            'before_digest' => $beforeDigest,
            'after_digest' => $afterDigest,
            'metadata_json' => $metadata,
            'created_at' => $this->clock->sql(),
        ]);
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private static function sanitize(array $value): array
    {
        $safe = [];
        foreach ($value as $key => $item) {
            if (preg_match('/password|passphrase|token|secret|api[_-]?key|authorization|cookie/i', (string) $key) === 1) {
                continue;
            }
            $safe[(string) $key] = is_array($item) ? self::sanitize($item) : $item;
        }
        return $safe;
    }
}
