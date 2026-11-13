<?php

declare(strict_types=1);

namespace LandingOps\Instance;

use Closure;
use LandingOps\Config\ConfigProjector;
use LandingOps\Contract\ConfigValidator;
use LandingOps\Contract\Json;
use LandingOps\Exception\LandingOpsException;
use LandingOps\Exception\PublicationInterruptedException;
use LandingOps\Exception\ValidationException;
use LandingOps\Filesystem\LocalFilesystem;
use LandingOps\Template\TemplateValidator;
use LandingOps\Validation\ValidationResult;
use LandingOps\Zip\SafeExtractor;
use LandingOps\Zip\ZipLimits;
use stdClass;

final class OfflineInstanceBuilder
{
    private readonly TemplateValidator $templateValidator;
    private readonly SafeExtractor $extractor;
    private readonly Closure $clock;
    private readonly ?Closure $faultInjector;

    public function __construct(
        ?ZipLimits $limits = null,
        private readonly ConfigValidator $configValidator = new ConfigValidator(),
        private readonly ConfigProjector $projector = new ConfigProjector(),
        private readonly LocalFilesystem $filesystem = new LocalFilesystem(),
        ?Closure $clock = null,
        ?Closure $faultInjector = null,
    ) {
        $resolvedLimits = $limits ?? new ZipLimits();
        $this->templateValidator = new TemplateValidator($resolvedLimits);
        $this->extractor = new SafeExtractor($resolvedLimits);
        $this->clock = $clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z');
        $this->faultInjector = $faultInjector;
    }

    public function recover(string $instanceRoot): void
    {
        $this->initializeInstanceRoot($instanceRoot);
        $ledger = new RevisionLedger($instanceRoot, $this->filesystem);
        $pointer = new ReleasePointer($instanceRoot, $this->filesystem);
        (new PublicationCoordinator($instanceRoot, $this->filesystem))->recover($ledger, $pointer);
    }

