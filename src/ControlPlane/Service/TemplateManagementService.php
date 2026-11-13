<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Service;

use LandingOps\Contract\Json;
use LandingOps\ControlPlane\Audit\AuditLogger;
use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\Principal;
use LandingOps\ControlPlane\Auth\Role;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Repository\ControlPlaneRepository;
use LandingOps\ControlPlane\Support\IdGenerator;
use LandingOps\ControlPlane\Support\UtcClock;
use LandingOps\ControlPlane\Template\PrivateTemplateStorage;
use LandingOps\ControlPlane\Template\UploadedTemplateFile;
use LandingOps\Template\TemplateValidator;
use Throwable;

final class TemplateManagementService
{
    private readonly AuditLogger $audit;
    private readonly TemplateGrantService $grants;

    public function __construct(
        private readonly ControlPlaneRepository $repository,
        private readonly PrivateTemplateStorage $storage,
        private readonly AuthorizationService $authorization = new AuthorizationService(),
        private readonly TemplateValidator $validator = new TemplateValidator(),
        private readonly IdGenerator $ids = new IdGenerator(),
        private readonly UtcClock $clock = new UtcClock(),
        ?TemplateGrantService $grants = null,
        ?AuditLogger $audit = null,
    ) {
        $this->grants = $grants ?? new TemplateGrantService($repository, $authorization, $ids, $clock);
        $this->audit = $audit ?? new AuditLogger($repository, $ids, $clock);
    }

    /** @return list<array<string,mixed>> */
    public function list(Principal $actor): array
    {
        $this->authorization->assertActive($actor);
        $all = $actor->role === Role::SUPER_ADMIN;
        $teamId = $all ? null : $actor->teamId;
        if (!$all && $teamId === null) {
            return [];
        }
        $templates = [];
        foreach ($this->repository->listTemplateVersions($teamId) as $row) {
            $templateId = (string) $row['template_id'];
            if (!isset($templates[$templateId])) {
                $templates[$templateId] = [
                    'id' => $templateId,
                    'slug' => $row['slug'],
                    'name' => $row['template_name'],
                    'description' => $row['description'],
                    'status' => $row['template_status'],
                    'owner_team' => $row['owner_team_id'] === null ? null : [
                        'id' => $row['owner_team_id'],
                        'name' => $row['owner_team_name'],
                    ],
                    'created_by' => [
                        'id' => $row['created_by_user_id'],
                        'display_name' => $row['creator_name'],
                    ],
                    'created_at' => $row['template_created_at'],
                    'versions' => [],
                ];
            }
            $version = $this->safeVersion($row, includeReport: true);
            $version['available'] = $all || $this->repository->hasTemplateGrant((string) $row['version_id'], (string) $teamId);
            $version['grants'] = $all ? $this->repository->listTemplateGrants((string) $row['version_id']) : [];
            $templates[$templateId]['versions'][] = $version;
        }
        return array_values($templates);
    }

    /** @return array<string,mixed> */
    public function validation(Principal $actor, string $versionId): array
    {
        $this->authorization->assertActive($actor);
        $teamId = $actor->role === Role::SUPER_ADMIN ? null : $actor->teamId;
        $row = $this->repository->findTemplateVersionDetail($versionId, $teamId);
        if ($row === null) {
            throw new ApiException(404, 'template_not_available', '模板版本不存在或未授权。');
        }
        return [
            'template_version_id' => $versionId,
            'validation_status' => $row['validation_status'],
            'validation_report' => self::decodeJson($row['validation_report_json']),
        ];
    }

