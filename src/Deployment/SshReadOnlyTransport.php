<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

interface SshReadOnlyTransport
{
    /**
     * Execute the collector-owned immutable read-only probe set.
     *
     * @return array{host_key_fingerprint:string,session_id:string,probes:array<string,array{exit_code:int,stdout:string,timed_out:bool}>}
     */
    public function collect(DeploymentConfig $config): array;
}
