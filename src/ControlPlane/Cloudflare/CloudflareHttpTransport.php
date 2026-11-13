<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Cloudflare;

interface CloudflareHttpTransport
{
    public function get(string $path, string $token, float $timeoutSeconds): CloudflareHttpResponse;
}
