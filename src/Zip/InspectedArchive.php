<?php

declare(strict_types=1);

namespace LandingOps\Zip;

use LandingOps\Exception\LandingOpsException;
use ZipArchive;

final class InspectedArchive
{
    /** @param list<ArchiveEntry> $entries */
    public function __construct(
        public readonly string $sourcePath,
        public readonly string $path,
        public readonly string $sha256,
        public readonly array $entries,
        private readonly string $snapshotDirectory,
        /** @var array<string,int> */
        private readonly array $snapshotIdentity,
    ) {
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    public function assertSnapshotIntegrity(): void
    {
        if (!is_file($this->path) || is_link($this->path)) {
            throw new LandingOpsException('已验证 ZIP 私有快照不存在或类型已改变。');
        }
        $stat = lstat($this->path);
        if (!is_array($stat)) {
            throw new LandingOpsException('无法读取已验证 ZIP 私有快照身份。');
        }
        foreach ($this->snapshotIdentity as $field => $expected) {
            if ((int) ($stat[$field] ?? -1) !== $expected) {
                throw new LandingOpsException('已验证 ZIP 私有快照身份已改变。');
            }
        }
        $actualHash = hash_file('sha256', $this->path);
        if (!is_string($actualHash) || !hash_equals($this->sha256, $actualHash)) {
            throw new LandingOpsException('已验证 ZIP 私有快照 SHA-256 已改变。');
        }
    }

    public function cleanup(): void
    {
        if (is_file($this->path) || is_link($this->path)) {
            @unlink($this->path);
        }
        if (is_dir($this->snapshotDirectory)) {
            @rmdir($this->snapshotDirectory);
        }
    }

    public function has(string $name): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->name === $name) {
                return true;
            }
        }

        return false;
    }

    public function read(string $name, int $limitBytes): string
    {
        $this->assertSnapshotIntegrity();
        $zip = new ZipArchive();
        if ($zip->open($this->path, ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('无法重新打开 ZIP。');
        }

        try {
            $entry = null;
            foreach ($this->entries as $candidate) {
                if ($candidate->name === $name) {
                    $entry = $candidate;
                    break;
                }
            }

            if ($entry === null || $entry->directory) {
                throw new \RuntimeException(sprintf('ZIP 中不存在文件：%s', $name));
            }

            if ($entry->size > $limitBytes) {
                throw new \RuntimeException(sprintf('ZIP 文件超过读取限制：%s', $name));
            }

            $stream = $zip->getStream($name);
            if (!is_resource($stream)) {
                throw new \RuntimeException(sprintf('无法读取 ZIP 文件：%s', $name));
            }

            try {
                $contents = stream_get_contents($stream, $limitBytes + 1);
                if ($contents === false || strlen($contents) > $limitBytes) {
                    throw new \RuntimeException(sprintf('ZIP 文件读取超过限制：%s', $name));
                }

                return $contents;
            } finally {
                fclose($stream);
            }
        } finally {
            $zip->close();
            $this->assertSnapshotIntegrity();
        }
    }
}
