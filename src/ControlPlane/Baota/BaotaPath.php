<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Baota;

use LandingOps\ControlPlane\Exception\ApiException;

final class BaotaPath
{
    public static function normalize(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new ApiException(422, 'baota_path_invalid', '站点路径必须是绝对 POSIX 路径。');
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new ApiException(422, 'baota_path_escape', '站点路径包含越界片段。');
            }
            if (strlen($part) > 255) {
                throw new ApiException(422, 'baota_path_invalid', '站点路径片段过长。');
            }
            $parts[] = $part;
        }
        if ($parts === []) {
            throw new ApiException(422, 'baota_path_invalid', '站点路径不能是文件系统根目录。');
        }
        return '/' . implode('/', $parts);
    }

    public static function candidateRoot(string $fqdn): ?string
    {
        $root = getenv('LANDINGOPS_BAOTA_SITE_ROOT');
        if (!is_string($root) || trim($root) === '') {
            return null;
        }
        return self::normalize(rtrim(self::normalize(trim($root)), '/') . '/' . $fqdn);
    }
}
