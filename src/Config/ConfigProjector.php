<?php

declare(strict_types=1);

namespace LandingOps\Config;

use LandingOps\Contract\Json;
use LandingOps\Exception\LandingOpsException;
use stdClass;

final class ConfigProjector
{
    /** @param list<string> $publicPaths */
    public function project(stdClass $config, array $publicPaths): stdClass
    {
        $projected = new stdClass();
        foreach ($publicPaths as $path) {
            $segments = explode('.', $path);
            $value = $this->read($config, $segments, $path);
            $this->write($projected, $segments, $value);
        }

        return $projected;
    }

    public function publicHash(stdClass $projected): string
    {
        return Json::hash($projected);
    }

    public function javascript(
        string $globalName,
        int $revision,
        string $publicHash,
        string $generatedAt,
        stdClass $projected,
    ): string {
        if ($globalName !== '__LANDINGOPS_CONFIG__') {
            throw new LandingOpsException('LTS v1.0 全局配置名非法。');
        }

        $envelope = new stdClass();
        $envelope->contract = 'lts-public-config/1.0';
        $envelope->revision = $revision;
        $envelope->public_hash = $publicHash;
        $envelope->generated_at = $generatedAt;
        $envelope->config = $projected;

        $json = json_encode(
            json_decode(Json::canonical($envelope), false, 128, JSON_THROW_ON_ERROR),
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT,
        );

        $detail = json_encode(
            ['revision' => $revision, 'public_hash' => $publicHash],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return "window.__LANDINGOPS_CONFIG__ = Object.freeze({$json});\n"
            . "window.dispatchEvent(new CustomEvent('landingops:config-ready', {detail: {$detail}}));\n";
    }

    /** @param list<string> $segments */
    private function read(stdClass $config, array $segments, string $path): mixed
    {
        $cursor = $config;
        foreach ($segments as $index => $segment) {
            if (!($cursor instanceof stdClass) || !property_exists($cursor, $segment)) {
                throw new LandingOpsException('公开配置路径在实例配置中不存在：' . $path);
            }
            $cursor = $cursor->{$segment};
            if ($index < count($segments) - 1 && !($cursor instanceof stdClass)) {
                throw new LandingOpsException('公开配置路径不是 object：' . $path);
            }
        }

        return $this->cloneValue($cursor);
    }

    /** @param list<string> $segments */
    private function write(stdClass $target, array $segments, mixed $value): void
    {
        $cursor = $target;
        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $cursor->{$segment} = $value;
                return;
            }
            if (!property_exists($cursor, $segment)) {
                $cursor->{$segment} = new stdClass();
            }
            $cursor = $cursor->{$segment};
        }
    }

    private function cloneValue(mixed $value): mixed
    {
        return Json::decode(Json::canonical($value), '投影配置');
    }
}
