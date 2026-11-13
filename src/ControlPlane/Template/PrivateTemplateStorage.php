<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Template;

use Closure;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\Exception\LandingOpsException;
use LandingOps\Filesystem\LocalFilesystem;
use LandingOps\Template\ValidatedTemplate;

final class PrivateTemplateStorage
{
    public const MAX_ARCHIVE_BYTES = 30 * 1024 * 1024;

    private readonly string $root;
    /** @var Closure(string,string):void */
    private readonly Closure $copier;

    public function __construct(
        string $root,
        private readonly LocalFilesystem $filesystem = new LocalFilesystem(),
        ?string $webRoot = null,
        ?Closure $copier = null,
    ) {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($root === '' || !self::absolute($root)) {
            throw new LandingOpsException('模板私有存储必须使用绝对路径。');
        }
        $nativeRoot = str_replace('/', DIRECTORY_SEPARATOR, $root);
        $this->filesystem->ensureDirectory($nativeRoot);
        if (is_link($nativeRoot)) {
            throw new LandingOpsException('模板私有存储根目录不得是符号链接。');
        }
        $resolvedRoot = realpath($nativeRoot);
        if (!is_string($resolvedRoot)) {
            throw new LandingOpsException('无法解析模板私有存储根目录。');
        }
        if ($webRoot !== null) {
            $resolvedPublic = realpath($webRoot);
            $public = rtrim(str_replace('\\', '/', is_string($resolvedPublic) ? $resolvedPublic : $webRoot), '/');
            $resolved = rtrim(str_replace('\\', '/', $resolvedRoot), '/');
            if ($resolved === $public || str_starts_with($resolved . '/', $public . '/')) {
                throw new LandingOpsException('模板私有存储不得位于 Web Root 内。');
            }
        }
        $this->root = $resolvedRoot;
        $this->copier = $copier ?? $this->filesystem->copyFile(...);
        @chmod($this->root, 0700);
    }

    public static function forProject(string $projectRoot): self
    {
        $configured = getenv('LANDINGOPS_TEMPLATE_STORAGE_DIR');
        $path = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : rtrim($projectRoot, '\\/') . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'private'
                . DIRECTORY_SEPARATOR . 'templates';
        return new self($path, webRoot: rtrim($projectRoot, '\\/') . DIRECTORY_SEPARATOR . 'public');
    }

    public function stage(UploadedTemplateFile $file): string
    {
        if ($file->error !== UPLOAD_ERR_OK) {
            $status = in_array($file->error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 422;
            throw new ApiException($status, 'template_upload_failed', 'ZIP 上传未完成。', ['upload_error' => $file->error]);
        }
        if ($file->size < 1 || $file->size > self::MAX_ARCHIVE_BYTES) {
            throw new ApiException(413, 'template_zip_too_large', '模板 ZIP 必须大于 0 且不超过 30 MiB。');
        }
        if (strtolower(pathinfo($file->originalName, PATHINFO_EXTENSION)) !== 'zip') {
            throw new ApiException(422, 'template_zip_required', '只接受 .zip 模板压缩包。');
        }
        if (!is_file($file->temporaryPath) || is_link($file->temporaryPath)) {
            throw new ApiException(422, 'template_upload_missing', '上传临时文件不存在或类型无效。');
        }
        $actualSize = filesize($file->temporaryPath);
        if (!is_int($actualSize) || $actualSize !== $file->size) {
            throw new ApiException(422, 'template_upload_changed', '上传文件大小在处理期间发生变化。');
        }

        $intake = $this->root . DIRECTORY_SEPARATOR . 'intake';
        $this->ensurePrivateDirectory($intake);
        $path = $intake . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.zip';
        try {
            ($this->copier)($file->temporaryPath, $path);
            @chmod($path, 0600);
            return $path;
        } catch (\Throwable $exception) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
            throw $exception;
        }
    }

    public function persist(ValidatedTemplate $template, string $versionId): string
    {
        $template->archive->assertSnapshotIntegrity();
        $relative = 'versions/' . substr($template->archive->sha256, 0, 2) . '/' . $versionId . '.zip';
        $target = $this->resolve($relative);
        $this->ensurePrivateDirectory($this->root . DIRECTORY_SEPARATOR . 'versions');
        $this->ensurePrivateDirectory(dirname($target));
        ($this->copier)($template->archive->path, $target);
        @chmod($target, 0600);
        $actual = hash_file('sha256', $target);
        if (!is_string($actual) || !hash_equals($template->archive->sha256, $actual)) {
            @unlink($target);
            throw new LandingOpsException('持久化模板 ZIP 的 SHA-256 校验失败。');
        }
        return $relative;
    }

    public function removeStaged(string $path): void
    {
        $intake = rtrim(str_replace('\\', '/', $this->root), '/') . '/intake/';
        $candidate = str_replace('\\', '/', $path);
        if (str_starts_with($candidate, $intake) && is_file($path)) {
            @unlink($path);
        }
    }

    public function removeStored(string $relative): void
    {
        $path = $this->resolve($relative);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function hasStored(string $relative): bool
    {
        return is_file($this->resolve($relative));
    }

    private function resolve(string $relative): string
    {
        if (preg_match('#^versions/[0-9a-f]{2}/[0-9A-HJKMNP-TV-Z]{26}\.zip$#D', $relative) !== 1) {
            throw new LandingOpsException('模板存储键无效。');
        }
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function ensurePrivateDirectory(string $path): void
    {
        $this->filesystem->ensureDirectory($path);
        if (is_link($path)) {
            throw new LandingOpsException('模板私有存储子目录不得是符号链接。');
        }
        $resolved = realpath($path);
        $root = rtrim(str_replace('\\', '/', $this->root), '/') . '/';
        if (!is_string($resolved) || !str_starts_with(str_replace('\\', '/', $resolved) . '/', $root)) {
            throw new LandingOpsException('模板私有存储子目录越出允许范围。');
        }
        @chmod($path, 0700);
    }

    private static function absolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#D', $path) === 1;
    }
}
