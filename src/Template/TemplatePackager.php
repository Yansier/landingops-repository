<?php

declare(strict_types=1);

namespace LandingOps\Template;

use LandingOps\Exception\LandingOpsException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

final class TemplatePackager
{
    public function package(string $sourceDirectory, string $zipPath): void
    {
        if (!is_dir($sourceDirectory)) {
            throw new LandingOpsException('模板目录不存在：' . $sourceDirectory);
        }
        if (file_exists($zipPath)) {
            throw new LandingOpsException('拒绝覆盖已有 ZIP：' . $zipPath);
        }
        $parent = dirname($zipPath);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new LandingOpsException('无法创建 ZIP 输出目录。');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new LandingOpsException('无法创建 ZIP。');
        }
        try {
            $root = rtrim(realpath($sourceDirectory) ?: $sourceDirectory, '\\/');
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isLink()) {
                    throw new LandingOpsException('示例模板目录不得包含链接。');
                }
                if (!$file->isFile()) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
                if (!$zip->addFile($file->getPathname(), $relative)) {
                    throw new LandingOpsException('添加 ZIP 文件失败：' . $relative);
                }
            }
        } finally {
            $zip->close();
        }
    }
}
