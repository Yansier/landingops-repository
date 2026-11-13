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
        $this->walk($schema, $schema, '', false, $fields, []);
        return [
            'schema_hash' => hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'fields' => $fields,
        ];
    }

    /** @param list<array<string,mixed>> $fields @param array<string,bool> $refs */
    private function walk(stdClass $node, stdClass $root, string $path, bool $required, array &$fields, array $refs): void
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
            $this->walk($this->merge($target, $node), $root, $path, $required, $fields, $refs);
            return;
        }

        $type = is_string($node->type ?? null) ? $node->type : null;
        $nullable = false;
        if ($type === null && is_array($node->oneOf ?? null)) {
            $branches = array_values(array_filter($node->oneOf, static fn (mixed $branch): bool => $branch instanceof stdClass));
            foreach ($branches as $branch) {
                if (($branch->type ?? null) === 'null') {
                    $nullable = true;
                    continue;
                }
                if ($type === null && is_string($branch->type ?? null)) {
                    $type = $branch->type;
                    $node = $this->merge($branch, $node);
                }
            }
        }

        if ($type === 'object') {
            $requiredNames = is_array($node->required ?? null) ? array_map('strval', $node->required) : [];
            $properties = ($node->properties ?? null) instanceof stdClass ? $node->properties : new stdClass();
            foreach (get_object_vars($properties) as $name => $child) {
                if ($child instanceof stdClass) {
                    $childPath = $path === '' ? $name : $path . '.' . $name;
                    $this->walk($child, $root, $childPath, in_array($name, $requiredNames, true), $fields, $refs);
                }
            }
            return;
        }
        if ($path === '' || !in_array($type, ['string', 'number', 'integer', 'boolean', 'array'], true)) {
            return;
        }

        $itemType = null;
        if ($type === 'array' && ($node->items ?? null) instanceof stdClass) {
            $itemType = is_string($node->items->type ?? null) ? $node->items->type : null;
        }
        $fields[] = array_filter([
            'path' => $path,
            'label' => $node->title ?? str_replace(['_', '.'], ' ', $path),
            'description' => $node->description ?? null,
            'type' => $type,
            'item_type' => $itemType,
            'required' => $required,
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
        ], static fn (mixed $value): bool => $value !== null);
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
