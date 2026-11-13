<?php

declare(strict_types=1);

namespace LandingOps\Template;

use LandingOps\Contract\Json;
use LandingOps\Contract\ManifestValidator;
use LandingOps\Contract\SchemaContractValidator;
use LandingOps\Exception\LandingOpsException;
use LandingOps\Validation\ValidationResult;
use LandingOps\Zip\InspectedArchive;
use LandingOps\Zip\ZipInspector;
use LandingOps\Zip\ZipLimits;
use stdClass;

final class TemplateValidator
{
    private readonly ZipInspector $zipInspector;

    public function __construct(
        ?ZipLimits $limits = null,
        private readonly ManifestValidator $manifestValidator = new ManifestValidator(),
        private readonly SchemaContractValidator $schemaValidator = new SchemaContractValidator(),
    ) {
        $this->zipInspector = new ZipInspector($limits ?? new ZipLimits());
    }

    public function validate(string $zipPath): TemplateValidation
    {
        $result = new ValidationResult();
        $archive = $this->zipInspector->inspect($zipPath, $result);
        if (!($archive instanceof InspectedArchive)) {
            return new TemplateValidation($result, null);
        }

        foreach (['landingops.json', 'index.html'] as $required) {
            if (!$archive->has($required)) {
                $result->addError('template_required_file', 'ZIP 根目录缺少必需文件。', $required);
            }
        }
        if (!$result->isValid()) {
            return new TemplateValidation($result, null);
        }

        try {
            $manifest = Json::decode($archive->read('landingops.json', 1024 * 1024), 'landingops.json');
        } catch (LandingOpsException|\RuntimeException $exception) {
            $result->addError('manifest_json', $exception->getMessage(), 'landingops.json');
            return new TemplateValidation($result, null);
        }

        $result->merge($this->manifestValidator->validate($manifest));
        if (!($manifest instanceof stdClass)) {
            return new TemplateValidation($result, null);
        }
        if (!$result->isValid()) {
            return new TemplateValidation($result, null);
        }

        $configurable = ($manifest->capabilities->configurable ?? false) === true;
        if ($configurable && !$archive->has('config.schema.json')) {
            $result->addError('template_schema_required', '可配置模板必须包含 config.schema.json。', 'config.schema.json');
            return new TemplateValidation($result, null);
        }
        if (!$configurable && $archive->has('config.schema.json')) {
            $result->addError('template_schema_unexpected', '不可配置模板不得包含 config.schema.json。', 'config.schema.json');
        }

        try {
            $schema = $configurable
                ? Json::decode($archive->read('config.schema.json', 2 * 1024 * 1024), 'config.schema.json')
                : (object) [
                    '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => new stdClass(),
                ];
        } catch (LandingOpsException|\RuntimeException $exception) {
            $result->addError('schema_json', $exception->getMessage(), 'config.schema.json');
            return new TemplateValidation($result, null);
        }
        if (!($schema instanceof stdClass)) {
            $result->addError('schema_root_type', 'config.schema.json 根必须是 object。', 'config.schema.json');
            return new TemplateValidation($result, null);
        }

        $result->merge($this->schemaValidator->validate($schema));

        $publicPaths = $manifest->config->delivery->public_paths ?? [];
        if (is_array($publicPaths)) {
            $result->merge($this->schemaValidator->validatePublicPaths($schema, $publicPaths));
            foreach ($publicPaths as $path) {
                if (is_string($path) && preg_match('/(?:^|\.)(?:password|password_hash|secret|token|credential|api_key|private_key)(?:\.|$)/i', $path) === 1) {
                    $result->addError('template_sensitive_public_path', '敏感字段不得投影到公开配置。', $path);
                }
            }
        }
        if (!$result->isValid()) {
            return new TemplateValidation($result, null);
        }

        $this->validateCapabilities($archive, $manifest, $schema, $result);
        $this->validateFiles($archive, $manifest, $result);
        $this->validateHtml($archive, $manifest, $result);
        $this->scanSensitiveContent($archive, $result);

        return new TemplateValidation(
            $result,
            $result->isValid() ? new ValidatedTemplate($archive, $manifest, $schema) : null,
        );
    }

