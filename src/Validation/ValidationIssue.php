<?php

declare(strict_types=1);

namespace LandingOps\Validation;

final class ValidationIssue
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $path = null,
    ) {
    }

    /** @return array{code:string,message:string,path:?string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'path' => $this->path,
        ];
    }
}
