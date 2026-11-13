<?php

declare(strict_types=1);

namespace LandingOps\Template;

use LandingOps\Validation\ValidationResult;

final class TemplateValidation
{
    public function __construct(
        public readonly ValidationResult $result,
        public readonly ?ValidatedTemplate $template,
    ) {
    }
}
