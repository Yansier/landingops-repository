<?php

declare(strict_types=1);

namespace LandingOps\Zip;

final class ArchiveEntry
{
    public function __construct(
        public readonly int $index,
        public readonly string $name,
        public readonly int $size,
        public readonly int $compressedSize,
        public readonly bool $directory,
        public readonly int $unixMode,
    ) {
    }
}
