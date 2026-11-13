<?php

declare(strict_types=1);

namespace LandingOps\Instance;

use LandingOps\Exception\LandingOpsException;

final class OfflinePublicRouter
{
    public function __construct(private readonly string $instanceRoot)
    {
    }

    public function resolve(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || str_contains($path, '%') || str_contains($path, '\\')) {
            throw new LandingOpsException('公共 URL 非法。');
        }

        if ($path === '/' || $path === '/index.html') {
            $releaseId = (new ReleasePointer($this->instanceRoot))->currentId();
            if ($releaseId === null) {
                throw new LandingOpsException('实例尚无 active release。');
            }
            return $this->releaseFile($releaseId, 'index.html');
        }

        $pattern = '~^' . preg_quote(PublicUrlRewriter::PREFIX, '~')
            . '(r[1-9]\d*-[a-f0-9]{12})/(.+)$~D';
        if (preg_match($pattern, $path, $matches) !== 1) {
            throw new LandingOpsException('公共 URL 不属于稳定 release 路由。');
        }
        return $this->releaseFile($matches[1], $matches[2]);
    }

    public function read(string $url): string
    {
        $contents = file_get_contents($this->resolve($url));
        if (!is_string($contents)) {
            throw new LandingOpsException('无法读取公共 URL。');
        }
        return $contents;
    }

    private function releaseFile(string $releaseId, string $relative): string
    {
        if (preg_match('/^r[1-9]\d*-[a-f0-9]{12}$/D', $releaseId) !== 1) {
            throw new LandingOpsException('公共 URL 的 release ID 非法。');
        }
        $segments = explode('/', $relative);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new LandingOpsException('公共 URL 包含非法路径段。');
            }
        }

        $publicRoot = $this->instanceRoot . DIRECTORY_SEPARATOR . 'releases'
            . DIRECTORY_SEPARATOR . $releaseId . DIRECTORY_SEPARATOR . 'public';
        $file = $publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($file) || is_link($file)) {
            throw new LandingOpsException('公共 URL 对应文件不存在。');
        }
        return $file;
    }
}
