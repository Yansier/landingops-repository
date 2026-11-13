<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Service;

use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\Principal;
use LandingOps\ControlPlane\Repository\ControlPlaneRepository;

final class ResourceAccessService
{
    public function __construct(
        private readonly ControlPlaneRepository $repository,
        private readonly AuthorizationService $authorization = new AuthorizationService(),
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function listInstances(Principal $actor): array
    {
        $scope = $this->authorization->instanceScope($actor);
        return $this->repository->listInstances($scope['team_id'], $scope['owner_user_id']);
    }

    public function assertTemplateVersion(Principal $actor, string $teamId, string $versionId): void
    {
        $version = $this->repository->findTemplateVersionAuthorization($versionId);
        $this->authorization->assertTemplateGrant(
            $actor,
            $teamId,
            $version !== null,
            $this->repository->hasTemplateGrant($versionId, $teamId),
        );
    }

    public function assertPrimaryDomain(Principal $actor, string $teamId, string $domainId): void
    {
        $this->authorization->assertDomainGrant(
            $actor,
            $teamId,
            $this->repository->hasDomainGrant($domainId, $teamId),
        );
    }
}
