<?php

declare(strict_types=1);

namespace LandingOps\Zip;

use LandingOps\Exception\LandingOpsException;
use ZipArchive;

final class SafeExtractor
{
    public function __construct(private readonly ZipLimits $limits = new ZipLimits())
    {
    }

    public function extract(InspectedArchive $archive, string $destination): void
    {
        $archive->assertSnapshotIntegrity();
        if (file_exists($destination)) {
            throw new LandingOpsException('安全解压目标必须不存在。');
        }
        if (!mkdir($destination, 0700, true) && !is_dir($destination)) {
            throw new LandingOpsException('无法创建安全解压目录。');
        }

        $zip = new ZipArchive();
        if ($zip->open($archive->path, ZipArchive::RDONLY) !== true) {
            throw new LandingOpsException('无法打开已检查 ZIP。');
        }

        $total = 0;
        try {
            foreach ($archive->entries as $entry) {
                $relative = rtrim($entry->name, '/');
                $target = $destination . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $relative);

                if ($entry->directory) {
                    if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
                        throw new LandingOpsException('无法创建解压目录：' . $entry->name);
                    }
                    continue;
                }

                $parent = dirname($target);
                if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                    throw new LandingOpsException('无法创建解压父目录：' . $entry->name);
                }

                $input = $zip->getStream($entry->name);
                if (!is_resource($input)) {
                    throw new LandingOpsException('无法读取 ZIP 条目：' . $entry->name);
                }
                $output = fopen($target, 'xb');
                if (!is_resource($output)) {
                    fclose($input);
                    throw new LandingOpsException('拒绝覆盖解压目标：' . $entry->name);
                }

                $written = 0;
                try {
                    while (!feof($input)) {
                        $chunk = fread($input, 64 * 1024);
                        if ($chunk === false) {
                            throw new LandingOpsException('读取 ZIP 条目失败：' . $entry->name);
                        }
                        $length = strlen($chunk);
                        $written += $length;
                        $total += $length;
                        if ($written > $this->limits->maxFileBytes || $total > $this->limits->maxExtractedBytes) {
                            throw new LandingOpsException('解压数据超过运行时限制。');
                        }
                        if ($length > 0 && fwrite($output, $chunk) !== $length) {
                            throw new LandingOpsException('写入解压条目失败：' . $entry->name);
                        }
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }

                if ($written !== $entry->size) {
                    throw new LandingOpsException('ZIP 条目声明大小与实际不符：' . $entry->name);
                }
            }
        } finally {
            $zip->close();
            $archive->assertSnapshotIntegrity();
        }
    }
}
