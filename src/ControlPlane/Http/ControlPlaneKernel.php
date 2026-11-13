<?php

declare(strict_types=1);

namespace LandingOps\ControlPlane\Http;

use LandingOps\ControlPlane\Auth\Principal;
use LandingOps\ControlPlane\Auth\Role;
use LandingOps\ControlPlane\Auth\SessionService;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Service\AccountService;
use LandingOps\ControlPlane\Service\BaotaConfigService;
use LandingOps\ControlPlane\Service\BaotaReadOnlyService;
use LandingOps\ControlPlane\Service\CloudflareConnectionService;
use LandingOps\ControlPlane\Service\CloudflareDnsPrecheckService;
use LandingOps\ControlPlane\Service\CloudflareVerificationService;
use LandingOps\ControlPlane\Service\DomainResourceService;
use LandingOps\ControlPlane\Service\DeploymentDryRunService;
use LandingOps\ControlPlane\Service\OwnershipTransferService;
use LandingOps\ControlPlane\Service\ResourceAccessService;
use LandingOps\ControlPlane\Service\TemplateGrantService;
use LandingOps\ControlPlane\Service\TemplateManagementService;
use LandingOps\ControlPlane\Template\UploadedTemplateFile;
use Throwable;

final class ControlPlaneKernel
{
    public const COOKIE = 'landingops_session';

