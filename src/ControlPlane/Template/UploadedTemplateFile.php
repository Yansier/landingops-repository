<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Template;

final class UploadedTemplateFile
{
    public function __construct(
        public readonly string $temporaryPath,
        public readonly string $originalName,
        public readonly int $size,
        public readonly int $error = UPLOAD_ERR_OK,
    ) {
    }
}
