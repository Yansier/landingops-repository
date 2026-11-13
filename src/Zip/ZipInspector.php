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
        if (!is_file($path)) {
            $result->addError('zip_not_found', 'ZIP 文件不存在。', $path);
            return null;
        }

        $archiveSize = filesize($path);
        if (!is_int($archiveSize) || $archiveSize > $this->limits->maxArchiveBytes) {
            $result->addError('zip_too_large', 'ZIP 压缩包超过大小限制。', $path);
            return null;
        }

        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::RDONLY);
        if ($opened !== true) {
            $result->addError('zip_invalid', sprintf('无法打开 ZIP（错误码 %s）。', (string) $opened), $path);
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

            return new InspectedArchive(
                $path,
                hash_file('sha256', $path),
                $entries,
            );
        } finally {
            $zip->close();
        }
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
