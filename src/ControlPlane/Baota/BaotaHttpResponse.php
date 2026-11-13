<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Baota;

final readonly class BaotaHttpResponse
{
    public function __construct(public int $status, public string $body)
    {
    }
}
