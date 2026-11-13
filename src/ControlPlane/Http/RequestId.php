<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Http;

final class RequestId
{
    public function resolve(string $candidate): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $candidate) === 1) {
            return $candidate;
        }
        return 'req_' . bin2hex(random_bytes(12));
    }
}
