<?php

declare(strict_types=1);

namespace LandingOps\Exception;

use LandingOps\Validation\ValidationResult;

final class ValidationException extends LandingOpsException
{
    public function __construct(
        string $message,
        public readonly ValidationResult $result,
    ) {
        parent::__construct($message);
    }
}
