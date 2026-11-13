<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Service;

use LandingOps\ControlPlane\Exception\ApiException;
use stdClass;

final class ConfigFormSchemaBuilder
{
    /** @return array{schema_hash:string,fields:list<array<string,mixed>>} */
    public function build(stdClass $schema): array
    {
        $fields = [];
        $this->walk($schema, $schema, '', true, $fields, [], []);
        return [
            'schema_hash' => hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'fields' => $fields,
        ];
    }

    /** @param list<array<string,mixed>> $fields @param array<string,bool> $refs @param list<string> $optionalAncestors */
    private function walk(stdClass $node, stdClass $root, string $path, bool $required, array &$fields, array $refs, array $optionalAncestors): void
    {
        if (property_exists($node, '$ref')) {
            $ref = (string) $node->{'$ref'};
            if (isset($refs[$ref])) {
                throw new ApiException(422, 'config_schema_cycle', '模板配置 Schema 存在循环引用。');
            }
            $target = $this->resolve($root, $ref);
            if ($target === null) {
                throw new ApiException(422, 'config_schema_ref', '模板配置 Schema 引用无法解析。');
            }
            $refs[$ref] = true;
            $this->walk($this->merge($target, $node), $root, $path, $required, $fields, $refs, $optionalAncestors);
            return;
        }

        $this->rejectUnsupportedComposition($node, $path);
        $type = is_string($node->type ?? null) ? $node->type : null;
        $nullable = false;
        if ($type !== null && property_exists($node, 'oneOf')) {
            $this->unsupported($path, '不能同时声明 type 与 oneOf。');
        }
        if ($type === null && property_exists($node, 'oneOf')) {
            if (!is_array($node->oneOf) || count($node->oneOf) !== 2) {
                $this->unsupported($path, '仅支持由 null 与一个标量类型组成的 oneOf。');
            }
            $branches = array_values(array_filter($node->oneOf, static fn (mixed $branch): bool => $branch instanceof stdClass));
            if (count($branches) !== 2) {
                $this->unsupported($path, 'oneOf 分支必须是对象。');
            }
            $nonNull = [];
            foreach ($branches as $branch) {
                if (($branch->type ?? null) === 'null') {
                    $nullable = true;
                    continue;
                }
                $nonNull[] = $branch;
            }
            if (!$nullable || count($nonNull) !== 1 || !is_string($nonNull[0]->type ?? null)) {
                $this->unsupported($path, 'oneOf 必须且只能包含 null 与一个受支持类型。');
            }
            $type = $nonNull[0]->type;
            $node = $this->merge($nonNull[0], $node);
        }

        if ($type === 'object') {
            if (!(($node->properties ?? null) instanceof stdClass)) {
                $this->unsupported($path, '对象必须声明 properties。');
            }
            if (($node->additionalProperties ?? null) !== false) {
                $this->unsupported($path, '对象必须以 additionalProperties=false 固定可编辑字段。');
            }
            $requiredNames = is_array($node->required ?? null) ? array_map('strval', $node->required) : [];
            $properties = $node->properties;
            if ($path !== '' && !$required) {
                $optionalAncestors[] = $path;
            }
            foreach ($requiredNames as $requiredName) {
                if (!property_exists($properties, $requiredName)) {
                    $this->unsupported($path, 'required 引用了不存在的属性。');
                }
            }
            foreach (get_object_vars($properties) as $name => $child) {
                if (!$child instanceof stdClass) {
                    $this->unsupported($path, 'properties 中的字段定义必须是对象。');
                }
                $childPath = $path === '' ? $name : $path . '.' . $name;
                $this->walk($child, $root, $childPath, in_array($name, $requiredNames, true), $fields, $refs, $optionalAncestors);
            }
            return;
        }
        if ($path === '') {
            $this->unsupported($path, 'Schema 根节点必须是 object。');
        }
        if (!in_array($type, ['string', 'number', 'integer', 'boolean', 'array'], true)) {
            $this->unsupported($path, '字段类型不受当前配置向导支持。');
        }

        $itemType = null;
        $itemEnum = null;
        $items = null;
        if ($type === 'array') {
            if (!(($node->items ?? null) instanceof stdClass)) {
                $this->unsupported($path, '数组必须声明单一 items Schema。');
            }
            $items = $this->dereference($node->items, $root, $refs);
            $this->rejectUnsupportedComposition($items, $path . '[]');
            $itemType = is_string($items->type ?? null) ? $items->type : null;
            if (!in_array($itemType, ['string', 'number', 'integer', 'boolean'], true)) {
                $this->unsupported($path, '数组 items 只支持 string、number、integer 或 boolean。');
            }
            $itemEnum = is_array($items->enum ?? null) ? $items->enum : null;
        }
        $fields[] = array_filter([
            'path' => $path,
            'label' => $node->title ?? str_replace(['_', '.'], ' ', $path),
            'description' => $node->description ?? null,
            'type' => $type,
            'item_type' => $itemType,
            'item_enum' => $itemEnum,
            'item_pattern' => $items?->pattern ?? null,
            'item_minimum' => $items?->minimum ?? null,
            'item_maximum' => $items?->maximum ?? null,
            'item_min_length' => $items?->minLength ?? null,
            'item_max_length' => $items?->maxLength ?? null,
            'required' => $required && $optionalAncestors === [],
            'conditionally_required' => $required && $optionalAncestors !== [],
            'optional_ancestors' => $optionalAncestors,
            'nullable' => $nullable,
            'visibility' => ($node->{'x-landingops-visibility'} ?? null) === 'public' ? 'public' : 'private',
            'format' => $node->format ?? null,
            'pattern' => $node->pattern ?? null,
            'minimum' => $node->minimum ?? null,
            'maximum' => $node->maximum ?? null,
            'min_length' => $node->minLength ?? null,
            'max_length' => $node->maxLength ?? null,
            'min_items' => $node->minItems ?? null,
            'max_items' => $node->maxItems ?? null,
            'enum' => is_array($node->enum ?? null) ? $node->enum : null,
            'default' => property_exists($node, 'default') ? $node->default : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string,bool> $refs */
    private function dereference(stdClass $node, stdClass $root, array $refs): stdClass
    {
        if (!property_exists($node, '$ref')) {
            return $node;
        }
        $ref = (string) $node->{'$ref'};
        if (isset($refs[$ref])) {
            throw new ApiException(422, 'config_schema_cycle', '模板配置 Schema 存在循环引用。');
        }
        $target = $this->resolve($root, $ref);
        if ($target === null) {
            throw new ApiException(422, 'config_schema_ref', '模板配置 Schema 引用无法解析。');
        }
        $refs[$ref] = true;
        return $this->dereference($this->merge($target, $node), $root, $refs);
    }

    private function rejectUnsupportedComposition(stdClass $node, string $path): void
    {
        foreach (['anyOf', 'allOf', 'not', 'if', 'then', 'else', 'patternProperties', 'dependentSchemas', 'prefixItems'] as $keyword) {
            if (property_exists($node, $keyword)) {
                $this->unsupported($path, "不支持 Schema 关键字 {$keyword}。");
            }
        }
        if (is_array($node->type ?? null)) {
            $this->unsupported($path, '不支持数组形式的 type，请使用受限 nullable oneOf。');
        }
    }

    private function unsupported(string $path, string $reason): never
    {
        throw new ApiException(422, 'config_form_schema_unsupported', '模板配置 Schema 包含当前向导不支持的结构。', [
            'path' => $path === '' ? '$' : $path,
            'reason' => $reason,
        ]);
    }

    private function resolve(stdClass $root, string $ref): ?stdClass
    {
        if (preg_match('~^#/\$defs/([A-Za-z_][A-Za-z0-9_-]*)$~D', $ref, $matches) !== 1
            || !(($root->{'$defs'} ?? null) instanceof stdClass)
        ) {
            return null;
        }
        $target = $root->{'$defs'}->{$matches[1]} ?? null;
        return $target instanceof stdClass ? $target : null;
    }

    private function merge(stdClass $base, stdClass $overlay): stdClass
    {
        $result = clone $base;
        foreach (get_object_vars($overlay) as $key => $value) {
            if ($key !== '$ref' && $key !== 'oneOf') {
                $result->{$key} = $value;
            }
        }
        return $result;
    }
}
