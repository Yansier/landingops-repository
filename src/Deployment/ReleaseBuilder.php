<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

use ZipArchive;

final class ReleaseBuilder
{
    public function __construct(private readonly ReleaseManifest $manifest = new ReleaseManifest())
    {
    }

    /** @return array<string,mixed> */
    public function build(string $projectRoot, string $outputRoot, string $version, string $sourceCommit, string $builtAt): array
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/D', $version) !== 1
            || preg_match('/^[a-f0-9]{7,64}$/D', $sourceCommit) !== 1) {
            throw new DeploymentException('release_identity_invalid', 'release 版本或源码提交标识无效。');
        }
        try {
            $timestamp = new \DateTimeImmutable($builtAt);
        } catch (\Throwable) {
            throw new DeploymentException('release_time_invalid', '构建时间必须是有效时间。');
        }
        $project = realpath($projectRoot);
        if (!is_string($project)) {
            throw new DeploymentException('project_root_invalid', '项目根目录不存在。');
        }
        foreach (['composer.lock', 'vendor/autoload.php', 'frontend/dist/index.html', 'public/index.php', 'public/health.php'] as $required) {
            if (!is_file($project . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $required))) {
                throw new DeploymentException('build_prerequisite_missing', '生产构建前置文件缺失：' . $required);
            }
        }
        if (!is_dir($outputRoot) && !mkdir($outputRoot, 0700, true) && !is_dir($outputRoot)) {
            throw new DeploymentException('build_output_unavailable', '无法创建构建输出目录。');
        }
        $versionRoot = rtrim($outputRoot, '\/') . DIRECTORY_SEPARATOR . $version;
        if (file_exists($versionRoot)) {
            throw new DeploymentException('release_output_exists', '版本化构建输出已存在，禁止覆盖。');
        }
        $releaseRoot = $versionRoot . DIRECTORY_SEPARATOR . 'release';
        if (!mkdir($releaseRoot, 0700, true)) {
            throw new DeploymentException('build_output_unavailable', '无法创建 release staging。');
        }
        try {
            $this->copyTree($project . '/frontend/dist', $releaseRoot . '/public');
            $this->copyFile($project . '/public/index.php', $releaseRoot . '/public/index.php');
            $this->copyFile($project . '/public/health.php', $releaseRoot . '/public/health.php');
            $this->copyTree($project . '/src', $releaseRoot . '/src');
            $this->copyTree($project . '/database/migrations', $releaseRoot . '/database/migrations');
            $this->copyTree($project . '/vendor', $releaseRoot . '/vendor');
            $this->copyFile($project . '/bin/landingops-control', $releaseRoot . '/bin/landingops-control');
            $this->copyFile($project . '/composer.json', $releaseRoot . '/composer.json');
            $this->copyFile($project . '/composer.lock', $releaseRoot . '/composer.lock');
            file_put_contents($releaseRoot . '/RELEASE_VERSION', $version . "\n", LOCK_EX);
            file_put_contents($releaseRoot . '/release-layout.json', json_encode([
                'web_root' => 'public',
                'application_root' => '.',
                'persistent_paths' => ['template_storage', 'environment', 'keyring', 'database_backups'],
                'deployment_scope' => 'landingops_platform',
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
            $files = $this->hashFiles($releaseRoot);
            $manifest = [
                'schema_version' => 'landingops-release/1.0',
                'deployment_scope' => 'landingops_platform',
                'version' => $version,
                'source_commit' => $sourceCommit,
                'built_at' => $timestamp->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'web_root' => 'public',
                'database_migrations' => array_map(
                    static fn (string $path): string => basename($path, '.sql'),
                    glob($releaseRoot . '/database/migrations/[0-9][0-9][0-9]_*.sql') ?: [],
                ),
                'files' => $files,
            ];
            file_put_contents($releaseRoot . '/release-manifest.json', json_encode(
                $manifest,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n", LOCK_EX);
            $this->manifest->verify($releaseRoot);
            $archive = $versionRoot . DIRECTORY_SEPARATOR . 'landingops-' . $version . '.zip';
            $this->zip($releaseRoot, $archive, $timestamp->getTimestamp());
            $archiveHash = hash_file('sha256', $archive);
            file_put_contents($archive . '.sha256', $archiveHash . '  ' . basename($archive) . "\n", LOCK_EX);
            return [
                'version' => $version, 'release_directory' => $releaseRoot, 'archive' => $archive,
                'archive_sha256' => $archiveHash, 'file_count' => count($files),
                'web_root' => $releaseRoot . DIRECTORY_SEPARATOR . 'public',
                'write_scope' => 'local_build_output_only',
            ];
        } catch (\Throwable $exception) {
            $this->removeTree($versionRoot);
            throw $exception;
        }
    }

    /** @return array<string,array{sha256:string,size:int}> */
    private function hashFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (is_link($file->getPathname())) {
                throw new DeploymentException('build_symlink_forbidden', '生产构建输入包含符号链接。');
            }
            if (!$file->isFile()) {
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            $files[$relative] = ['sha256' => (string) hash_file('sha256', $file->getPathname()), 'size' => $file->getSize()];
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private function copyTree(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new DeploymentException('build_prerequisite_missing', '构建目录缺失：' . basename($source));
        }
        if (!is_dir($destination) && !mkdir($destination, 0700, true) && !is_dir($destination)) {
            throw new DeploymentException('build_copy_failed', '无法创建构建目录。');
        }
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $from = $source . DIRECTORY_SEPARATOR . $entry;
            $to = $destination . DIRECTORY_SEPARATOR . $entry;
            if (is_link($from)) {
                throw new DeploymentException('build_symlink_forbidden', '生产构建输入包含符号链接。');
            }
            if (is_dir($from)) {
                $this->copyTree($from, $to);
            } elseif (is_file($from)) {
                $this->copyFile($from, $to);
            }
        }
    }

    private function copyFile(string $source, string $destination): void
    {
        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new DeploymentException('build_copy_failed', '无法创建文件父目录。');
        }
        if (!copy($source, $destination)) {
            throw new DeploymentException('build_copy_failed', '无法复制生产构建文件。');
        }
    }

    private function zip(string $root, string $archivePath, int $timestamp): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new DeploymentException('release_archive_failed', '无法创建 release ZIP。');
        }
        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            $paths = [];
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $paths[] = $file->getPathname();
                }
            }
            sort($paths, SORT_STRING);
            foreach ($paths as $path) {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
                if (!$zip->addFile($path, $relative)) {
                    throw new DeploymentException('release_archive_failed', '无法写入 release ZIP。');
                }
                if (method_exists($zip, 'setMtimeName')) {
                    $zip->setMtimeName($relative, $timestamp);
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
    }
}
