<?php

declare(strict_types=1);

namespace LandingOps\Contract;

use LandingOps\Validation\ValidationResult;
use stdClass;

final class ManifestValidator
{
    /** @var list<string> */
    private const EVENTS = ['Download', 'AddToCart', 'Purchase'];

    public function validate(mixed $manifest): ValidationResult
    {
        $result = new ValidationResult();
        if (!($manifest instanceof stdClass)) {
            $result->addError('manifest_root_type', 'landingops.json 根必须是 object。');
            return $result;
        }

        $this->keys($manifest, [
            'standard', 'standard_version', 'template', 'entry', 'runtime',
            'capabilities', 'native_admin', 'config', 'integrity',
        ], [
            'standard', 'standard_version', 'template', 'entry', 'runtime',
            'capabilities', 'native_admin', 'config',
        ], '$', $result);

        $this->fixedString($manifest, 'standard', 'landingops-template', '$.standard', $result);
        $this->fixedString($manifest, 'standard_version', '1.0', '$.standard_version', $result);
        $this->template($manifest->template ?? null, $result);
        $this->entry($manifest->entry ?? null, $result);
        $this->runtime($manifest->runtime ?? null, $result);
        $this->capabilities($manifest->capabilities ?? null, $result);
        $this->nativeAdmin($manifest->native_admin ?? null, $result);
        $this->config($manifest->config ?? null, $result);

        if (isset($manifest->integrity)) {
            if (!($manifest->integrity instanceof stdClass)) {
                $result->addError('manifest_type', 'integrity 必须是 object。', '$.integrity');
            } else {
                $this->keys($manifest->integrity, ['algorithm'], ['algorithm'], '$.integrity', $result);
                $this->fixedString($manifest->integrity, 'algorithm', 'sha256', '$.integrity.algorithm', $result);
            }
        }

        $this->crossConsistency($manifest, $result);

        return $result;
    }

