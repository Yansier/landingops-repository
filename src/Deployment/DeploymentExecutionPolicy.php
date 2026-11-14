<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

final class DeploymentExecutionPolicy
{
    public function requireSimulationRoot(?string $path): string
    {
        if ($path === null || trim($path) === '') {
            throw new DeploymentException(
                'real_execution_disabled',
                'Phase 1L 禁止真实服务器写入；必须显式提供 --simulate-root。',
            );
        }
        return $path;
    }
}