    public function __construct(
        private readonly SessionService $sessions,
        private readonly AccountService $accounts,
        private readonly ResourceAccessService $resources,
        private readonly OwnershipTransferService $transfers,
        private readonly RequestId $requestIds = new RequestId(),
        private readonly ?TemplateManagementService $templates = null,
        private readonly ?TemplateGrantService $templateGrants = null,
        private readonly ?CloudflareConnectionService $cloudflareConnections = null,
        private readonly ?DomainResourceService $domains = null,
        private readonly ?CloudflareVerificationService $cloudflareVerifications = null,
        private readonly ?CloudflareDnsPrecheckService $dnsPrechecks = null,
        private readonly ?BaotaConfigService $baotaConfigs = null,
        private readonly ?BaotaReadOnlyService $baotaReadOnly = null,
        private readonly ?DeploymentDryRunService $dryRuns = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        $requestId = $this->requestIds->resolve($request->header('X-Request-Id'));
        try {
            return $this->route($request, $requestId);
        } catch (ApiException $exception) {
            return new Response($exception->status, [
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                    'details' => $exception->details,
                    'retryable' => $exception->retryable,
                ],
                'request_id' => $requestId,
            ], [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Request-Id' => $requestId,
                'Cache-Control' => 'no-store',
            ]);
        } catch (Throwable) {
            return new Response(500, [
                'error' => [
                    'code' => 'internal_error',
                    'message' => '服务器内部错误。',
                    'details' => [],
                    'retryable' => false,
                ],
                'request_id' => $requestId,
            ], [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Request-Id' => $requestId,
                'Cache-Control' => 'no-store',
            ]);
        }
    }

    private function route(Request $request, string $requestId): Response
    {
        $method = strtoupper($request->method);
        $path = rtrim($request->path, '/') ?: '/';
        if ($method === 'POST' && $path === '/api/v1/login') {
            $session = $this->sessions->login(
                $this->string($request->body, 'email'),
                $this->string($request->body, 'password'),
                $request->header('User-Agent'),
                $request->ip,
                $requestId,
            );
            return $this->success([
                'user' => $session->principal->safeArray(),
                'csrf_token' => $session->csrfToken,
                'expires_at' => $session->expiresAt,
            ], $requestId, 200, ['Set-Cookie' => self::sessionCookie($session->cookieToken, 28800)]);
        }

        [$principal, $session] = $this->identity($request);
        if ($method === 'POST' && $path === '/api/v1/logout') {
            $this->csrf($request, $session);
            $this->sessions->logout((string) $session['session_id'], $principal, $requestId);
            return $this->success(null, $requestId, 200, ['Set-Cookie' => self::sessionCookie('', 0)]);
        }
        if ($method === 'GET' && $path === '/api/v1/me') {
            return $this->success([
                'user' => $principal->safeArray(),
                'capabilities' => self::capabilities($principal),
                'csrf_token' => $this->sessions->refreshCsrf($session),
            ], $requestId);
        }
        if ($method === 'PATCH' && $path === '/api/v1/me/profile') {
            $this->csrf($request, $session);
            return $this->success(
                $this->accounts->updateOwnProfile($principal, $this->string($request->body, 'display_name'), $requestId),
                $requestId,
            );
        }
        if ($method === 'POST' && $path === '/api/v1/me/password') {
            $this->csrf($request, $session);
            $this->accounts->changeOwnPassword(
                $principal,
                $this->string($request->body, 'current_password'),
                $this->string($request->body, 'new_password'),
                $requestId,
            );
            return $this->success(null, $requestId, 200, ['Set-Cookie' => self::sessionCookie('', 0)]);
        }
        if ($method === 'GET' && $path === '/api/v1/teams') {
            return $this->list($this->accounts->listTeams($principal), $requestId);
        }
        if ($method === 'POST' && $path === '/api/v1/teams') {
            $this->csrf($request, $session);
            return $this->success($this->accounts->createTeam(
                $principal,
                $this->string($request->body, 'name'),
                $this->string($request->body, 'slug'),
                $requestId,
            ), $requestId, 201);
        }
        if ($method === 'GET' && $path === '/api/v1/users') {
            return $this->list($this->accounts->listUsers($principal), $requestId);
        }
        if ($method === 'POST' && $path === '/api/v1/users') {
            $this->csrf($request, $session);
            return $this->success($this->accounts->createUser(
                $principal,
                $this->string($request->body, 'email'),
                $this->string($request->body, 'display_name'),
                $this->string($request->body, 'role'),
                $this->nullableString($request->body, 'team_id'),
                $this->string($request->body, 'password'),
                $requestId,
            ), $requestId, 201);
        }
        if ($method === 'PATCH' && preg_match('~^/api/v1/users/([0-9A-HJKMNP-TV-Z]{26})$~D', $path, $matches) === 1) {
            $this->csrf($request, $session);
            if (($request->body['status'] ?? null) !== 'disabled') {
                throw new ApiException(501, 'not_implemented', '本阶段只实现用户停用。');
            }
            $this->accounts->disableUser($principal, $matches[1], $requestId);
            return $this->success(null, $requestId);
        }
        if ($method === 'GET' && $path === '/api/v1/instances') {
            return $this->list($this->resources->listInstances($principal), $requestId);
        }
        if ($method === 'POST'
            && preg_match('~^/api/v1/instances/([0-9A-HJKMNP-TV-Z]{26})/owner-transfers$~D', $path, $matches) === 1
        ) {
            $this->csrf($request, $session);
            return $this->success($this->transfers->transfer(
                $principal,
                $matches[1],
                $this->string($request->body, 'to_user_id'),
                $this->integer($request->body, 'expected_instance_version'),
                $this->string($request->body, 'reason'),
                $requestId,
            ), $requestId);
        }

        if ($method === 'GET' && $path === '/api/v1/templates') {
            return $this->list($this->templateManagement()->list($principal), $requestId);
        }
        if ($method === 'POST' && $path === '/api/v1/templates/uploads') {
            $this->csrf($request, $session);
            if (count($request->files) !== 1 || !isset($request->files['template_zip'])) {
                throw new ApiException(422, 'template_upload_count', '每次请求必须且只能上传一个 template_zip。');
            }
            $file = $request->files['template_zip'];
            if (!is_array($file)) {
                throw new ApiException(422, 'template_upload_invalid', '模板上传字段无效。');
            }
            return $this->success($this->templateManagement()->upload(
                $principal,
                new UploadedTemplateFile(
                    $this->fileString($file, 'tmp_name'),
                    $this->fileString($file, 'name'),
                    $this->fileInteger($file, 'size'),
                    $this->fileInteger($file, 'error'),
                ),
                $requestId,
            ), $requestId, 201);
        }
        if ($method === 'GET'
            && preg_match('~^/api/v1/template-versions/([0-9A-HJKMNP-TV-Z]{26})/validation$~D', $path, $matches) === 1
        ) {
            return $this->success($this->templateManagement()->validation($principal, $matches[1]), $requestId);
        }
        if ($method === 'GET'
            && preg_match('~^/api/v1/template-versions/([0-9A-HJKMNP-TV-Z]{26})/config-form$~D', $path, $matches) === 1
        ) {
            return $this->success($this->dryRunManagement()->configForm($principal, $matches[1]), $requestId);
        }
        if ($method === 'POST' && $path === '/api/v1/deployment-dry-runs') {
            $this->csrf($request, $session);
            return $this->success($this->dryRunManagement()->run($principal, $request->body), $requestId);
        }
        if ($method === 'POST'
            && preg_match('~^/api/v1/template-versions/([0-9A-HJKMNP-TV-Z]{26})/grants$~D', $path, $matches) === 1
        ) {
            $this->csrf($request, $session);
            $this->grantManagement()->grantBySuperAdmin(
                $principal,
                $matches[1],
                $this->string($request->body, 'team_id'),
                $requestId,
            );
            return $this->success(null, $requestId);
        }
        if ($method === 'DELETE'
            && preg_match('~^/api/v1/template-versions/([0-9A-HJKMNP-TV-Z]{26})/grants/([0-9A-HJKMNP-TV-Z]{26})$~D', $path, $matches) === 1
        ) {
            $this->csrf($request, $session);
            $this->grantManagement()->revokeBySuperAdmin($principal, $matches[1], $matches[2], $requestId);
            return $this->success(null, $requestId);
        }

        if ($method === 'GET' && $path === '/api/v1/cloudflare-connections') {
            return $this->list($this->connectionManagement()->list($principal), $requestId);
        }
        if ($method === 'POST' && $path === '/api/v1/cloudflare-connections') {
            $this->csrf($request, $session);
            return $this->success($this->connectionManagement()->create($principal, $request->body, $requestId), $requestId, 201);
        }
        if ($method === 'PATCH'
            && preg_match('~^/api/v1/cloudflare-connections/([0-9A-HJKMNP-TV-Z]{26})$~D', $path, $matches) === 1
        ) {
            $this->csrf($request, $session);
            return $this->success($this->connectionManagement()->update($principal, $matches[1], $request->body, $requestId), $requestId);
        }
        if ($method === 'POST'
            && preg_match('~^/api/v1/cloudflare-connections/([0-9A-HJKMNP-TV-Z]{26})/verifications$~D', $path, $matches) === 1
        ) {
            $this->csrf($request, $session);
            return $this->success($this->verificationManagement()->verify(
                $principal,
                $matches[1],
                $this->string($request->body, 'zone_id'),
                $this->integer($request->body, 'expected_connection_version'),
                $this->integer($request->body, 'expected_zone_version'),
                $requestId,
            ), $requestId);
        }
        if ($method === 'GET' && $path === '/api/v1/cloudflare-zones') {
            return $this->list($this->domainManagement()->listZones($principal), $requestId);
        }
        if ($method === 'POST' && $path === '/api/v1/cloudflare-zones') {
            $this->csrf($request, $session);
            return $this->success($this->domainManagement()->createZone($principal, $request->body, $requestId), $requestId, 201);
        }
        if ($method === 'PATCH'
            && preg_match('~^/api/v1/cloudflare-zones/([0-9A-HJKMNP-TV-Z]{26})$~D', $path, $matches) === 1
        ) {
            $this->csrf($request, $session);
            return $this->success($this->domainManagement()->updateZone($principal, $matches[1], $request->body, $requestId), $requestId);
        }
        if ($method === 'GET' && $path === '/api/v1/primary-domains') {
            return $this->list($this->domainManagement()->listDomains($principal), $requestId);
        }
        if ($method === 'POST' && $path === '/api/v1/primary-domains') {
            $this->csrf($request, $session);
            return $this->success($this->domainManagement()->createDomain($principal, $request->body, $requestId), $requestId, 201);
        }
        if ($method === 'PATCH'
            && preg_match('~^/api/v1/primary-domains/([0-9A-HJKMNP-TV-Z]{26})$~D', $path, $matches) === 1
        ) {
            $this->csrf($request, $session);
            return $this->success($this->domainManagement()->updateDomain($principal, $matches[1], $request->body, $requestId), $requestId);
        }
        if ($method === 'POST'
            && preg_match('~^/api/v1/primary-domains/([0-9A-HJKMNP-TV-Z]{26})/grants$~D', $path, $matches) === 1
        ) {
            $this->csrf($request, $session);
            $this->domainManagement()->grant($principal, $matches[1], $this->string($request->body, 'team_id'), $requestId);
            return $this->success(null, $requestId);
        }
        if ($method === 'DELETE'
            && preg_match('~^/api/v1/primary-domains/([0-9A-HJKMNP-TV-Z]{26})/grants/([0-9A-HJKMNP-TV-Z]{26})$~D', $path, $matches) === 1
        ) {
            $this->csrf($request, $session);
            $this->domainManagement()->revoke($principal, $matches[1], $matches[2], $requestId);
            return $this->success(null, $requestId);
        }
        if ($method === 'POST'
            && preg_match('~^/api/v1/primary-domains/([0-9A-HJKMNP-TV-Z]{26})/dns-prechecks$~D', $path, $matches) === 1
        ) {
            $this->csrf($request, $session);
            return $this->success($this->dnsPrecheckManagement()->check(
                $principal,
                $matches[1],
                $this->string($request->body, 'hostname'),
            ), $requestId);
        }

        if ($method === 'GET' && $path === '/api/v1/settings/baota') {
            return $this->success($this->baotaConfigManagement()->get($principal), $requestId);
        }
        if ($method === 'PUT' && $path === '/api/v1/settings/baota') {
            $this->csrf($request, $session);
            return $this->success($this->baotaConfigManagement()->save($principal, $request->body, $requestId), $requestId);
        }
        if ($method === 'POST' && $path === '/api/v1/settings/baota/verifications') {
            $this->csrf($request, $session);
            return $this->success($this->baotaReadOnlyManagement()->verify(
                $principal,
                $this->integer($request->body, 'expected_version'),
                $requestId,
            ), $requestId);
        }
        if ($method === 'GET' && $path === '/api/v1/settings/baota/sites') {
            return $this->success($this->baotaReadOnlyManagement()->sites($principal), $requestId);
        }
        if ($method === 'POST' && $path === '/api/v1/settings/baota/prechecks') {
            $this->csrf($request, $session);
            return $this->success($this->baotaReadOnlyManagement()->precheck(
                $principal,
                $this->string($request->body, 'hostname'),
            ), $requestId);
        }
        if ($method === 'GET' && $path === '/api/v1/settings/baota/isolation-checks') {
            return $this->success($this->baotaReadOnlyManagement()->isolation($principal), $requestId);
        }

        foreach (['/api/v1/jobs'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                throw new ApiException(501, 'not_implemented', '该资源接口不属于 Phase 1C。');
            }
        }
        throw new ApiException(404, 'route_not_found', '接口不存在。');
    }

    /** @return array{0:Principal,1:array<string,mixed>} */
    private function identity(Request $request): array
    {
        $authenticated = $this->sessions->authenticate($request->cookies[self::COOKIE] ?? '');
        return [$authenticated['principal'], $authenticated['session']];
    }

    /** @param array<string,mixed> $session */
    private function csrf(Request $request, array $session): void
    {
        $this->sessions->assertCsrf($session, $request->header('X-CSRF-Token'));
    }

    /** @param array<string,mixed>|null $data @param array<string,string> $extraHeaders */
    private function success(?array $data, string $requestId, int $status = 200, array $extraHeaders = []): Response
    {
        return new Response($status, ['data' => $data, 'request_id' => $requestId], array_merge([
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Request-Id' => $requestId,
            'Cache-Control' => 'no-store',
        ], $extraHeaders));
    }

    /** @param list<array<string,mixed>> $data */
    private function list(array $data, string $requestId): Response
    {
        return new Response(200, [
            'data' => $data,
            'page' => ['next_cursor' => null, 'has_more' => false],
            'request_id' => $requestId,
        ], [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Request-Id' => $requestId,
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @param array<string,mixed> $body */
    private function string(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ApiException(400, 'input_invalid', '缺少或无效字段：' . $key);
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    private function nullableString(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw new ApiException(400, 'input_invalid', '无效字段：' . $key);
        }
        return $value;
    }

    private function templateManagement(): TemplateManagementService
    {
        if ($this->templates === null) {
            throw new ApiException(501, 'not_implemented', '模板管理服务尚未配置。');
        }
        return $this->templates;
    }

    private function dryRunManagement(): DeploymentDryRunService
    {
        if ($this->dryRuns === null) {
            throw new ApiException(501, 'not_implemented', '部署预演服务尚未配置。');
        }
        return $this->dryRuns;
    }

    private function grantManagement(): TemplateGrantService
    {
        if ($this->templateGrants === null) {
            throw new ApiException(501, 'not_implemented', '模板授权服务尚未配置。');
        }
        return $this->templateGrants;
    }

    private function connectionManagement(): CloudflareConnectionService
    {
        if ($this->cloudflareConnections === null) {
            throw new ApiException(501, 'not_implemented', 'Cloudflare 连接管理服务尚未配置。');
        }
        return $this->cloudflareConnections;
    }

    private function domainManagement(): DomainResourceService
    {
        if ($this->domains === null) {
            throw new ApiException(501, 'not_implemented', '主域名管理服务尚未配置。');
        }
        return $this->domains;
    }

    private function verificationManagement(): CloudflareVerificationService
    {
        if ($this->cloudflareVerifications === null) {
            throw new ApiException(501, 'not_implemented', 'Cloudflare 只读验证服务尚未配置。');
        }
        return $this->cloudflareVerifications;
    }

    private function dnsPrecheckManagement(): CloudflareDnsPrecheckService
    {
        if ($this->dnsPrechecks === null) {
            throw new ApiException(501, 'not_implemented', 'DNS 只读预检查服务尚未配置。');
        }
        return $this->dnsPrechecks;
    }

    private function baotaConfigManagement(): BaotaConfigService
    {
        if ($this->baotaConfigs === null) {
            throw new ApiException(501, 'not_implemented', '宝塔连接管理服务尚未配置。');
        }
        return $this->baotaConfigs;
    }

    private function baotaReadOnlyManagement(): BaotaReadOnlyService
    {
        if ($this->baotaReadOnly === null) {
            throw new ApiException(501, 'not_implemented', '宝塔只读预检查服务尚未配置。');
        }
        return $this->baotaReadOnly;
    }

    /** @param array<string,mixed> $file */
    private function fileString(array $file, string $key): string
    {
        $value = $file[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ApiException(422, 'template_upload_invalid', '上传文件字段无效：' . $key);
        }
        return $value;
    }

    /** @param array<string,mixed> $file */
    private function fileInteger(array $file, string $key): int
    {
        $value = $file[$key] ?? null;
        if (!is_int($value)) {
            throw new ApiException(422, 'template_upload_invalid', '上传文件字段无效：' . $key);
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    private function integer(array $body, string $key): int
    {
        $value = $body[$key] ?? null;
        if (!is_int($value)) {
            throw new ApiException(400, 'input_invalid', '缺少或无效字段：' . $key);
        }
        return $value;
    }

    /** @return array<string,bool> */
    private static function capabilities(Principal $principal): array
    {
        return [
            'manage_teams' => $principal->role === Role::SUPER_ADMIN,
            'manage_users' => in_array($principal->role, [Role::SUPER_ADMIN, Role::TEAM_ADMIN], true),
            'transfer_owner' => in_array($principal->role, [Role::SUPER_ADMIN, Role::TEAM_ADMIN], true),
            'manage_cloudflare' => $principal->role === Role::SUPER_ADMIN,
            'manage_domains' => $principal->role === Role::SUPER_ADMIN,
            'manage_baota' => $principal->role === Role::SUPER_ADMIN,
        ];
    }

    private static function sessionCookie(string $token, int $maxAge): string
    {
        return self::COOKIE . '=' . rawurlencode($token)
            . '; Path=/api/v1; Max-Age=' . $maxAge . '; HttpOnly; Secure; SameSite=Strict';
    }
}
