<?php

declare(strict_types=1);

namespace LandingOps\Tests;

use LandingOps\ControlPlane\Audit\AuditLogger;
use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\PasswordHasher;
use LandingOps\ControlPlane\Auth\Principal;
use LandingOps\ControlPlane\Auth\Role;
use LandingOps\ControlPlane\Auth\SessionService;
use LandingOps\ControlPlane\Database\SqlStatementSplitter;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Http\ControlPlaneKernel;
use LandingOps\ControlPlane\Http\Request;
use LandingOps\ControlPlane\Service\AccountService;
use LandingOps\ControlPlane\Service\BootstrapAdminService;
use LandingOps\ControlPlane\Service\OwnershipTransferService;
use LandingOps\ControlPlane\Service\ResourceAccessService;
use LandingOps\ControlPlane\Service\TemplateGrantService;

const SUPER_ID = '01AAAAAAAAAAAAAAAAAAAAAAAA';
const ADMIN_A_ID = '01BBBBBBBBBBBBBBBBBBBBBBBB';
const STAFF_A_ID = '01CCCCCCCCCCCCCCCCCCCCCCCC';
const STAFF_A2_ID = '01DDDDDDDDDDDDDDDDDDDDDDDD';
const ADMIN_B_ID = '01EEEEEEEEEEEEEEEEEEEEEEEE';
const STAFF_B_ID = '01FFFFFFFFFFFFFFFFFFFFFFFF';
const TEAM_A_ID = '01GGGGGGGGGGGGGGGGGGGGGGGG';
const TEAM_B_ID = '01HHHHHHHHHHHHHHHHHHHHHHHH';
const INSTANCE_A_ID = '01JJJJJJJJJJJJJJJJJJJJJJJJ';
const INSTANCE_B_ID = '01KKKKKKKKKKKKKKKKKKKKKKKK';
const TEMPLATE_A_VERSION_ID = '01MMMMMMMMMMMMMMMMMMMMMMMM';

/** @return array{0:MemoryControlPlaneRepository,1:array<string,Principal>} */
function controlFixture(): array
{
    $repository = new MemoryControlPlaneRepository();
    $hasher = new PasswordHasher();
    foreach ([
        [TEAM_A_ID, 'Team A', 'team-a'],
        [TEAM_B_ID, 'Team B', 'team-b'],
    ] as [$id, $name, $slug]) {
        $repository->insertTeam([
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'status' => 'active',
            'version' => 1,
            'created_at' => '2026-01-01 00:00:00.000000',
            'updated_at' => '2026-01-01 00:00:00.000000',
        ]);
    }
    foreach ([
        [SUPER_ID, null, 'root@example.test', Role::SUPER_ADMIN],
        [ADMIN_A_ID, TEAM_A_ID, 'admin-a@example.test', Role::TEAM_ADMIN],
        [STAFF_A_ID, TEAM_A_ID, 'staff-a@example.test', Role::STAFF],
        [STAFF_A2_ID, TEAM_A_ID, 'staff-a2@example.test', Role::STAFF],
        [ADMIN_B_ID, TEAM_B_ID, 'admin-b@example.test', Role::TEAM_ADMIN],
        [STAFF_B_ID, TEAM_B_ID, 'staff-b@example.test', Role::STAFF],
    ] as [$id, $teamId, $email, $role]) {
        $repository->insertUser([
            'id' => $id,
            'team_id' => $teamId,
            'email' => $email,
            'normalized_email' => $email,
            'display_name' => $email,
            'password_hash' => $hasher->hash('Correct horse battery staple 123!'),
            'role' => $role,
            'status' => 'active',
            'version' => 1,
            'created_at' => '2026-01-01 00:00:00.000000',
            'updated_at' => '2026-01-01 00:00:00.000000',
        ]);
    }
    $repository->instances = [
        INSTANCE_A_ID => [
            'id' => INSTANCE_A_ID,
            'team_id' => TEAM_A_ID,
            'created_by_user_id' => STAFF_A_ID,
            'current_owner_user_id' => STAFF_A_ID,
            'version' => 1,
            'template_version_id' => TEMPLATE_A_VERSION_ID,
        ],
        INSTANCE_B_ID => [
            'id' => INSTANCE_B_ID,
            'team_id' => TEAM_B_ID,
            'created_by_user_id' => STAFF_B_ID,
            'current_owner_user_id' => STAFF_B_ID,
            'version' => 1,
        ],
    ];
    $repository->templateVersions[TEMPLATE_A_VERSION_ID] = [
        'id' => TEMPLATE_A_VERSION_ID,
        'template_id' => '01NNNNNNNNNNNNNNNNNNNNNNNN',
        'owner_team_id' => TEAM_A_ID,
        'uploaded_by_user_id' => ADMIN_A_ID,
        'validation_status' => 'valid',
        'template_status' => 'active',
    ];
    $principals = [];
    foreach ($repository->users as $id => $user) {
        $principals[$id] = Principal::fromRow($user);
    }
    return [$repository, $principals];
}