    public function build(string $zipPath, stdClass $config, string $instanceRoot): BuildResult
    {
        $validation = $this->templateValidator->validate($zipPath);
        if (!$validation->result->isValid() || $validation->template === null) {
            throw new ValidationException('模板验证失败。', $validation->result);
        }
        $template = $validation->template;
        if (($template->manifest->runtime->vendor_component->required ?? false) === true) {
            $result = new ValidationResult();
            $result->addError('vendor_component_unavailable', '离线实例化未提供独立供应商组件。');
            throw new ValidationException('无法离线实例化需要供应商组件的模板。', $result);
        }

        $this->initializeInstanceRoot($instanceRoot);
        $ledger = new RevisionLedger($instanceRoot, $this->filesystem);
        $pointer = new ReleasePointer($instanceRoot, $this->filesystem);
        $coordinator = new PublicationCoordinator($instanceRoot, $this->filesystem);
        $coordinator->recover($ledger, $pointer);
        $revision = $ledger->allocate();
        $stagingRoot = $instanceRoot . DIRECTORY_SEPARATOR . 'staging'
            . DIRECTORY_SEPARATOR . 'revision-' . $revision . '-' . bin2hex(random_bytes(4));
        $releaseCommitted = false;

        try {
            $coordinator->begin($revision, $stagingRoot);
            $configResult = $this->configValidator->validate($config, $template->configSchema);
            if (!$configResult->isValid()) {
                throw new ValidationException('实例配置验证失败。', $configResult);
            }

            $sourceRoot = $stagingRoot . DIRECTORY_SEPARATOR . '_template';
            $this->filesystem->ensureDirectory(dirname($sourceRoot));
            $this->extractor->extract($template->archive, $sourceRoot);

            $publicRoot = $stagingRoot . DIRECTORY_SEPARATOR . 'public';
            $privateRoot = $stagingRoot . DIRECTORY_SEPARATOR . 'private';
            $this->filesystem->ensureDirectory($publicRoot);
            $this->filesystem->ensureDirectory($privateRoot . DIRECTORY_SEPARATOR . '.landingops');
            $this->copyTemplatePublicFiles($template->archive->entries, $sourceRoot, $publicRoot);

            $generatedAt = ($this->clock)();
            $nativeAdmin = (object) ['enabled' => false, 'password_hash' => null];
            $privateHashPayload = (object) [
                'config' => $config,
                'native_admin' => (object) ['enabled' => false],
            ];
            $contentHash = Json::hash($privateHashPayload);

            $publicPaths = $template->manifest->config->delivery->public_paths;
            $projected = $this->projector->project($config, $template->configSchema, $publicPaths);
            $publicHash = $this->projector->publicHash($projected);
            $releaseId = sprintf('r%d-%s', $revision, substr($publicHash, strlen('sha256:'), 12));
            $publicBaseUrl = (new PublicUrlRewriter())->baseUrl($releaseId);
            $publicConfigAsset = sprintf(
                'assets/landingops.config.r%d.%s.js',
                $revision,
                substr($publicHash, strlen('sha256:'), 12),
            );

            $canonical = (object) [
                'contract' => 'lts-local-config/1.0',
                'schema_version' => $template->manifest->config->schema_version,
                'revision' => $revision,
                'content_hash' => $contentHash,
                'updated_at' => $generatedAt,
                'updated_by' => (object) ['type' => 'offline_builder', 'id' => 'local'],
                'native_admin' => $nativeAdmin,
                'config' => $config,
            ];
            $this->filesystem->writeExclusive(
                $privateRoot . DIRECTORY_SEPARATOR . '.landingops' . DIRECTORY_SEPARATOR . 'config.json',
                Json::encode($canonical, true),
            );

            $javascript = $this->projector->javascript(
                $template->manifest->config->delivery->global_name,
                $revision,
                $publicHash,
                $generatedAt,
                $projected,
            );
            $publicConfigPath = $publicRoot . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $publicConfigAsset);
            $this->filesystem->writeExclusive($publicConfigPath, $javascript);

            $indexPath = $publicRoot . DIRECTORY_SEPARATOR . 'index.html';
            $index = (string) file_get_contents($indexPath);
            $placeholder = $template->manifest->config->delivery->script_placeholder;
            if (substr_count($index, $placeholder) !== 1) {
                throw new LandingOpsException('实例化时公开配置占位符数量异常。');
            }
            $index = str_replace('./' . $placeholder, $publicBaseUrl . $publicConfigAsset, $index);
            $index = (new PublicUrlRewriter())->rewriteHtml($index, $releaseId);
            if (file_put_contents($indexPath, $index, LOCK_EX) === false) {
                throw new LandingOpsException('无法写入实例 index.html。');
            }

            $this->filesystem->removeTree($sourceRoot, $stagingRoot);
            $manifest = (object) [
                'contract' => 'landingops-release/1.0',
                'release_id' => $releaseId,
                'revision' => $revision,
                'created_at' => $generatedAt,
                'template' => (object) [
                    'slug' => $template->manifest->template->slug,
                    'version' => $template->manifest->template->version,
                    'zip_sha256' => 'sha256:' . $template->archive->sha256,
                ],
                'config' => (object) [
                    'content_hash' => $contentHash,
                    'public_hash' => $publicHash,
                    'public_asset' => $publicConfigAsset,
                    'public_base_url' => $publicBaseUrl,
                    'public_url' => $publicBaseUrl . $publicConfigAsset,
                ],
                'files' => $this->filesystem->inventory($stagingRoot),
            ];
            $this->filesystem->writeExclusive(
                $stagingRoot . DIRECTORY_SEPARATOR . 'release-manifest.json',
                Json::encode($manifest, true),
            );
            $coordinator->stageReady($revision, $releaseId);
            $this->inject('after_staging_ready', $revision, $releaseId);

            $releasesRoot = $instanceRoot . DIRECTORY_SEPARATOR . 'releases';
            $this->filesystem->ensureDirectory($releasesRoot);
            $releasePath = $releasesRoot . DIRECTORY_SEPARATOR . $releaseId;
            if (file_exists($releasePath) || !rename($stagingRoot, $releasePath)) {
                throw new LandingOpsException('无法提交不可变 release。');
            }
            $releaseCommitted = true;
            $coordinator->releaseCommitted($revision);
            $this->inject('after_release_committed', $revision, $releaseId);

            $pointer->activate($releaseId);
            $coordinator->pointerActivated($revision);
            $this->inject('after_pointer_activated', $revision, $releaseId);
            $ledger->markActive($revision, $releaseId);
            $coordinator->complete($revision);

            return new BuildResult(
                $revision,
                $releaseId,
                $releasePath,
                $contentHash,
                $publicHash,
                $publicConfigAsset,
                $publicBaseUrl,
            );
        } catch (\Throwable $exception) {
            if ($exception instanceof PublicationInterruptedException) {
                throw $exception;
            }
            if (!$releaseCommitted) {
                $ledger->markFailed($revision);
                if (is_dir($stagingRoot)) {
                    $this->filesystem->removeTree($stagingRoot, dirname($stagingRoot));
                }
                $coordinator->complete($revision);
            }
            throw $exception;
        }
    }

    private function initializeInstanceRoot(string $instanceRoot): void
    {
        $marker = $instanceRoot . DIRECTORY_SEPARATOR . '.landingops-instance.json';
        if (is_dir($instanceRoot) && !is_file($marker)) {
            $entries = array_values(array_filter(
                scandir($instanceRoot) ?: [],
                static fn (string $entry): bool => $entry !== '.' && $entry !== '..',
            ));
            if ($entries !== []) {
                throw new LandingOpsException('拒绝接管缺少 ownership marker 的非空实例目录。');
            }
        }
        $this->filesystem->ensureDirectory($instanceRoot);
        if (!is_file($marker)) {
            $this->filesystem->writeExclusive($marker, Json::encode((object) [
                'contract' => 'landingops-offline-instance/1.0',
            ], true));
        } else {
            $decoded = Json::decode((string) file_get_contents($marker), '.landingops-instance.json');
            if (!($decoded instanceof stdClass) || ($decoded->contract ?? null) !== 'landingops-offline-instance/1.0') {
                throw new LandingOpsException('实例目录 ownership marker 无效。');
            }
        }
        $this->filesystem->ensureDirectory($instanceRoot . DIRECTORY_SEPARATOR . 'staging');
        $this->filesystem->ensureDirectory($instanceRoot . DIRECTORY_SEPARATOR . 'releases');
    }

    /** @param list<\LandingOps\Zip\ArchiveEntry> $entries */
    private function copyTemplatePublicFiles(array $entries, string $sourceRoot, string $publicRoot): void
    {
        foreach ($entries as $entry) {
            if ($entry->directory || in_array($entry->name, ['landingops.json', 'config.schema.json'], true)) {
                continue;
            }
            if (str_starts_with($entry->name, 'admin/')) {
                continue;
            }
            $source = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry->name);
            $destination = $publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry->name);
            $this->filesystem->copyFile($source, $destination);
        }
    }

    private function inject(string $point, int $revision, string $releaseId): void
    {
        if ($this->faultInjector !== null) {
            ($this->faultInjector)($point, $revision, $releaseId);
        }
    }
}
