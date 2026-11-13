<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Baota;

use RuntimeException;

final class BaotaTransportException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Baota transport failed.');
    }
}