    /** @return array<string,mixed> */
    public function upload(Principal $actor, UploadedTemplateFile $file, string $requestId): array
    {
        $this->authorization->assertActive($actor);
        if (!in_array($actor->role, [Role::SUPER_ADMIN, Role::TEAM_ADMIN], true)) {
            throw new ApiException(403, 'template_upload_forbidden', '当前角色不能上传模板。');
        }
        if ($actor->role === Role::TEAM_ADMIN) {
            $team = $actor->teamId === null ? null : $this->repository->findTeamById($actor->teamId);
            if ($team === null || ($team['status'] ?? null) !== 'active') {
                throw new ApiException(403, 'template_upload_forbidden', '所属团队不可用，不能上传模板。');
            }
        }

        $staged = $this->storage->stage($file);
        $storedKey = null;
        try {
            $validation = $this->validator->validate($staged);
            $report = self::publicReport($validation->result->toArray());
            if (!$validation->result->isValid() || $validation->template === null) {
                throw new ApiException(422, 'template_validation_failed', '模板 ZIP 未通过 LTS v1.0 校验。', [
                    'validation' => $report,
                ]);
            }

            $manifest = $validation->template->manifest;
            $slug = (string) $manifest->template->slug;
            $versionName = (string) $manifest->template->version;
            $existingHash = $this->repository->findTemplateVersionByHash($validation->template->archive->sha256);
            if ($existingHash !== null) {
                throw new ApiException(409, 'template_hash_conflict', '相同内容的模板版本已经存在。');
            }

            $template = $this->repository->findTemplateBySlug($slug);
            if ($template !== null && $actor->role === Role::TEAM_ADMIN
                && ($template['owner_team_id'] ?? null) !== $actor->teamId
            ) {
                throw new ApiException(404, 'template_not_available', '模板不存在或不可用于上传新版本。');
            }
            if ($template !== null
                && $this->repository->findTemplateVersionByTemplateAndVersion((string) $template['id'], $versionName) !== null
            ) {
                throw new ApiException(409, 'template_version_immutable', '该模板版本号已经存在且不可覆盖。');
            }

            $now = $this->clock->sql();
            $templateId = $template === null ? $this->ids->generate() : (string) $template['id'];
            $versionId = $this->ids->generate();
            $storedKey = $this->storage->persist($validation->template, $versionId);
            $templateRow = [
                'id' => $templateId,
                'slug' => $slug,
                'name' => (string) $manifest->template->name,
                'description' => (string) $manifest->template->description,
                'owner_team_id' => $actor->role === Role::TEAM_ADMIN ? $actor->teamId : null,
                'created_by_user_id' => $actor->id,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $versionRow = [
                'id' => $versionId,
                'template_id' => $templateId,
                'version' => $versionName,
                'standard_version' => (string) $manifest->standard_version,
                'zip_storage_key' => $storedKey,
                'zip_sha256' => $validation->template->archive->sha256,
                'manifest_json' => Json::encode($manifest),
                'config_schema_json' => Json::encode($validation->template->configSchema),
                'capability_tags_json' => Json::encode($manifest->capabilities),
                'validation_status' => 'valid',
                'validation_report_json' => json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'uploaded_by_user_id' => $actor->id,
                'published_at' => $now,
                'created_at' => $now,
                'owner_team_id' => $template === null ? $templateRow['owner_team_id'] : ($template['owner_team_id'] ?? null),
            ];

            try {
                $this->repository->transaction(function () use (
                    $template,
                    $templateRow,
                    $versionRow,
                    $actor,
                    $versionId,
                    $requestId,
                ): void {
                    if ($template === null) {
                        $this->repository->insertTemplate($templateRow);
                    }
                    $this->repository->insertTemplateVersion($versionRow);
                    if ($actor->role === Role::TEAM_ADMIN) {
                        $this->grants->grantUploaderTeam($actor, $versionId, $requestId);
                    }
                    $this->audit->record($actor, 'template.version_upload', 'template_version', $versionId, $requestId, [
                        'template_id' => $versionRow['template_id'],
                        'version' => $versionRow['version'],
                        'zip_sha256' => $versionRow['zip_sha256'],
                    ]);
                });
            } catch (Throwable $exception) {
                if ($this->repository->findTemplateVersionByHash($versionRow['zip_sha256']) !== null) {
                    throw new ApiException(409, 'template_hash_conflict', '相同内容的模板版本已经存在。');
                }
                if ($this->repository->findTemplateVersionByTemplateAndVersion($templateId, $versionName) !== null) {
                    throw new ApiException(409, 'template_version_immutable', '该模板版本号已经存在且不可覆盖。');
                }
                if ($template === null && $this->repository->findTemplateBySlug($slug) !== null) {
                    throw new ApiException(409, 'template_slug_conflict', '相同 slug 的模板已由另一个上传请求创建。');
                }
                throw $exception;
            }

            return [
                'template_id' => $templateId,
                'template_version_id' => $versionId,
                'slug' => $slug,
                'version' => $versionName,
                'standard_version' => (string) $manifest->standard_version,
                'zip_sha256' => $validation->template->archive->sha256,
                'validation_status' => 'valid',
                'validation_report' => $report,
            ];
        } catch (Throwable $exception) {
            if ($storedKey !== null) {
                $this->storage->removeStored($storedKey);
            }
            throw $exception;
        } finally {
            $this->storage->removeStaged($staged);
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function safeVersion(array $row, bool $includeReport): array
    {
        $version = [
            'id' => $row['version_id'],
            'version' => $row['version'],
            'standard_version' => $row['standard_version'],
            'zip_sha256' => $row['zip_sha256'],
            'validation_status' => $row['validation_status'],
            'uploaded_by' => [
                'id' => $row['uploaded_by_user_id'],
                'display_name' => $row['uploader_name'],
            ],
            'published_at' => $row['published_at'],
            'created_at' => $row['version_created_at'],
        ];
        if ($includeReport) {
            $version['validation_report'] = self::decodeJson($row['validation_report_json']);
        }
        return $version;
    }

    /** @return array<string,mixed> */
    private static function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private static function publicReport(array $report): array
    {
        foreach (['errors', 'warnings'] as $kind) {
            if (!isset($report[$kind]) || !is_array($report[$kind])) {
                $report[$kind] = [];
                continue;
            }
            foreach ($report[$kind] as &$issue) {
                if (!is_array($issue)) {
                    continue;
                }
                $path = $issue['path'] ?? null;
                if (is_string($path) && (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#D', $path) === 1)) {
                    $issue['path'] = null;
                }
            }
            unset($issue);
        }
        return $report;
    }
}