/** @return ControlPlaneKernel */
function kernel(MemoryControlPlaneRepository $repository): ControlPlaneKernel
{
    $authorization = new AuthorizationService();
    return new ControlPlaneKernel(
        new SessionService($repository),
        new AccountService($repository, $authorization),
        new ResourceAccessService($repository, $authorization),
        new OwnershipTransferService($repository, $authorization),
    );
}

/** @return array<string,\Closure> */
return [
    'control plane enforces three role scopes' => static function (): void {
        [$repository, $p] = controlFixture();
        $resources = new ResourceAccessService($repository, new AuthorizationService());
        TestHarness::assertSame(2, count($resources->listInstances($p[SUPER_ID])));
        TestHarness::assertSame(1, count($resources->listInstances($p[ADMIN_A_ID])));
        TestHarness::assertSame(INSTANCE_A_ID, $resources->listInstances($p[STAFF_A_ID])[0]['id']);
        TestHarness::assertSame([], $resources->listInstances($p[STAFF_A2_ID]));
    },

    'staff forged resource id and cross-team admin are denied' => static function (): void {
        [$repository, $p] = controlFixture();
        $authorization = new AuthorizationService();
        $exception = TestHarness::assertThrows(
            static fn () => $authorization->assertInstanceAccess($p[STAFF_A_ID], $repository->instances[INSTANCE_B_ID]),
            ApiException::class,
        );
        TestHarness::assertSame(404, $exception->status);
        $exception = TestHarness::assertThrows(
            static fn () => $authorization->assertInstanceAccess($p[ADMIN_A_ID], $repository->instances[INSTANCE_B_ID]),
            ApiException::class,
        );
        TestHarness::assertSame('resource_not_found', $exception->errorCode);
    },

    'team admin can only create and disable own team staff' => static function (): void {
        [$repository, $p] = controlFixture();
        $accounts = new AccountService($repository, new AuthorizationService());
        $created = $accounts->createUser(
            $p[ADMIN_A_ID],
            'new@example.test',
            'New Staff',
            Role::SUPER_ADMIN,
            TEAM_B_ID,
            'Another secure password 123!',
            'request-1',
        );
        TestHarness::assertSame(Role::STAFF, $created['role']);
        TestHarness::assertSame(TEAM_A_ID, $created['team_id']);
        TestHarness::assertFalse(array_key_exists('password_hash', $created));
        $exception = TestHarness::assertThrows(
            static fn () => $accounts->disableUser($p[ADMIN_A_ID], STAFF_B_ID, 'request-2'),
            ApiException::class,
        );
        TestHarness::assertSame(404, $exception->status);
        $exception = TestHarness::assertThrows(
            static fn () => $accounts->listUsers($p[STAFF_A_ID]),
            ApiException::class,
        );
        TestHarness::assertSame(403, $exception->status);
    },

    'password login csrf logout and disabled session are enforced' => static function (): void {
        [$repository, $p] = controlFixture();
        $sessions = new SessionService($repository);
        TestHarness::assertThrows(
            static fn () => $sessions->login('staff-a@example.test', 'wrong password'),
            ApiException::class,
        );
        $authenticated = $sessions->login('staff-a@example.test', 'Correct horse battery staple 123!');
        $row = $sessions->authenticate($authenticated->cookieToken);
        TestHarness::assertSame(STAFF_A_ID, $row['principal']->id);
        TestHarness::assertThrows(
            static fn () => $sessions->assertCsrf($row['session'], 'forged'),
            ApiException::class,
        );
        $sessions->assertCsrf($row['session'], $authenticated->csrfToken);
        $repository->users[STAFF_A_ID]['status'] = 'disabled';
        $exception = TestHarness::assertThrows(
            static fn () => $sessions->authenticate($authenticated->cookieToken),
            ApiException::class,
        );
        TestHarness::assertSame('session_invalid', $exception->errorCode);
    },

    'disabling account revokes every old session' => static function (): void {
        [$repository, $p] = controlFixture();
        $sessions = new SessionService($repository);
        $token = $sessions->login('staff-a@example.test', 'Correct horse battery staple 123!')->cookieToken;
        (new AccountService($repository, new AuthorizationService()))->disableUser($p[ADMIN_A_ID], STAFF_A_ID, 'request-3');
        TestHarness::assertThrows(static fn () => $sessions->authenticate($token), ApiException::class);
        TestHarness::assertSame('disabled', $repository->users[STAFF_A_ID]['status']);
    },

    'password change verifies current password and invalidates sessions' => static function (): void {
        [$repository, $p] = controlFixture();
        $sessions = new SessionService($repository);
        $login = $sessions->login('staff-a@example.test', 'Correct horse battery staple 123!');
        $accounts = new AccountService($repository, new AuthorizationService());
        TestHarness::assertThrows(
            static fn () => $accounts->changeOwnPassword($p[STAFF_A_ID], 'bad', 'New password 123456789!', 'request-4'),
            ApiException::class,
        );
        $accounts->changeOwnPassword(
            $p[STAFF_A_ID],
            'Correct horse battery staple 123!',
            'New password 123456789!',
            'request-5',
        );
        TestHarness::assertThrows(static fn () => $sessions->authenticate($login->cookieToken), ApiException::class);
        $sessions->login('staff-a@example.test', 'New password 123456789!');
    },

    'owner transfer preserves creator and records history' => static function (): void {
        [$repository, $p] = controlFixture();
        $service = new OwnershipTransferService($repository, new AuthorizationService());
        $updated = $service->transfer(
            $p[ADMIN_A_ID],
            INSTANCE_A_ID,
            STAFF_A2_ID,
            1,
            '工作调整',
            'request-6',
        );
        TestHarness::assertSame(STAFF_A2_ID, $updated['current_owner_user_id']);
        TestHarness::assertSame(STAFF_A_ID, $updated['created_by_user_id']);
        TestHarness::assertSame(2, $updated['version']);
        TestHarness::assertSame(1, count($repository->ownerHistory));
        TestHarness::assertThrows(
            static fn () => $service->transfer($p[ADMIN_A_ID], INSTANCE_A_ID, STAFF_B_ID, 2, 'bad team', 'request-7'),
            ApiException::class,
        );
        TestHarness::assertThrows(
            static fn () => $service->transfer($p[STAFF_A2_ID], INSTANCE_A_ID, STAFF_A_ID, 2, 'staff cannot', 'request-8'),
            ApiException::class,
        );
    },

    'template grant revoke cannot be bypassed by ownership and can be restored only by super admin' => static function (): void {
        [$repository, $p] = controlFixture();
        $access = new ResourceAccessService($repository, new AuthorizationService());
        $grants = new TemplateGrantService($repository, new AuthorizationService());

        $access->assertTemplateVersion($p[SUPER_ID], TEAM_A_ID, TEMPLATE_A_VERSION_ID);
        TestHarness::assertThrows(
            static fn () => $access->assertTemplateVersion($p[ADMIN_A_ID], TEAM_A_ID, TEMPLATE_A_VERSION_ID),
            ApiException::class,
        );
        TestHarness::assertThrows(
            static fn () => $grants->grantUploaderTeam($p[ADMIN_B_ID], TEMPLATE_A_VERSION_ID, 'request-false-owner'),
            ApiException::class,
        );
        $grants->grantUploaderTeam($p[ADMIN_A_ID], TEMPLATE_A_VERSION_ID, 'request-grant-default');
        $access->assertTemplateVersion($p[ADMIN_A_ID], TEAM_A_ID, TEMPLATE_A_VERSION_ID);
        $access->assertTemplateVersion($p[STAFF_A_ID], TEAM_A_ID, TEMPLATE_A_VERSION_ID);

        TestHarness::assertThrows(
            static fn () => $access->assertTemplateVersion($p[ADMIN_B_ID], TEAM_B_ID, TEMPLATE_A_VERSION_ID),
            ApiException::class,
        );
        $grants->revokeBySuperAdmin($p[SUPER_ID], TEMPLATE_A_VERSION_ID, TEAM_A_ID, 'request-grant-revoke');
        TestHarness::assertThrows(
            static fn () => $access->assertTemplateVersion($p[ADMIN_A_ID], TEAM_A_ID, TEMPLATE_A_VERSION_ID),
            ApiException::class,
        );
        TestHarness::assertThrows(
            static fn () => $access->assertTemplateVersion($p[STAFF_A_ID], TEAM_A_ID, TEMPLATE_A_VERSION_ID),
            ApiException::class,
        );
        $access->assertTemplateVersion($p[SUPER_ID], TEAM_A_ID, TEMPLATE_A_VERSION_ID);
        $exception = TestHarness::assertThrows(
            static fn () => $grants->grantUploaderTeam($p[ADMIN_A_ID], TEMPLATE_A_VERSION_ID, 'request-owner-bypass'),
            ApiException::class,
        );
        TestHarness::assertSame('template_grant_revoked', $exception->errorCode);

        // 撤销只阻止新实例选用模板，不改变已部署实例或其负责人访问范围。
        TestHarness::assertSame(INSTANCE_A_ID, $access->listInstances($p[ADMIN_A_ID])[0]['id']);

        $grants->grantBySuperAdmin($p[SUPER_ID], TEMPLATE_A_VERSION_ID, TEAM_A_ID, 'request-grant-restore');
        $access->assertTemplateVersion($p[ADMIN_A_ID], TEAM_A_ID, TEMPLATE_A_VERSION_ID);
        $grants->grantBySuperAdmin($p[SUPER_ID], TEMPLATE_A_VERSION_ID, TEAM_B_ID, 'request-grant-cross-team');
        $access->assertTemplateVersion($p[ADMIN_B_ID], TEAM_B_ID, TEMPLATE_A_VERSION_ID);

        TestHarness::assertThrows(
            static fn () => $access->assertTemplateVersion($p[SUPER_ID], TEAM_A_ID, '01PPPPPPPPPPPPPPPPPPPPPPPP'),
            ApiException::class,
        );
    },

    'domain grants remain centralized' => static function (): void {
        [$repository, $p] = controlFixture();
        $access = new ResourceAccessService($repository, new AuthorizationService());
        TestHarness::assertThrows(
            static fn () => $access->assertPrimaryDomain($p[ADMIN_A_ID], TEAM_A_ID, 'domain-1'),
            ApiException::class,
        );
        $repository->domainGrants['domain-1:' . TEAM_A_ID] = true;
        $access->assertPrimaryDomain($p[ADMIN_A_ID], TEAM_A_ID, 'domain-1');
    },

    'bootstrap admin is single and transactional' => static function (): void {
        $repository = new MemoryControlPlaneRepository();
        $service = new BootstrapAdminService($repository);
        $created = $service->createFirst('root@example.test', 'Root', 'Safe bootstrap password 123!');
        TestHarness::assertSame(Role::SUPER_ADMIN, $created['role']);
        TestHarness::assertFalse(array_key_exists('password_hash', $created));
        TestHarness::assertThrows(
            static fn () => $service->createFirst('root2@example.test', 'Root 2', 'Safe bootstrap password 456!'),
            ApiException::class,
        );
        TestHarness::assertSame(1, count($repository->users));
    },

    'audit metadata recursively removes credentials' => static function (): void {
        [$repository, $p] = controlFixture();
        (new AuditLogger($repository))->record(
            $p[ADMIN_A_ID],
            'security.test',
            'user',
            STAFF_A_ID,
            'request-security',
            [
                'safe' => 'kept',
                'password' => 'top-secret-value',
                'nested' => ['api_key' => 'nested-secret-value', 'result' => 'ok'],
            ],
        );
        $encoded = json_encode($repository->audits[0]['metadata_json'], JSON_THROW_ON_ERROR);
        TestHarness::assertContains('kept', $encoded);
        TestHarness::assertNotContains('top-secret-value', $encoded);
        TestHarness::assertNotContains('nested-secret-value', $encoded);
    },

    'api uses safe envelope csrf secure cookie and explicit not implemented' => static function (): void {
        [$repository] = controlFixture();
        $kernel = kernel($repository);
        $login = $kernel->handle(new Request('POST', '/api/v1/login', [], [], [
            'email' => 'admin-a@example.test',
            'password' => 'Correct horse battery staple 123!',
        ]));
        TestHarness::assertSame(200, $login->status);
        TestHarness::assertContains('HttpOnly', $login->headers['Set-Cookie']);
        TestHarness::assertContains('Secure', $login->headers['Set-Cookie']);
        $encoded = json_encode($login->body, JSON_THROW_ON_ERROR);
        TestHarness::assertNotContains('password', $encoded);
        preg_match('/landingops_session=([^;]+)/', $login->headers['Set-Cookie'], $matches);
        $cookie = rawurldecode($matches[1]);
        $csrf = $login->body['data']['csrf_token'];
        $me = $kernel->handle(new Request('GET', '/api/v1/me', [], [ControlPlaneKernel::COOKIE => $cookie]));
        TestHarness::assertSame(200, $me->status);
        $refreshedCsrf = $me->body['data']['csrf_token'];
        TestHarness::assertTrue(is_string($refreshedCsrf) && $refreshedCsrf !== $csrf);
        $forged = $kernel->handle(new Request('POST', '/api/v1/users', [], [ControlPlaneKernel::COOKIE => $cookie], [
            'email' => 'api@example.test',
            'display_name' => 'API Staff',
            'role' => Role::STAFF,
            'team_id' => TEAM_A_ID,
            'password' => 'API secure password 123!',
        ]));
        TestHarness::assertSame(403, $forged->status);
        TestHarness::assertSame('csrf_invalid', $forged->body['error']['code']);
        $future = $kernel->handle(new Request('GET', '/api/v1/templates', [], [ControlPlaneKernel::COOKIE => $cookie]));
        TestHarness::assertSame(501, $future->status);
        TestHarness::assertSame('not_implemented', $future->body['error']['code']);
        $staleLogout = $kernel->handle(new Request('POST', '/api/v1/logout', [
            'X-CSRF-Token' => $csrf,
        ], [ControlPlaneKernel::COOKIE => $cookie]));
        TestHarness::assertSame(403, $staleLogout->status);
        TestHarness::assertSame('csrf_invalid', $staleLogout->body['error']['code']);
        $logout = $kernel->handle(new Request('POST', '/api/v1/logout', [
            'X-CSRF-Token' => $refreshedCsrf,
        ], [ControlPlaneKernel::COOKIE => $cookie]));
        TestHarness::assertSame(200, $logout->status);
    },

    'migration sql defines v1 constraints without secrets' => static function (): void {
        $migrationDirectory = dirname(__DIR__) . '/database/migrations';
        $migration001 = $migrationDirectory . '/001_v1_control_plane.sql';
        $sql = (string) file_get_contents($migration001);
        TestHarness::assertSame(
            '72e62312d5eb77b9364c032d60ec720a94df040a3f6dfb38d0a2d2fcdef1eac9',
            hash_file('sha256', $migration001),
            '001 migration must remain immutable after Phase 1C.1 acceptance.',
        );
        foreach ([
            'teams', 'users', 'auth_sessions', 'templates', 'template_versions', 'template_grants',
            'baota_config', 'cloudflare_connections', 'cloudflare_zones', 'primary_domains', 'domain_grants',
            'instances', 'instance_owner_history', 'config_versions', 'site_releases', 'deployment_batches',
            'deployment_jobs', 'job_steps', 'domain_reservations', 'audit_logs',
        ] as $table) {
            TestHarness::assertContains('CREATE TABLE IF NOT EXISTS ' . $table, $sql);
        }
        TestHarness::assertContains('CONSTRAINT uq_baota_config_singleton UNIQUE (singleton_key)', $sql);
        TestHarness::assertContains('CONSTRAINT chk_baota_config_singleton CHECK (singleton_key = 1)', $sql);
        TestHarness::assertContains('INDEX idx_config_versions_content_hash (instance_id, content_hash)', $sql);
        TestHarness::assertNotContains('UNIQUE KEY uq_config_versions_hash', $sql);
        TestHarness::assertContains('COLLATE=utf8mb4_unicode_ci', $sql);
        TestHarness::assertNotContains('utf8mb4_0900_ai_ci', $sql);
        TestHarness::assertContains('CONSTRAINT chk_site_releases_active_key CHECK', $sql);
        TestHarness::assertContains('CONSTRAINT chk_domain_reservations_active_key CHECK', $sql);
        TestHarness::assertNotContains('password123', strtolower($sql));
        TestHarness::assertTrue(count((new SqlStatementSplitter())->split($sql)) >= 20);

        $sql002 = (string) file_get_contents($migrationDirectory . '/002_fix_active_key_null_constraints.sql');
        TestHarness::assertContains('active_instance_id IS NOT NULL', $sql002);
        TestHarness::assertContains('active_fqdn IS NOT NULL', $sql002);
        TestHarness::assertContains('chk_site_releases_active_key_not_null', $sql002);
        TestHarness::assertContains('chk_domain_reservations_active_key_not_null', $sql002);
        TestHarness::assertTrue(
            strpos($sql002, 'INSERT INTO landingops_002_active_key_guard')
                < strpos($sql002, 'ALTER TABLE site_releases'),
            'Historical anomaly guard must run before permanent DDL.',
        );
        $sql003 = (string) file_get_contents($migrationDirectory . '/003_split_cloudflare_admin_verification_state.sql');
        foreach (['admin_state', 'verification_status', 'last_verification_attempt_at', 'last_verification_result_json', 'version'] as $column) {
            TestHarness::assertContains($column, $sql003);
        }
        TestHarness::assertContains('cloudflare_status', $sql003);
        TestHarness::assertNotContains('DROP TABLE', strtoupper($sql003));
    },
];
