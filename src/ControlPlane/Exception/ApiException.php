<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Exception;

use RuntimeException;

final class ApiException extends RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
