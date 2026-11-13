<?php

declare(strict_types=1);

namespace LandingOps\Contract;

use LandingOps\Validation\ValidationResult;
use stdClass;

final class SchemaContractValidator
{
    /** @var list<string> */
    private const ALLOWED_KEYWORDS = [
        '$schema', '$id', '$ref', '$defs', 'title', 'description',
        'type', 'additionalProperties', 'required', 'properties', 'items',
        'maxItems', 'minItems', 'uniqueItems', 'default', 'pattern',
        'minimum', 'maximum', 'minLength', 'maxLength', 'format',
        'oneOf', 'enum', 'const', 'x-landingops-visibility',
    ];

    /** @var list<string> */
    private const TYPES = ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'];

    public function validate(mixed $schema): ValidationResult
    {
        $result = new ValidationResult();
        if (!($schema instanceof stdClass)) {
            $result->addError('schema_root_type', 'config.schema.json 根必须是 object。', '$');
            return $result;
        }

        if (($schema->{'$schema'} ?? null) !== 'https://json-schema.org/draft/2020-12/schema') {
            $result->addError('schema_draft', '仅支持 JSON Schema Draft 2020-12。', '$.$schema');
        }
        if (($schema->type ?? null) !== 'object') {
            $result->addError('schema_root_object', '配置 schema 根 type 必须是 object。', '$.type');
        }
        if (($schema->additionalProperties ?? null) !== false) {
            $result->addError('schema_root_closed', '配置 schema 根必须设置 additionalProperties=false。', '$.additionalProperties');
        }

        $this->node($schema, '$', $result);
        $this->references($schema, '$', $schema, $result);
        return $result;
    }

    /** @param list<string> $publicPaths */
    public function validatePublicPaths(stdClass $schema, array $publicPaths): ValidationResult
    {
        $result = new ValidationResult();
        foreach ($publicPaths as $path) {
            $node = $this->resolvePropertyPath($schema, $path);
            if (!($node instanceof stdClass)) {
                $result->addError('schema_public_path_missing', '公开路径在 schema 中不存在。', $path);
                continue;
            }
            if (($node->{'x-landingops-visibility'} ?? null) !== 'public') {
                $result->addError('schema_public_visibility', '公开路径必须显式标记 x-landingops-visibility=public。', $path);
            }
            if ($this->containsPrivateNode($node, $schema)) {
                $result->addError('schema_public_contains_private', '公开路径的子树不得包含 private 字段。', $path);
            }
        }

        return $result;
    }

    public function resolvePropertyPath(stdClass $schema, string $path): ?stdClass
    {
        $node = $schema;
        foreach (explode('.', $path) as $segment) {
            if (($node->type ?? null) !== 'object'
                || !(($node->properties ?? null) instanceof stdClass)
                || !property_exists($node->properties, $segment)
                || !($node->properties->{$segment} instanceof stdClass)
            ) {
                return null;
            }
            $node = $node->properties->{$segment};
        }

        return $node;
    }

