<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Support;

use DateTimeImmutable;
use DateTimeZone;

final class UtcClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function sql(): string
    {
        return $this->now()->format('Y-m-d H:i:s.u');
    }
}
