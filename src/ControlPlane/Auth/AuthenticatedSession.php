<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Auth;

final class AuthenticatedSession
{
    public function __construct(
        public readonly Principal $principal,
        public readonly string $sessionId,
        public readonly string $cookieToken,
        public readonly string $csrfToken,
        public readonly string $expiresAt,
    ) {
    }
}
