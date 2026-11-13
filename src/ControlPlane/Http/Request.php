<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Http;

final class Request
{
    /**
     * @param array<string,string> $headers
     * @param array<string,string> $cookies
     * @param array<string,mixed> $body
     * @param array<string,mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly array $body = [],
        public readonly string $ip = '',
        public readonly array $files = [],
    ) {
    }

    public function header(string $name): string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return '';
    }
}
