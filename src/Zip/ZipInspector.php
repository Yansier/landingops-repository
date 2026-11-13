<?php

declare(strict_types=1);

namespace LandingOps\Zip;

use LandingOps\Validation\ValidationResult;
use ZipArchive;

final class ZipInspector
{
    private const UNIX_TYPE_MASK = 0170000;
    private const UNIX_REGULAR = 0100000;
    private const UNIX_DIRECTORY = 0040000;

    /** @var list<string> */
    private const FORBIDDEN_BASENAMES = [
        '.env',
        '.htaccess',
        '.user.ini',
        'web.config',
        'id_rsa',
        'id_ed25519',
        'authorized_keys',
        'composer.phar',
    ];

    /** @var list<string> */
    private const FORBIDDEN_EXTENSIONS = [
        'exe', 'dll', 'so', 'dylib', 'com', 'bat', 'cmd', 'ps1',
        'pfx', 'p12', 'pem', 'key', 'der',
    ];

    public function __construct(private readonly ZipLimits $limits = new ZipLimits())
    {
    }

    public function inspect(string $path, ValidationResult $result): ?InspectedArchive
    {
        if (!is_file($path) || is_link($path)) {
            $result->addError('zip_not_found', 'ZIP 文件不存在。', $path);
            return null;
        }

        $archiveSize = filesize($path);
        if (!is_int($archiveSize) || $archiveSize > $this->limits->maxArchiveBytes) {
            $result->addError('zip_too_large', 'ZIP 压缩包超过大小限制。', $path);
            return null;
        }

        $snapshot = $this->snapshot($path, $archiveSize, $result);
        if ($snapshot === null) {
            return null;
        }
        [$snapshotPath, $snapshotDirectory, $snapshotHash, $snapshotIdentity] = $snapshot;
        $retainSnapshot = false;

        $zip = new ZipArchive();
        $opened = $zip->open($snapshotPath, ZipArchive::RDONLY);
        if ($opened !== true) {
            $result->addError('zip_invalid', sprintf('无法打开 ZIP（错误码 %s）。', (string) $opened), $path);
            @unlink($snapshotPath);
            @rmdir($snapshotDirectory);
            return null;
        }

        try {
            if ($zip->numFiles > $this->limits->maxFiles) {
                $result->addError('zip_file_count_exceeded', 'ZIP 文件数量超过限制。');
            }

            $entries = [];
            $seen = [];
            $seenFolded = [];
            $totalSize = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if (!is_array($stat) || !isset($stat['name'])) {
                    $result->addError('zip_entry_unreadable', '无法读取 ZIP 中央目录条目。', (string) $index);
                    continue;
                }

                $name = (string) $stat['name'];
                $normalized = $this->validatePath($name, $result);
                if ($normalized === null) {
                    continue;
                }

                $collisionKey = rtrim($normalized, '/');
                if (isset($seen[$collisionKey])) {
                    $result->addError('zip_duplicate_path', 'ZIP 包含重复路径。', $normalized);
                    continue;
                }
                $seen[$collisionKey] = true;

                $folded = strtolower($collisionKey);
                if (isset($seenFolded[$folded])) {
                    $result->addError('zip_case_collision', 'ZIP 路径存在大小写折叠冲突。', $normalized);
                }
                $seenFolded[$folded] = true;

                $size = (int) ($stat['size'] ?? 0);
                $compressedSize = (int) ($stat['comp_size'] ?? 0);
                $totalSize += $size;
                if ($size > $this->limits->maxFileBytes) {
                    $result->addError('zip_entry_too_large', 'ZIP 内单文件超过限制。', $normalized);
                }
                if ($totalSize > $this->limits->maxExtractedBytes) {
                    $result->addError('zip_extracted_size_exceeded', 'ZIP 解压总大小超过限制。');
                }
                if ($compressedSize > 0 && $size > 1024 * 1024 && ($size / $compressedSize) > $this->limits->maxCompressionRatio) {
                    $result->addError('zip_compression_ratio_exceeded', 'ZIP 条目压缩比异常。', $normalized);
                }

                if (isset($stat['encryption_method']) && (int) $stat['encryption_method'] !== ZipArchive::EM_NONE) {
                    $result->addError('zip_encrypted_entry', '不允许加密 ZIP 条目。', $normalized);
                }

                $opsys = 0;
                $attributes = 0;
                $unixMode = 0;
                if ($zip->getExternalAttributesIndex($index, $opsys, $attributes, ZipArchive::FL_UNCHANGED)) {
                    $unixMode = ($attributes >> 16) & 0xffff;
                }

                $directory = str_ends_with($name, '/');
                $type = $unixMode & self::UNIX_TYPE_MASK;
                if ($type !== 0
                    && $type !== self::UNIX_REGULAR
                    && $type !== self::UNIX_DIRECTORY
                ) {
                    $result->addError('zip_special_file', '不允许符号链接、硬链接或设备类条目。', $normalized);
                }
                if ($type === self::UNIX_DIRECTORY) {
                    $directory = true;
                }

                if (!$directory) {
                    $this->checkDangerousName($normalized, $result);
                }

                $entries[] = new ArchiveEntry(
                    $index,
                    $normalized,
                    $size,
                    $compressedSize,
                    $directory,
                    $unixMode,
                );
            }

            if (!$result->isValid()) {
                return null;
            }

            $archive = new InspectedArchive(
                $path,
                $snapshotPath,
                $snapshotHash,
                $entries,
                $snapshotDirectory,
                $snapshotIdentity,
            );
            $retainSnapshot = true;
            return $archive;
        } finally {
            $zip->close();
            if (!$retainSnapshot) {
                @unlink($snapshotPath);
                @rmdir($snapshotDirectory);
            }
        }
    }

    /** @return array{0:string,1:string,2:string,3:array<string,int>}|null */
    private function snapshot(string $source, int $expectedSize, ValidationResult $result): ?array
    {
        $before = lstat($source);
        if (!is_array($before)) {
            $result->addError('zip_source_identity', '无法读取 ZIP 源文件身份。', $source);
            return null;
        }

        $directory = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR
            . 'landingops-zip-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            $result->addError('zip_snapshot_create', '无法创建 ZIP 私有暂存目录。', $source);
            return null;
        }
        @chmod($directory, 0700);
        $snapshot = $directory . DIRECTORY_SEPARATOR . 'template.zip';
        $input = fopen($source, 'rb');
        $output = fopen($snapshot, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($snapshot);
            @rmdir($directory);
            $result->addError('zip_snapshot_create', '无法创建 ZIP 私有快照。', $source);
            return null;
        }
        @chmod($snapshot, 0600);

        $hash = hash_init('sha256');
        $copied = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 64 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException('读取 ZIP 源文件失败。');
                }
                $length = strlen($chunk);
                $copied += $length;
                if ($copied > $this->limits->maxArchiveBytes) {
                    throw new \RuntimeException('ZIP 复制期间超过大小限制。');
                }
                if ($length > 0 && fwrite($output, $chunk) !== $length) {
                    throw new \RuntimeException('写入 ZIP 私有快照失败。');
                }
                hash_update($hash, $chunk);
            }
            fflush($output);
        } catch (\RuntimeException $exception) {
            $result->addError('zip_snapshot_failed', $exception->getMessage(), $source);
        } finally {
            fclose($input);
            fclose($output);
        }

        $after = lstat($source);
        $sourceStable = is_array($after)
            && $copied === $expectedSize
            && $this->sameIdentity($before, $after);
        if (!$result->isValid() || !$sourceStable) {
            if (!$sourceStable) {
                $result->addError('zip_source_changed', 'ZIP 在私有快照期间发生变化。', $source);
            }
            @unlink($snapshot);
            @rmdir($directory);
            return null;
        }

        $snapshotStat = lstat($snapshot);
        if (!is_array($snapshotStat) || is_link($snapshot)) {
            @unlink($snapshot);
            @rmdir($directory);
            $result->addError('zip_snapshot_identity', '无法确认 ZIP 私有快照身份。', $source);
            return null;
        }

        return [
            $snapshot,
            $directory,
            hash_final($hash),
            $this->identity($snapshotStat),
        ];
    }

    /** @param array<int|string,mixed> $left @param array<int|string,mixed> $right */
    private function sameIdentity(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $field) {
            if ((int) ($left[$field] ?? -1) !== (int) ($right[$field] ?? -2)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<int|string,mixed> $stat @return array<string,int> */
    private function identity(array $stat): array
    {
        return [
            'dev' => (int) ($stat['dev'] ?? -1),
            'ino' => (int) ($stat['ino'] ?? -1),
            'mode' => (int) ($stat['mode'] ?? -1),
            'size' => (int) ($stat['size'] ?? -1),
        ];
    }

    private function validatePath(string $name, ValidationResult $result): ?string
    {
        if ($name === '' || str_contains($name, "\0")) {
            $result->addError('zip_invalid_path', 'ZIP 路径为空或包含 NUL。', $name);
            return null;
        }
        if (str_contains($name, '\\')) {
            $result->addError('zip_backslash_path', 'ZIP 路径必须使用正斜杠。', $name);
            return null;
        }
        if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) {
            $result->addError('zip_absolute_path', 'ZIP 不允许绝对路径或盘符。', $name);
            return null;
        }

        $trimmed = rtrim($name, '/');
        $segments = explode('/', $trimmed);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                $result->addError('zip_path_traversal', 'ZIP 路径包含空段、`.` 或 `..`。', $name);
                return null;
            }
            if (preg_match('/[\x00-\x1f\x7f]/u', $segment) === 1) {
                $result->addError('zip_control_character', 'ZIP 路径包含控制字符。', $name);
                return null;
            }
            if (preg_match('/^[\x20-\x7e]+$/D', $segment) !== 1) {
                $result->addError('zip_non_ascii_path', 'LTS v1.0 离线验证器仅接受可确定规范化的 ASCII 路径。', $name);
                return null;
            }
            if (str_ends_with($segment, '.') || str_ends_with($segment, ' ') || str_contains($segment, ':')) {
                $result->addError('zip_platform_ambiguous_path', 'ZIP 路径在目标平台上存在歧义。', $name);
                return null;
            }
            $deviceName = strtoupper(explode('.', $segment, 2)[0]);
            if (preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/D', $deviceName) === 1) {
                $result->addError('zip_reserved_device_name', 'ZIP 路径使用了保留设备名。', $name);
                return null;
            }
        }

        return implode('/', $segments) . (str_ends_with($name, '/') ? '/' : '');
    }

    private function checkDangerousName(string $name, ValidationResult $result): void
    {
        $segments = explode('/', strtolower($name));
        if (in_array('.git', $segments, true)) {
            $result->addError('zip_forbidden_path', '模板不得包含 .git 目录。', $name);
        }

        $basename = basename(strtolower($name));
        if (in_array($basename, self::FORBIDDEN_BASENAMES, true)) {
            $result->addError('zip_forbidden_file', '模板包含危险或敏感文件名。', $name);
        }

        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
        if (in_array($extension, self::FORBIDDEN_EXTENSIONS, true)) {
            $result->addError('zip_forbidden_extension', '模板包含不允许的二进制或密钥扩展名。', $name);
        }
    }
}
