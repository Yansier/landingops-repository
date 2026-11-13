<?php

declare(strict_types=1);

namespace LandingOps\Instance;

final class BuildResult
{
    public function __construct(
        public readonly int $revision,
        public readonly string $releaseId,
        public readonly string $releasePath,
        public readonly string $contentHash,
        public readonly string $publicHash,
        public readonly string $publicConfigAsset,
    ) {
    }

    /** @return array<string,int|string> */
    public function toArray(): array
    {
        return [
            'revision' => $this->revision,
            'release_id' => $this->releaseId,
            'release_path' => $this->releasePath,
            'content_hash' => $this->contentHash,
            'public_hash' => $this->publicHash,
            'public_config_asset' => $this->publicConfigAsset,
        ];
    }
}