    private function node(stdClass $node, string $path, ValidationResult $result): void
    {
        foreach (array_keys(get_object_vars($node)) as $keyword) {
            if (!in_array($keyword, self::ALLOWED_KEYWORDS, true)) {
                $result->addError('schema_unknown_keyword', '受限 schema 不支持此关键字。', $path . '.' . $keyword);
            }
        }

        if (property_exists($node, '$ref')) {
            if (!is_string($node->{'$ref'}) || preg_match('~^\#/\$defs/[A-Za-z_][A-Za-z0-9_-]*$~D', $node->{'$ref'}) !== 1) {
                $result->addError('schema_remote_ref', '只允许 #/$defs/name 形式的本地引用。', $path . '.$ref');
            }
            return;
        }

        if (property_exists($node, 'type') && (!is_string($node->type) || !in_array($node->type, self::TYPES, true))) {
            $result->addError('schema_type', 'schema type 不受支持。', $path . '.type');
        }
        if (property_exists($node, 'additionalProperties') && !is_bool($node->additionalProperties)) {
            $result->addError('schema_additional_properties', 'additionalProperties 只能是 boolean。', $path . '.additionalProperties');
        }
        foreach (['maxItems', 'minItems', 'minLength', 'maxLength'] as $integerKeyword) {
            if (property_exists($node, $integerKeyword)
                && (!is_int($node->{$integerKeyword}) || $node->{$integerKeyword} < 0)
            ) {
                $result->addError('schema_integer_keyword', $integerKeyword . ' 必须是非负整数。', $path . '.' . $integerKeyword);
            }
        }
        foreach (['minimum', 'maximum'] as $numberKeyword) {
            if (property_exists($node, $numberKeyword)
                && !is_int($node->{$numberKeyword})
                && !is_float($node->{$numberKeyword})
            ) {
                $result->addError('schema_number_keyword', $numberKeyword . ' 必须是 number。', $path . '.' . $numberKeyword);
            }
        }
        if (property_exists($node, 'uniqueItems') && !is_bool($node->uniqueItems)) {
            $result->addError('schema_unique_items', 'uniqueItems 必须是 boolean。', $path . '.uniqueItems');
        }
        if (property_exists($node, 'enum') && (!is_array($node->enum) || $node->enum === [])) {
            $result->addError('schema_enum', 'enum 必须是非空数组。', $path . '.enum');
        }
        foreach (['$id', 'title', 'description'] as $stringKeyword) {
            if (property_exists($node, $stringKeyword) && !is_string($node->{$stringKeyword})) {
                $result->addError('schema_string_keyword', $stringKeyword . ' 必须是 string。', $path . '.' . $stringKeyword);
            }
        }
        if (property_exists($node, 'required')) {
            if (!is_array($node->required)) {
                $result->addError('schema_required_type', 'required 必须是数组。', $path . '.required');
            } else {
                $seen = [];
                foreach ($node->required as $index => $name) {
                    if (!is_string($name) || $name === '' || isset($seen[$name])) {
                        $result->addError('schema_required_value', 'required 必须由不重复的非空字符串组成。', $path . '.required.' . $index);
                    }
                    $seen[(string) $name] = true;
                    if (is_string($name)
                        && ($node->properties ?? null) instanceof stdClass
                        && !property_exists($node->properties, $name)
                    ) {
                        $result->addError('schema_required_missing_property', 'required 字段必须在 properties 中定义。', $path . '.required.' . $index);
                    }
                }
            }
        }
        if (property_exists($node, 'properties')) {
            if (!($node->properties instanceof stdClass)) {
                $result->addError('schema_properties_type', 'properties 必须是 object。', $path . '.properties');
            } else {
                foreach (get_object_vars($node->properties) as $name => $child) {
                    if (!($child instanceof stdClass)) {
                        $result->addError('schema_node_type', '属性 schema 必须是 object。', $path . '.properties.' . $name);
                        continue;
                    }
                    $this->node($child, $path . '.properties.' . $name, $result);
                }
            }
        }
        if (property_exists($node, 'items')) {
            if (!($node->items instanceof stdClass)) {
                $result->addError('schema_items_type', 'items 必须是 object。', $path . '.items');
            } else {
                $this->node($node->items, $path . '.items', $result);
            }
        }
        if (property_exists($node, '$defs')) {
            if (!($node->{'$defs'} instanceof stdClass)) {
                $result->addError('schema_defs_type', '$defs 必须是 object。', $path . '.$defs');
            } else {
                foreach (get_object_vars($node->{'$defs'}) as $name => $child) {
                    if (!($child instanceof stdClass)) {
                        $result->addError('schema_node_type', '$defs 子项必须是 object。', $path . '.$defs.' . $name);
                        continue;
                    }
                    $this->node($child, $path . '.$defs.' . $name, $result);
                }
            }
        }
        if (property_exists($node, 'oneOf')) {
            if (!is_array($node->oneOf) || count($node->oneOf) < 1) {
                $result->addError('schema_one_of', 'oneOf 必须是非空数组。', $path . '.oneOf');
            } else {
                foreach ($node->oneOf as $index => $child) {
                    if (!($child instanceof stdClass)) {
                        $result->addError('schema_node_type', 'oneOf 子项必须是 object。', $path . '.oneOf.' . $index);
                        continue;
                    }
                    $this->node($child, $path . '.oneOf.' . $index, $result);
                }
            }
        }
        if (property_exists($node, 'pattern') && (!is_string($node->pattern) || @preg_match('/' . str_replace('/', '\\/', $node->pattern) . '/u', '') === false)) {
            $result->addError('schema_pattern', 'pattern 不是有效正则表达式。', $path . '.pattern');
        }
        if (property_exists($node, 'format') && $node->format !== 'uri') {
            $result->addError('schema_format', '受限 schema 只支持 uri format。', $path . '.format');
        }
        if (property_exists($node, 'x-landingops-visibility')
            && !in_array($node->{'x-landingops-visibility'}, ['public', 'private'], true)
        ) {
            $result->addError('schema_visibility', 'visibility 仅允许 public 或 private。', $path . '.x-landingops-visibility');
        }
    }

