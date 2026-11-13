<?php

declare(strict_types=1);

namespace LandingOps\Zip;

final class ZipLimits
{
    public function __construct(
        public readonly int $maxArchiveBytes = 30 * 1024 * 1024,
        public readonly int $maxExtractedBytes = 100 * 1024 * 1024,
        public readonly int $maxFiles = 2_000,
        public readonly int $maxFileBytes = 30 * 1024 * 1024,
        public readonly float $maxCompressionRatio = 200.0,
    ) {
    }
}
