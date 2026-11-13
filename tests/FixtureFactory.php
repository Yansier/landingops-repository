<?php

declare(strict_types=1);

namespace LandingOps\Tests;

use LandingOps\Contract\Json;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use stdClass;
use ZipArchive;

final class FixtureFactory
{
    public static function sampleDirectory(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'examples'
            . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'minimal-lts-v1';
    }

    public static function sampleConfig(): stdClass
    {
        $json = (string) file_get_contents(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'examples'
            . DIRECTORY_SEPARATOR . 'configs' . DIRECTORY_SEPARATOR . 'minimal-instance.json',
        );
        $config = Json::decode($json, 'minimal-instance.json');
        if (!($config instanceof stdClass)) {
            throw new RuntimeException('Sample config must be object.');
        }
        return $config;
    }

    /** @param array<string,string> $replace @param array<string,string> $extra */
    public static function sampleZip(string $zipPath, array $replace = [], array $extra = [], array $skip = []): void
    {
        $entries = self::directoryEntries(self::sampleDirectory());
        foreach ($replace as $name => $contents) {
            $entries[$name] = $contents;
        }
        foreach ($extra as $name => $contents) {
            $entries[$name] = $contents;
        }
        foreach ($skip as $name) {
            unset($entries[$name]);
        }
        self::zip($zipPath, $entries);
    }

    /** @param array<string,string> $entries @param array<string,int> $unixModes */
    public static function zip(string $zipPath, array $entries, array $unixModes = []): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Unable to create test ZIP.');
        }
        try {
            foreach ($entries as $name => $contents) {
                if (!$zip->addFromString($name, $contents)) {
                    throw new RuntimeException('Unable to add ZIP entry: ' . $name);
                }
                if (isset($unixModes[$name])) {
                    $zip->setExternalAttributesName(
                        $name,
                        ZipArchive::OPSYS_UNIX,
                        $unixModes[$name] << 16,
                    );
                }
            }
        } finally {
            $zip->close();
        }
    }

    /** @return array<string,string> */
    private static function directoryEntries(string $directory): array
    {
        $root = rtrim(realpath($directory) ?: $directory, '\\/');
        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $entries[$relative] = (string) file_get_contents($file->getPathname());
        }
        ksort($entries, SORT_STRING);
        return $entries;
    }
}
