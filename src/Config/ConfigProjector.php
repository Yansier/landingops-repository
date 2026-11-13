<?php

declare(strict_types=1);

namespace LandingOps\Config;

use LandingOps\Contract\Json;
use LandingOps\Exception\LandingOpsException;
use stdClass;

final class ConfigProjector
{
    /** @param list<string> $publicPaths */
    public function project(stdClass $config, stdClass $schema, array $publicPaths): stdClass
    {
        $projected = new stdClass();
        foreach ($publicPaths as $path) {
            $segments = explode('.', $path);
            $value = $this->read($config, $segments, $path);
            $schemaNode = $this->resolvePropertyPath($schema, $segments, $path);
            $this->write($projected, $segments, $this->projectValue($value, $schemaNode, $schema, $path));
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

    /** @param list<string> $segments */
    private function resolvePropertyPath(stdClass $root, array $segments, string $path): stdClass
    {
        $node = $root;
        foreach ($segments as $segment) {
            $resolved = $this->dereference($node, $root, $path);
            if (($resolved->type ?? null) !== 'object'
                || !(($resolved->properties ?? null) instanceof stdClass)
                || !property_exists($resolved->properties, $segment)
                || !($resolved->properties->{$segment} instanceof stdClass)
            ) {
                throw new LandingOpsException('公开配置路径在 schema 中不存在：' . $path);
            }
            $node = $resolved->properties->{$segment};
        }
        return $node;
    }

    /** @param array<string,bool> $refStack */
    private function projectValue(
        mixed $value,
        stdClass $node,
        stdClass $root,
        string $path,
        array $refStack = [],
    ): mixed
    {
        if (($node->{'x-landingops-visibility'} ?? null) !== 'public') {
            throw new LandingOpsException('公开配置节点未显式标记 public：' . $path);
        }

        if (property_exists($node, '$ref')) {
            $reference = (string) $node->{'$ref'};
            if (isset($refStack[$reference])) {
                throw new LandingOpsException('公开配置包含循环 $ref：' . $path);
            }
            $refStack[$reference] = true;
            $target = $this->dereference($node, $root, $path);
            return $this->projectValue($value, $target, $root, $path . '.$ref', $refStack);
        }

        if (is_array($node->oneOf ?? null)) {
            foreach ($node->oneOf as $index => $branch) {
                if ($branch instanceof stdClass && $this->matchesDeclaredType($value, $branch, $root, $path)) {
                    return $this->projectValue($value, $branch, $root, $path . '.oneOf.' . $index, $refStack);
                }
            }
            throw new LandingOpsException('公开配置无法选择 oneOf 分支：' . $path);
        }

        if (!$this->matchesDeclaredType($value, $node, $root, $path)) {
            throw new LandingOpsException('公开配置值与 schema 类型不一致：' . $path);
        }

        if (($node->type ?? null) === 'object' && $value instanceof stdClass) {
            $output = new stdClass();
            $properties = ($node->properties ?? null) instanceof stdClass
                ? $node->properties
                : new stdClass();
            foreach (get_object_vars($value) as $name => $childValue) {
                $child = $properties->{$name} ?? null;
                if (!($child instanceof stdClass)
                    || ($child->{'x-landingops-visibility'} ?? null) !== 'public'
                ) {
                    continue;
                }
                $output->{$name} = $this->projectValue(
                    $childValue,
                    $child,
                    $root,
                    $path . '.' . $name,
                    $refStack,
                );
            }
            return $output;
        }

        if (($node->type ?? null) === 'array' && is_array($value)) {
            $items = $node->items ?? null;
            if (!($items instanceof stdClass)
                || ($items->{'x-landingops-visibility'} ?? null) !== 'public'
            ) {
                throw new LandingOpsException('公开数组 items 未显式标记 public：' . $path);
            }
            $output = [];
            foreach ($value as $index => $item) {
                $output[] = $this->projectValue($item, $items, $root, $path . '.' . $index, $refStack);
            }
            return $output;
        }

        return $this->cloneValue($value);
    }

    private function dereference(stdClass $node, stdClass $root, string $path): stdClass
    {
        if (!property_exists($node, '$ref')) {
            return $node;
        }
        $reference = $node->{'$ref'};
        if (!is_string($reference)
            || preg_match('~^\#/\$defs/([A-Za-z_][A-Za-z0-9_-]*)$~D', $reference, $matches) !== 1
            || !(($root->{'$defs'} ?? null) instanceof stdClass)
            || !(($root->{'$defs'}->{$matches[1]} ?? null) instanceof stdClass)
        ) {
            throw new LandingOpsException('公开配置包含无法解析的 $ref：' . $path);
        }
        return $root->{'$defs'}->{$matches[1]};
    }

    private function matchesDeclaredType(mixed $value, stdClass $node, stdClass $root, string $path): bool
    {
        $resolved = $this->dereference($node, $root, $path);
        return match ($resolved->type ?? null) {
            'object' => $value instanceof stdClass,
            'array' => is_array($value),
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }
}
