<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Http;

final class Response
{
    /** @param array<string,string> $headers @param array<string,mixed> $body */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly array $headers = ['Content-Type' => 'application/json; charset=utf-8'],
    ) {
    }
}
