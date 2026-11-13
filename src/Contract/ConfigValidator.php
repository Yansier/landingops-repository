<?php

declare(strict_types=1);

namespace LandingOps\Contract;

use LandingOps\Validation\ValidationResult;
use stdClass;

final class ConfigValidator
{
    public function validate(mixed $config, stdClass $schema): ValidationResult
    {
        $result = new ValidationResult();
        $this->value($config, $schema, '$', $schema, $result);
        return $result;
    }

    private function value(mixed $value, stdClass $schema, string $path, stdClass $root, ValidationResult $result): void
    {
        if (property_exists($schema, '$ref')) {
            $target = $this->resolveRef($root, (string) $schema->{'$ref'});
            if (!($target instanceof stdClass)) {
                $result->addError('config_schema_ref', 'schema 本地引用无法解析。', $path);
                return;
            }
            $this->value($value, $target, $path, $root, $result);
            return;
        }

        if (property_exists($schema, 'oneOf')) {
            $validBranches = 0;
            foreach ($schema->oneOf as $branch) {
                $branchResult = new ValidationResult();
                $this->value($value, $branch, $path, $root, $branchResult);
                if ($branchResult->isValid()) {
                    $validBranches++;
                }
            }
            if ($validBranches !== 1) {
                $result->addError('config_one_of', '配置值必须且只能匹配一个 oneOf 分支。', $path);
            }
            return;
        }

        $type = $schema->type ?? null;
        if (is_string($type) && !$this->matchesType($value, $type)) {
            $result->addError('config_type', sprintf('配置值类型必须为 %s。', $type), $path);
            return;
        }

        if (property_exists($schema, 'const') && $value !== $schema->const) {
            $result->addError('config_const', '配置值不符合 const。', $path);
        }
        if (property_exists($schema, 'enum') && is_array($schema->enum)) {
            $found = false;
            foreach ($schema->enum as $candidate) {
                if (Json::canonical($candidate) === Json::canonical($value)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $result->addError('config_enum', '配置值不在 enum 中。', $path);
            }
        }

        if ($type === 'object' && $value instanceof stdClass) {
            $properties = ($schema->properties ?? null) instanceof stdClass ? $schema->properties : new stdClass();
            $required = is_array($schema->required ?? null) ? $schema->required : [];
            foreach ($required as $requiredName) {
                if (!property_exists($value, $requiredName)) {
                    $result->addError('config_required', '缺少必需配置字段。', $path . '.' . $requiredName);
                }
            }
            foreach (get_object_vars($value) as $name => $childValue) {
                if (!property_exists($properties, $name)) {
                    if (($schema->additionalProperties ?? true) === false) {
                        $result->addError('config_unknown_field', '配置包含未知字段。', $path . '.' . $name);
                    }
                    continue;
                }
                $this->value($childValue, $properties->{$name}, $path . '.' . $name, $root, $result);
            }
        }

        if ($type === 'array' && is_array($value)) {
            if (isset($schema->minItems) && count($value) < $schema->minItems) {
                $result->addError('config_min_items', '数组项目少于限制。', $path);
            }
            if (isset($schema->maxItems) && count($value) > $schema->maxItems) {
                $result->addError('config_max_items', '数组项目超过限制。', $path);
            }
            if (($schema->uniqueItems ?? false) === true) {
                $seen = [];
                foreach ($value as $index => $item) {
                    $hash = hash('sha256', Json::canonical($item));
                    if (isset($seen[$hash])) {
                        $result->addError('config_unique_items', '数组项目必须唯一。', $path . '.' . $index);
                    }
                    $seen[$hash] = true;
                }
            }
            if (($schema->items ?? null) instanceof stdClass) {
                foreach ($value as $index => $item) {
                    $this->value($item, $schema->items, $path . '.' . $index, $root, $result);
                }
            }
        }

        if ($type === 'string' && is_string($value)) {
            $length = $this->length($value);
            if (isset($schema->minLength) && $length < $schema->minLength) {
                $result->addError('config_min_length', '字符串短于限制。', $path);
            }
            if (isset($schema->maxLength) && $length > $schema->maxLength) {
                $result->addError('config_max_length', '字符串超过限制。', $path);
            }
            if (isset($schema->pattern) && @preg_match('/' . str_replace('/', '\\/', $schema->pattern) . '/u', $value) !== 1) {
                $result->addError('config_pattern', '字符串不符合 pattern。', $path);
            }
            if (($schema->format ?? null) === 'uri' && filter_var($value, FILTER_VALIDATE_URL) === false) {
                $result->addError('config_uri', '字符串不是有效 URI。', $path);
            }
        }

        if (($type === 'number' || $type === 'integer') && (is_int($value) || is_float($value))) {
            $numeric = (float) $value;
            if (!is_finite($numeric)) {
                $result->addError('config_finite', '数值必须有限。', $path);
            }
            if (isset($schema->minimum) && $numeric < $schema->minimum) {
                $result->addError('config_minimum', '数值低于 minimum。', $path);
            }
            if (isset($schema->maximum) && $numeric > $schema->maximum) {
                $result->addError('config_maximum', '数值高于 maximum。', $path);
            }
        }
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
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

    private function resolveRef(stdClass $root, string $reference): ?stdClass
    {
        if (preg_match('~^\#/\$defs/([A-Za-z_][A-Za-z0-9_-]*)$~D', $reference, $matches) !== 1) {
            return null;
        }

        $defs = $root->{'$defs'} ?? null;
        if (!($defs instanceof stdClass) || !property_exists($defs, $matches[1])) {
            return null;
        }

        $target = $defs->{$matches[1]};
        return $target instanceof stdClass ? $target : null;
    }

    private function length(string $value): int
    {
        preg_match_all('/./us', $value, $matches);
        return count($matches[0]);
    }
}
