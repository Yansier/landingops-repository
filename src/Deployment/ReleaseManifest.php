<?php

declare(strict_types=1);

namespace LandingOps\Deployment;

final class ReleaseManifest
{
    /** @return array<string,mixed> */
    public function verify(string $releaseRoot): array
    {
        $root = realpath($releaseRoot);
        if (!is_string($root) || !is_dir($root)) {
            throw new DeploymentException('release_missing', 'release 目录不存在。');
        }
        $manifestPath = $root . DIRECTORY_SEPARATOR . 'release-manifest.json';
        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new DeploymentException('release_manifest_invalid', 'release manifest 无法读取或不是有效 JSON。');
        }
        if (!is_array($manifest) || ($manifest['schema_version'] ?? null) !== 'landingops-release/1.0'
            || ($manifest['deployment_scope'] ?? null) !== 'landingops_platform'
            || !is_array($manifest['files'] ?? null) || array_is_list($manifest['files'])) {
            throw new DeploymentException('release_manifest_invalid', 'release manifest 契约无效。');
        }
        $actual = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (is_link($path)) {
                throw new DeploymentException('release_symlink_forbidden', 'release 不允许包含符号链接。');
            }
            if (!$file->isFile()) {
                continue;
            }
            $relative = self::relative($root, $path);
            if ($relative === 'release-manifest.json') {
                continue;
            }
            $actual[$relative] = ['sha256' => hash_file('sha256', $path), 'size' => $file->getSize()];
        }
        ksort($actual, SORT_STRING);
        $expected = $manifest['files'];
        ksort($expected, SORT_STRING);
        if (array_keys($expected) !== array_keys($actual)) {
            throw new DeploymentException('release_manifest_file_set_mismatch', 'release 文件集合与 manifest 不一致。');
        }
        foreach ($expected as $relative => $metadata) {
            if (!is_string($relative) || !self::safeRelative($relative) || !is_array($metadata)
                || !is_string($metadata['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $metadata['sha256']) !== 1
                || !is_int($metadata['size'] ?? null)
                || !hash_equals($metadata['sha256'], (string) $actual[$relative]['sha256'])
                || $metadata['size'] !== $actual[$relative]['size']) {
                throw new DeploymentException('release_manifest_hash_mismatch', 'release 文件哈希或大小校验失败。', ['path' => $relative]);
            }
        }
        $this->assertPublicRoot($root, $actual);
        return $manifest;
    }

    /** @param array<string,array{sha256:string|false,size:int}> $files */
    private function assertPublicRoot(string $root, array $files): void
    {
        foreach (array_keys($files) as $relative) {
            if (!str_starts_with($relative, 'public/')) {
                continue;
            }
            if (preg_match('#(?:^|/)(?:\.[^/]*|composer\.(?:json|lock)|database|src|vendor|config\.(?:json|php|ya?ml)|private(?:[-_.][^/]*)?|backup(?:[-_.][^/]*)?)(?:/|$)#i', $relative) === 1
                || preg_match('/(?:\.map|\.env|\.pem|\.key|\.p12|\.pfx|\.sql|\.bak|\.backup|\.old|\.orig|\.swp|\.tmp|\.zip|\.tar|\.gz|\.vue|\.tsx?|\.scss|\.sass|\.less)$/i', $relative) === 1
                || str_ends_with($relative, '~')) {
                throw new DeploymentException('public_root_private_file', '敏感、备份、源码或私有配置文件进入了 public Web Root。', ['path' => $relative]);
            }
            if (in_array($relative, ['public/index.php', 'public/health.php', 'public/index.html'], true)) {
                // Explicitly approved entry points.
            } elseif (preg_match('#^public/assets/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+-[A-Za-z0-9_-]{6,}\.(?:js|css|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot)$#D', $relative) !== 1) {
                throw new DeploymentException('public_root_file_forbidden', 'public Web Root 包含非 Vue 构建白名单文件。', ['path' => $relative]);
            }
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $handle = fopen($path, 'rb');
            $sample = is_resource($handle) ? fread($handle, 1048576) : false;
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (!is_string($sample)
                || preg_match('/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/', $sample) === 1
                || preg_match('/LANDINGOPS_(?:DEPLOY|APP|DATABASE)_[A-Z0-9_]*\s*=\s*[^\s"\']+/', $sample) === 1) {
                throw new DeploymentException('public_root_sensitive_content', 'public Web Root 文件包含疑似私钥或私有配置内容。', ['path' => $relative]);
            }
        }
        foreach (['public/index.php', 'public/health.php', 'public/index.html'] as $required) {
            if (!isset($files[$required]) || !is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $required))) {
                throw new DeploymentException('public_root_incomplete', 'public Web Root 缺少 ' . $required . '。');
            }
        }
    }

    private static function relative(string $root, string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
    }

    private static function safeRelative(string $path): bool
    {
        return $path !== '' && !str_starts_with($path, '/') && !str_contains($path, '\\')
            && preg_match('#(?:^|/)\.\.?(/|$)#', $path) !== 1 && !str_contains($path, "\0");
    }
}