    private function validateCapabilities(
        InspectedArchive $archive,
        stdClass $manifest,
        stdClass $schema,
        ValidationResult $result,
    ): void {
        $properties = ($schema->properties ?? null) instanceof stdClass ? $schema->properties : new stdClass();
        $capabilities = $manifest->capabilities ?? new stdClass();

        if (($capabilities->configurable ?? false) === true) {
            if (!property_exists($properties, 'pixel_ids') || ($properties->pixel_ids->type ?? null) !== 'array') {
                $result->addError('capability_pixel_schema', '可配置模板必须定义 pixel_ids 数组。', '$.properties.pixel_ids');
            } else {
                $declaredMax = $capabilities->pixel_ids->max_items ?? null;
                $schemaMax = $properties->pixel_ids->maxItems ?? null;
                if (!is_int($declaredMax) || $schemaMax !== $declaredMax) {
                    $result->addError('capability_pixel_limit', 'manifest 与 schema 的 Pixel ID 上限必须一致。', '$.properties.pixel_ids.maxItems');
                }
            }
        }

        $eventMap = [
            'Download' => 'download',
            'AddToCart' => 'add_to_cart',
            'Purchase' => 'purchase',
        ];
        $eventsSchema = $properties->events ?? null;
        foreach (($capabilities->events ?? []) as $event) {
            $field = $eventMap[$event] ?? null;
            if ($field === null
                || !($eventsSchema instanceof stdClass)
                || !(($eventsSchema->properties ?? null) instanceof stdClass)
                || !property_exists($eventsSchema->properties, $field)
            ) {
                $result->addError('capability_event_schema', 'manifest 事件在 schema 中缺少对应字段。', (string) $event);
            }
        }

        $linksSchema = $properties->links ?? null;
        $linkProperties = $linksSchema instanceof stdClass && ($linksSchema->properties ?? null) instanceof stdClass
            ? $linksSchema->properties
            : new stdClass();
        if (($capabilities->links->download ?? false) === true && !property_exists($linkProperties, 'download_url')) {
            $result->addError('capability_link_schema', '下载能力必须定义 links.download_url。');
        }
        if (($capabilities->links->redirect ?? false) === true && !property_exists($linkProperties, 'redirect_url')) {
            $result->addError('capability_link_schema', '跳转能力必须定义 links.redirect_url。');
        }

        $adminSupported = ($manifest->native_admin->supported ?? false) === true;
        if ($adminSupported && !$archive->has('admin/index.php')) {
            $result->addError('capability_admin_entry', '声明原生后台时必须包含 admin/index.php。', 'admin/index.php');
        }
        if (!$adminSupported) {
            foreach ($archive->entries as $entry) {
                if (str_starts_with($entry->name, 'admin/')) {
                    $result->addError('capability_admin_unexpected', '未声明原生后台时不得包含 admin/。', $entry->name);
                }
            }
        }

        if (($manifest->runtime->vendor_component->required ?? false) === true) {
            $result->addWarning('vendor_component_required', '模板需要独立供应商组件；离线构建时必须显式提供兼容版本。');
        }
    }

    private function validateFiles(InspectedArchive $archive, stdClass $manifest, ValidationResult $result): void
    {
        $phpRequired = ($manifest->runtime->php->required ?? false) === true;
        foreach ($archive->entries as $entry) {
            if ($entry->directory) {
                continue;
            }
            $lower = strtolower($entry->name);
            if ($lower === 'index.php' || $lower === 'core.php') {
                $result->addError('template_vendor_component_embedded', '供应商 index.php/core.php 不得包含在基础模板 ZIP。', $entry->name);
            }
            if (str_ends_with($lower, '.php') && !$phpRequired) {
                $result->addError('template_php_undeclared', '模板包含 PHP，但 runtime.php.required=false。', $entry->name);
            }
        }
    }

    private function validateHtml(InspectedArchive $archive, stdClass $manifest, ValidationResult $result): void
    {
        try {
            $html = $archive->read('index.html', 5 * 1024 * 1024);
        } catch (\RuntimeException $exception) {
            $result->addError('template_index_read', $exception->getMessage(), 'index.html');
            return;
        }

        $placeholder = (string) ($manifest->config->delivery->script_placeholder ?? '');
        if ($placeholder !== '' && substr_count($html, $placeholder) !== 1) {
            $result->addError('template_config_placeholder', 'index.html 必须且只能包含一次公开配置占位符。', 'index.html');
        }
        if ($placeholder !== '' && preg_match(
            '#<script\s+[^>]*src=["\']\./' . preg_quote($placeholder, '#') . '["\'][^>]*>\s*</script>#i',
            $html,
        ) !== 1) {
            $result->addError('template_config_script', '公开配置占位符必须位于相对 script src 中。', 'index.html');
        }

        preg_match_all('/\b(?:src|href)\s*=\s*["\']([^"\']+)["\']/i', $html, $matches);
        foreach ($matches[1] as $reference) {
            if ($reference === './' . $placeholder || $reference === '' || str_starts_with($reference, '#')) {
                continue;
            }
            if (preg_match('#^(?:https?:)?//#i', $reference) === 1) {
                $result->addWarning('template_external_reference', '模板包含外部资源，需按域名策略审批。', $reference);
                continue;
            }
            if (preg_match('#^(?:javascript|data):#i', $reference) === 1 || str_starts_with($reference, '/')) {
                $result->addError('template_unsafe_reference', '模板资源必须使用安全相对路径。', $reference);
                continue;
            }
            $path = preg_split('/[?#]/', $reference, 2)[0] ?? '';
            $path = preg_replace('#^\./#', '', $path) ?? $path;
            if ($path !== '' && !$archive->has($path)) {
                $result->addError('template_missing_resource', 'index.html 引用的相对资源不存在。', $path);
            }
        }
    }

    private function scanSensitiveContent(InspectedArchive $archive, ValidationResult $result): void
    {
        foreach ($archive->entries as $entry) {
            if ($entry->directory || $entry->size > 1024 * 1024) {
                continue;
            }
            $extension = strtolower(pathinfo($entry->name, PATHINFO_EXTENSION));
            if (!in_array($extension, ['html', 'htm', 'js', 'css', 'json', 'php', 'txt', 'svg'], true)) {
                continue;
            }
            try {
                $contents = $archive->read($entry->name, 1024 * 1024);
            } catch (\RuntimeException) {
                continue;
            }
            if (preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/', $contents) === 1) {
                $result->addError('template_private_key_content', '模板文件包含私钥标记。', $entry->name);
            }
        }
    }
}
