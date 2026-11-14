<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

final class DeploymentExecutionPolicy
{
    public function __construct(private readonly SimulationSandbox $sandbox = new SimulationSandbox())
    {
    }

    public function authorizeSimulationRoot(string $path, DeploymentConfig $config, string $projectRoot): string
    {
        return $this->sandbox->authorize($path, $config, $projectRoot);
    }

    public function requireSimulationRoot(?string $path, DeploymentConfig $config, string $projectRoot): string
    {
        if ($path === null || trim($path) === '') {
            throw new DeploymentException(
                'real_execution_disabled',
                'Phase 1L 禁止真实服务器写入；必须显式提供 --simulate-root。',
            );
        }
        return $this->sandbox->verify($path, $config, $projectRoot);
    }
}
