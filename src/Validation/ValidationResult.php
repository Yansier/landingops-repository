<?php

declare(strict_types=1);

namespace LandingOps\Validation;

final class ValidationResult
{
    /** @var list<ValidationIssue> */
    private array $errors = [];

    /** @var list<ValidationIssue> */
    private array $warnings = [];

    public function addError(string $code, string $message, ?string $path = null): void
    {
        $this->errors[] = new ValidationIssue($code, $message, $path);
    }

    public function addWarning(string $code, string $message, ?string $path = null): void
    {
        $this->warnings[] = new ValidationIssue($code, $message, $path);
    }

    public function merge(self $other): void
    {
        array_push($this->errors, ...$other->errors());
        array_push($this->warnings, ...$other->warnings());
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return list<ValidationIssue> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<ValidationIssue> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array{valid:bool,errors:list<array{code:string,message:string,path:?string}>,warnings:list<array{code:string,message:string,path:?string}>} */
    public function toArray(): array
    {
        return [
            'valid' => $this->isValid(),
            'errors' => array_map(
                static fn (ValidationIssue $issue): array => $issue->toArray(),
                $this->errors,
            ),
            'warnings' => array_map(
                static fn (ValidationIssue $issue): array => $issue->toArray(),
                $this->warnings,
            ),
        ];
    }
}