    private function template(mixed $value, ValidationResult $result): void
    {
        if (!($value instanceof stdClass)) {
            $result->addError('manifest_type', 'template 必须是 object。', '$.template');
            return;
        }
        $this->keys($value, ['slug', 'name', 'version', 'description'], ['slug', 'name', 'version', 'description'], '$.template', $result);
        $slug = $this->string($value, 'slug', '$.template.slug', $result);
        if ($slug !== null && preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/D', $slug) !== 1) {
            $result->addError('manifest_slug', 'template.slug 格式不符合 LTS v1.0。', '$.template.slug');
        }
        $name = $this->string($value, 'name', '$.template.name', $result);
        if ($name !== null && ($this->length($name) < 1 || $this->length($name) > 100)) {
            $result->addError('manifest_length', 'template.name 长度必须为 1–100。', '$.template.name');
        }
        $version = $this->string($value, 'version', '$.template.version', $result);
        if ($version !== null && preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $version) !== 1) {
            $result->addError('manifest_semver', 'template.version 必须是 SemVer。', '$.template.version');
        }
        $description = $this->string($value, 'description', '$.template.description', $result);
        if ($description !== null && $this->length($description) > 500) {
            $result->addError('manifest_length', 'template.description 最多 500 字符。', '$.template.description');
        }
    }

    private function entry(mixed $value, ValidationResult $result): void
    {
        if (!($value instanceof stdClass)) {
            $result->addError('manifest_type', 'entry 必须是 object。', '$.entry');
            return;
        }
        $this->keys($value, ['source', 'asset_base'], ['source', 'asset_base'], '$.entry', $result);
        $this->fixedString($value, 'source', 'index.html', '$.entry.source', $result);
        $this->fixedString($value, 'asset_base', './', '$.entry.asset_base', $result);
    }

    private function runtime(mixed $value, ValidationResult $result): void
    {
        if (!($value instanceof stdClass)) {
            $result->addError('manifest_type', 'runtime 必须是 object。', '$.runtime');
            return;
        }
        $this->keys($value, ['php', 'vendor_component'], ['php', 'vendor_component'], '$.runtime', $result);

        if (!(($value->php ?? null) instanceof stdClass)) {
            $result->addError('manifest_type', 'runtime.php 必须是 object。', '$.runtime.php');
        } else {
            $php = $value->php;
            $this->keys($php, ['required', 'constraint'], ['required', 'constraint'], '$.runtime.php', $result);
            $required = $this->boolean($php, 'required', '$.runtime.php.required', $result);
            if ($required === true && (!isset($php->constraint) || !is_string($php->constraint) || $php->constraint === '')) {
                $result->addError('manifest_php_constraint', '需要 PHP 时必须声明版本约束。', '$.runtime.php.constraint');
            }
            if ($required === false && ($php->constraint ?? null) !== null) {
                $result->addError('manifest_php_constraint', '不需要 PHP 时 constraint 必须为 null。', '$.runtime.php.constraint');
            }
        }

        if (!(($value->vendor_component ?? null) instanceof stdClass)) {
            $result->addError('manifest_type', 'runtime.vendor_component 必须是 object。', '$.runtime.vendor_component');
        } else {
            $vendor = $value->vendor_component;
            $this->keys($vendor, ['required', 'compatibility'], ['required', 'compatibility'], '$.runtime.vendor_component', $result);
            $required = $this->boolean($vendor, 'required', '$.runtime.vendor_component.required', $result);
            if ($required === true && (!isset($vendor->compatibility) || !is_string($vendor->compatibility) || $vendor->compatibility === '')) {
                $result->addError('manifest_vendor_compatibility', '需要供应商组件时必须声明兼容约束。', '$.runtime.vendor_component.compatibility');
            }
            if ($required === false && ($vendor->compatibility ?? null) !== null) {
                $result->addError('manifest_vendor_compatibility', '不需要供应商组件时 compatibility 必须为 null。', '$.runtime.vendor_component.compatibility');
            }
        }
    }

    private function capabilities(mixed $value, ValidationResult $result): void
    {
        if (!($value instanceof stdClass)) {
            $result->addError('manifest_type', 'capabilities 必须是 object。', '$.capabilities');
            return;
        }
        $this->keys($value, ['configurable', 'pixel_ids', 'events', 'links', 'custom_config'], ['configurable', 'pixel_ids', 'events', 'links', 'custom_config'], '$.capabilities', $result);
        $this->boolean($value, 'configurable', '$.capabilities.configurable', $result);
        $this->boolean($value, 'custom_config', '$.capabilities.custom_config', $result);

        if (!(($value->pixel_ids ?? null) instanceof stdClass)) {
            $result->addError('manifest_type', 'capabilities.pixel_ids 必须是 object。', '$.capabilities.pixel_ids');
        } else {
            $pixel = $value->pixel_ids;
            $this->keys($pixel, ['multiple', 'max_items'], ['multiple', 'max_items'], '$.capabilities.pixel_ids', $result);
            if ($this->boolean($pixel, 'multiple', '$.capabilities.pixel_ids.multiple', $result) !== true) {
                $result->addError('manifest_pixel_multiple', 'LTS v1.0 必须支持多个 Pixel ID。', '$.capabilities.pixel_ids.multiple');
            }
            if (!isset($pixel->max_items) || !is_int($pixel->max_items) || $pixel->max_items < 1 || $pixel->max_items > 100) {
                $result->addError('manifest_pixel_limit', 'pixel_ids.max_items 必须是 1–100 的整数。', '$.capabilities.pixel_ids.max_items');
            }
        }

        if (!isset($value->events) || !is_array($value->events)) {
            $result->addError('manifest_type', 'capabilities.events 必须是数组。', '$.capabilities.events');
        } else {
            $seen = [];
            foreach ($value->events as $index => $event) {
                if (!is_string($event) || !in_array($event, self::EVENTS, true)) {
                    $result->addError('manifest_event', '事件仅允许 Download、AddToCart、Purchase。', '$.capabilities.events.' . $index);
                } elseif (isset($seen[$event])) {
                    $result->addError('manifest_event_duplicate', '事件声明不得重复。', '$.capabilities.events.' . $index);
                }
                $seen[(string) $event] = true;
            }
        }

        if (!(($value->links ?? null) instanceof stdClass)) {
            $result->addError('manifest_type', 'capabilities.links 必须是 object。', '$.capabilities.links');
        } else {
            $links = $value->links;
            $this->keys($links, ['download', 'redirect'], ['download', 'redirect'], '$.capabilities.links', $result);
            $this->boolean($links, 'download', '$.capabilities.links.download', $result);
            $this->boolean($links, 'redirect', '$.capabilities.links.redirect', $result);
        }
    }

    private function nativeAdmin(mixed $value, ValidationResult $result): void
    {
        if (!($value instanceof stdClass)) {
            $result->addError('manifest_type', 'native_admin 必须是 object。', '$.native_admin');
            return;
        }
        $this->keys($value, ['supported', 'entry', 'auth', 'default_enabled', 'config_contract'], ['supported', 'entry', 'auth', 'default_enabled', 'config_contract'], '$.native_admin', $result);
        $supported = $this->boolean($value, 'supported', '$.native_admin.supported', $result);
        if ($this->boolean($value, 'default_enabled', '$.native_admin.default_enabled', $result) !== false) {
            $result->addError('manifest_admin_default', '原生后台必须默认关闭。', '$.native_admin.default_enabled');
        }
        $this->fixedString($value, 'config_contract', 'lts-local-config/1.0', '$.native_admin.config_contract', $result);

        if ($supported === true) {
            $this->fixedString($value, 'entry', 'admin/index.php', '$.native_admin.entry', $result);
            $this->fixedString($value, 'auth', 'local_password', '$.native_admin.auth', $result);
        } elseif ($supported === false) {
            if (($value->entry ?? null) !== null || ($value->auth ?? null) !== null) {
                $result->addError('manifest_admin_disabled', '不支持原生后台时 entry 和 auth 必须为 null。', '$.native_admin');
            }
        }
    }

    private function config(mixed $value, ValidationResult $result): void
    {
        if (!($value instanceof stdClass)) {
            $result->addError('manifest_type', 'config 必须是 object。', '$.config');
            return;
        }
        $this->keys($value, ['schema', 'local_path', 'schema_version', 'delivery'], ['schema', 'local_path', 'schema_version', 'delivery'], '$.config', $result);
        if (!property_exists($value, 'schema') || (!is_string($value->schema) && $value->schema !== null)) {
            $result->addError('manifest_type', 'config.schema 必须是 string 或 null。', '$.config.schema');
        }
        $this->fixedString($value, 'local_path', '.landingops/config.json', '$.config.local_path', $result);
        $schemaVersion = $this->string($value, 'schema_version', '$.config.schema_version', $result);
        if ($schemaVersion !== null && preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D', $schemaVersion) !== 1) {
            $result->addError('manifest_schema_version', 'config.schema_version 必须是三段版本号。', '$.config.schema_version');
        }

        if (!(($value->delivery ?? null) instanceof stdClass)) {
            $result->addError('manifest_type', 'config.delivery 必须是 object。', '$.config.delivery');
            return;
        }
        $delivery = $value->delivery;
        $this->keys($delivery, ['mode', 'script_placeholder', 'global_name', 'public_paths'], ['mode', 'script_placeholder', 'global_name', 'public_paths'], '$.config.delivery', $result);
        $this->fixedString($delivery, 'mode', 'generated_js', '$.config.delivery.mode', $result);
        $this->fixedString($delivery, 'script_placeholder', '__LANDINGOPS_PUBLIC_CONFIG_ASSET__', '$.config.delivery.script_placeholder', $result);
        $this->fixedString($delivery, 'global_name', '__LANDINGOPS_CONFIG__', '$.config.delivery.global_name', $result);

        if (!property_exists($delivery, 'public_paths') || !is_array($delivery->public_paths)) {
            $result->addError('manifest_public_paths', 'delivery.public_paths 必须是数组。', '$.config.delivery.public_paths');
            return;
        }
        $paths = [];
        foreach ($delivery->public_paths as $index => $path) {
            if (!is_string($path) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/D', $path) !== 1) {
                $result->addError('manifest_public_path', '公开配置路径格式非法。', '$.config.delivery.public_paths.' . $index);
                continue;
            }
            if (isset($paths[$path])) {
                $result->addError('manifest_public_path_duplicate', '公开配置路径不得重复。', '$.config.delivery.public_paths.' . $index);
            }
            foreach (array_keys($paths) as $existing) {
                if (str_starts_with($path . '.', $existing . '.') || str_starts_with($existing . '.', $path . '.')) {
                    $result->addError('manifest_public_path_overlap', '公开配置路径不得存在父子重叠。', '$.config.delivery.public_paths.' . $index);
                }
            }
            $paths[$path] = true;
        }
    }

    private function crossConsistency(stdClass $manifest, ValidationResult $result): void
    {
        if (!(($manifest->capabilities ?? null) instanceof stdClass)
            || !(($manifest->config ?? null) instanceof stdClass)
            || !(($manifest->config->delivery ?? null) instanceof stdClass)
        ) {
            return;
        }
        $configurable = $manifest->capabilities->configurable ?? null;
        $schema = $manifest->config->schema ?? null;
        $paths = $manifest->config->delivery->public_paths ?? null;
        if ($configurable === true) {
            if ($schema !== 'config.schema.json') {
                $result->addError('manifest_config_schema', '可配置模板的 config.schema 必须固定为 config.schema.json。', '$.config.schema');
            }
            if (!is_array($paths) || $paths === []) {
                $result->addError('manifest_public_paths', '可配置模板必须声明至少一个公开配置路径。', '$.config.delivery.public_paths');
            }
        } elseif ($configurable === false) {
            if ($schema !== null) {
                $result->addError('manifest_config_schema', '不可配置模板的 config.schema 必须为 null。', '$.config.schema');
            }
            if (is_array($paths) && $paths !== []) {
                $result->addError('manifest_public_paths', '不可配置模板的 public_paths 必须为空。', '$.config.delivery.public_paths');
            }
        }
    }

    /** @param list<string> $allowed @param list<string> $required */
    private function keys(stdClass $object, array $allowed, array $required, string $path, ValidationResult $result): void
    {
        $properties = get_object_vars($object);
        foreach (array_keys($properties) as $key) {
            if (!in_array($key, $allowed, true)) {
                $result->addError('manifest_unknown_field', '出现未知字段。', $path . '.' . $key);
            }
        }
        foreach ($required as $key) {
            if (!property_exists($object, $key)) {
                $result->addError('manifest_required_field', '缺少必需字段。', $path . '.' . $key);
            }
        }
    }

    private function fixedString(stdClass $object, string $key, string $expected, string $path, ValidationResult $result): void
    {
        $value = $this->string($object, $key, $path, $result);
        if ($value !== null && $value !== $expected) {
            $result->addError('manifest_fixed_value', sprintf('字段必须固定为 %s。', $expected), $path);
        }
    }

    private function string(stdClass $object, string $key, string $path, ValidationResult $result): ?string
    {
        if (!property_exists($object, $key) || !is_string($object->{$key})) {
            $result->addError('manifest_type', '字段必须是 string。', $path);
            return null;
        }

        return $object->{$key};
    }

    private function boolean(stdClass $object, string $key, string $path, ValidationResult $result): ?bool
    {
        if (!property_exists($object, $key) || !is_bool($object->{$key})) {
            $result->addError('manifest_type', '字段必须是 boolean。', $path);
            return null;
        }

        return $object->{$key};
    }

    private function length(string $value): int
    {
        preg_match_all('/./us', $value, $matches);
        return count($matches[0]);
    }
}
