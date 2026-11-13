<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Baota;

interface BaotaHttpTransport
{
    /** @param array<string,string|int> $fields */
    public function post(string $baseUrl, string $path, array $fields, float $timeoutSeconds): BaotaHttpResponse;
}
