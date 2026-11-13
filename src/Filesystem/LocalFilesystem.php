<?php

declare(strict_types=1);

namespace LandingOps\Filesystem;

use LandingOps\Exception\LandingOpsException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class LocalFilesystem
{
    public function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new LandingOpsException('无法创建目录：' . $path);
        }
    }

    public function writeExclusive(string $path, string $contents): void
    {
        $this->ensureDirectory(dirname($path));
        $handle = fopen($path, 'xb');
        if (!is_resource($handle)) {
            throw new LandingOpsException('拒绝覆盖文件：' . $path);
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new LandingOpsException('写入文件失败：' . $path);
            }
            fflush($handle);
        } finally {
            fclose($handle);
        }
    }

    public function writeAtomic(string $path, string $contents): void
    {
        $this->ensureDirectory(dirname($path));
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $this->writeExclusive($temporary, $contents);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new LandingOpsException('原子替换文件失败：' . $path);
        }
    }

    public function copyFile(string $source, string $destination): void
    {
        if (!is_file($source)) {
            throw new LandingOpsException('复制源文件不存在：' . $source);
        }
        $this->ensureDirectory(dirname($destination));
        if (file_exists($destination) || !copy($source, $destination)) {
            throw new LandingOpsException('复制文件失败或目标已存在：' . $destination);
        }
    }

    /** @return array<string,array{sha256:string,size:int}> */
    public function inventory(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(rtrim($root, '\\/')) + 1);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $files[$relative] = [
                'sha256' => hash_file('sha256', $file->getPathname()),
                'size' => $file->getSize(),
            ];
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    public function removeTree(string $path, string $allowedParent): void
    {
        if (!file_exists($path)) {
            return;
        }
        $normalizedParent = rtrim(str_replace('\\', '/', $allowedParent), '/') . '/';
        $normalizedPath = rtrim(str_replace('\\', '/', $path), '/') . '/';
        if (!str_starts_with($normalizedPath, $normalizedParent) || $normalizedPath === $normalizedParent) {
            throw new LandingOpsException('拒绝清理允许目录之外的路径。');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isLink() || $entry->isFile()) {
                if (!unlink($entry->getPathname())) {
                    throw new LandingOpsException('无法清理文件：' . $entry->getPathname());
                }
            } elseif (!rmdir($entry->getPathname())) {
                throw new LandingOpsException('无法清理目录：' . $entry->getPathname());
            }
        }
        if (!rmdir($path)) {
            throw new LandingOpsException('无法清理目录：' . $path);
        }
    }
}