    /** @param array<string,bool> $visitedRefs */
    private function containsPrivateNode(stdClass $node, stdClass $root, array $visitedRefs = []): bool
    {
        if (($node->{'x-landingops-visibility'} ?? null) === 'private') {
            return true;
        }
        if (is_string($node->{'$ref'} ?? null)
            && preg_match('~^\#/\$defs/([A-Za-z_][A-Za-z0-9_-]*)$~D', $node->{'$ref'}, $matches) === 1
            && !isset($visitedRefs[$matches[1]])
        ) {
            $defs = $root->{'$defs'} ?? null;
            if ($defs instanceof stdClass && ($defs->{$matches[1]} ?? null) instanceof stdClass) {
                $visitedRefs[$matches[1]] = true;
                if ($this->containsPrivateNode($defs->{$matches[1]}, $root, $visitedRefs)) {
                    return true;
                }
            }
        }
        if (($node->properties ?? null) instanceof stdClass) {
            foreach (get_object_vars($node->properties) as $child) {
                if ($child instanceof stdClass && $this->containsPrivateNode($child, $root, $visitedRefs)) {
                    return true;
                }
            }
        }
        if (($node->items ?? null) instanceof stdClass && $this->containsPrivateNode($node->items, $root, $visitedRefs)) {
            return true;
        }
        return false;
    }

    private function references(stdClass $node, string $path, stdClass $root, ValidationResult $result): void
    {
        if (property_exists($node, '$ref') && is_string($node->{'$ref'})) {
            if (preg_match('~^\#/\$defs/([A-Za-z_][A-Za-z0-9_-]*)$~D', $node->{'$ref'}, $matches) === 1) {
                $defs = $root->{'$defs'} ?? null;
                if (!($defs instanceof stdClass) || !property_exists($defs, $matches[1]) || !($defs->{$matches[1]} instanceof stdClass)) {
                    $result->addError('schema_ref_missing', '本地 $ref 指向不存在的 $defs。', $path . '.$ref');
                }
            }
            return;
        }
        if (($node->properties ?? null) instanceof stdClass) {
            foreach (get_object_vars($node->properties) as $name => $child) {
                if ($child instanceof stdClass) {
                    $this->references($child, $path . '.properties.' . $name, $root, $result);
                }
            }
        }
        if (($node->items ?? null) instanceof stdClass) {
            $this->references($node->items, $path . '.items', $root, $result);
        }
        if (($node->{'$defs'} ?? null) instanceof stdClass) {
            foreach (get_object_vars($node->{'$defs'}) as $name => $child) {
                if ($child instanceof stdClass) {
                    $this->references($child, $path . '.$defs.' . $name, $root, $result);
                }
            }
        }
        if (is_array($node->oneOf ?? null)) {
            foreach ($node->oneOf as $index => $child) {
                if ($child instanceof stdClass) {
                    $this->references($child, $path . '.oneOf.' . $index, $root, $result);
                }
            }
        }
    }
}
