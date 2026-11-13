<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Cloudflare;

final readonly class CloudflareHttpResponse
{
    public function __construct(public int $status, public string $body)
    {
    }
}
