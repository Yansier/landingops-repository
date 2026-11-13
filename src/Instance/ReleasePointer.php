<?php

declare(strict_types=1);

namespace LandingOps\Instance;

use LandingOps\Exception\LandingOpsException;
use LandingOps\Filesystem\LocalFilesystem;

final class ReleasePointer
{
    private readonly string $pointerPath;
    private readonly InstanceLock $instanceLock;

    public function __construct(
        private readonly string $instanceRoot,
        private readonly LocalFilesystem $filesystem = new LocalFilesystem(),
    ) {
        $this->pointerPath = $instanceRoot . DIRECTORY_SEPARATOR . 'current.release';
        $this->instanceLock = new InstanceLock($instanceRoot, $filesystem);
    }

    public function activate(string $releaseId): void
    {
        $this->instanceLock->assertHeld();
        if (preg_match('/^r[1-9]\d*-[a-f0-9]{12}$/D', $releaseId) !== 1) {
            throw new LandingOpsException('release ID 格式非法。');
        }
        $release = $this->instanceRoot . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . $releaseId;
        if (!is_dir($release) || !is_file($release . DIRECTORY_SEPARATOR . 'release-manifest.json')) {
            throw new LandingOpsException('不能激活不存在或不完整的 release。');
        }

        $this->filesystem->writeAtomic($this->pointerPath, $releaseId . "\n");
    }

    public function currentId(): ?string
    {
        if (!is_file($this->pointerPath)) {
            return null;
        }
        $id = trim((string) file_get_contents($this->pointerPath));
        return $id === '' ? null : $id;
    }

    public function currentPath(): ?string
    {
        $id = $this->currentId();
        return $id === null
            ? null
            : $this->instanceRoot . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . $id;
    }
}
