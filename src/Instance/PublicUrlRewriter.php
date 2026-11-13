<?php

declare(strict_types=1);

namespace LandingOps\Instance;

use LandingOps\Exception\LandingOpsException;

final class PublicUrlRewriter
{
    public const PREFIX = '/__landingops/releases/';

    public function baseUrl(string $releaseId): string
    {
        if (preg_match('/^r[1-9]\d*-[a-f0-9]{12}$/D', $releaseId) !== 1) {
            throw new LandingOpsException('release ID 格式非法。');
        }
        return self::PREFIX . $releaseId . '/';
    }

    public function rewriteHtml(string $html, string $releaseId): string
    {
        $baseUrl = $this->baseUrl($releaseId);
        $rewritten = preg_replace_callback(
            '~(\b(?:src|href)\s*=\s*)(["\'])([^"\']+)\2~i',
            static function (array $matches) use ($baseUrl): string {
                $reference = $matches[3];
                if ($reference === ''
                    || str_starts_with($reference, '#')
                    || str_starts_with($reference, '/')
                    || preg_match('~^[A-Za-z][A-Za-z0-9+.-]*:~D', $reference) === 1
                ) {
                    return $matches[0];
                }

                $suffixAt = strcspn($reference, '?#');
                $path = substr($reference, 0, $suffixAt);
                $suffix = substr($reference, $suffixAt);
                while (str_starts_with($path, './')) {
                    $path = substr($path, 2);
                }
                $segments = explode('/', $path);
                if ($path === ''
                    || str_contains($path, '\\')
                    || array_filter($segments, static fn (string $segment): bool => in_array($segment, ['', '.', '..'], true)) !== []
                ) {
                    throw new LandingOpsException('无法生成稳定资源 URL：' . $reference);
                }

                return $matches[1] . $matches[2] . $baseUrl . $path . $suffix . $matches[2];
            },
            $html,
        );
        if (!is_string($rewritten)) {
            throw new LandingOpsException('无法重写 HTML 公共资源 URL。');
        }
        return $rewritten;
    }
}
