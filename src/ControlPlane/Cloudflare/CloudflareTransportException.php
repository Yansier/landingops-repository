<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Cloudflare;

use RuntimeException;

final class CloudflareTransportException extends RuntimeException
{
    public function __construct(public readonly string $reason = 'unavailable')
    {
        parent::__construct('Cloudflare transport failed.');
    }
}
