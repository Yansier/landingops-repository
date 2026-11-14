<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

use RuntimeException;

final class DeploymentException extends RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
