<?php

declare(strict_types=1);

namespace LandingOps\Zip;

use ZipArchive;

final class InspectedArchive
{
    /** @param list<ArchiveEntry> $entries */
    public function __construct(
        public readonly string $path,
        public readonly string $sha256,
        public readonly array $entries,
    ) {
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
        }
    }
}
