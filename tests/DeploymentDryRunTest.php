<?php

declare(strict_types=1);

namespace LandingOps\Tests;

use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\SessionService;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Http\ControlPlaneKernel;
use LandingOps\ControlPlane\Http\Request;
use LandingOps\ControlPlane\Service\AccountService;
use LandingOps\ControlPlane\Service\DeploymentDryRunService;
use LandingOps\ControlPlane\Service\OwnershipTransferService;
use LandingOps\ControlPlane\Service\ResourceAccessService;

/** @return array{0:MemoryControlPlaneRepository,1:array<string,\LandingOps\ControlPlane\Auth\Principal>,2:string,3:string} */
function dryRunFixture(): array
{
    [$repository, $principals] = controlFixture();
    $templateId = '01PPPPPPPPPPPPPPPPPPPPPPPP';
    $versionId = '01QQQQQQQQQQQQQQQQQQQQQQQQ';
    $domainId = '01RRRRRRRRRRRRRRRRRRRRRRRR';
    $connectionId = '01SSSSSSSSSSSSSSSSSSSSSSSS';
    $zoneId = '01TTTTTTTTTTTTTTTTTTTTTTTT';
    $repository->templates[$templateId] = [
        'id' => $templateId, 'slug' => 'dry-run-template', 'name' => 'Dry Run Template', 'description' => '',
        'owner_team_id' => TEAM_A_ID, 'created_by_user_id' => ADMIN_A_ID, 'status' => 'active',
        'created_at' => '2026-01-01 00:00:00.000000', 'updated_at' => '2026-01-01 00:00:00.000000',
    ];
    $repository->templateVersions[$versionId] = [
        'id' => $versionId, 'template_id' => $templateId, 'version' => '1.0.0', 'standard_version' => '1.0',
        'zip_sha256' => str_repeat('a', 64), 'validation_status' => 'valid',
        'config_schema_json' => file_get_contents(dirname(__DIR__) . '/examples/templates/minimal-lts-v1/config.schema.json'),
        'manifest_json' => file_get_contents(dirname(__DIR__) . '/examples/templates/minimal-lts-v1/landingops.json'),
        'validation_report_json' => '{"valid":true,"errors":[],"warnings":[]}',
        'uploaded_by_user_id' => ADMIN_A_ID, 'created_at' => '2026-01-01 00:00:00.000000',
    ];
    $repository->templateGrants[$versionId . ':' . TEAM_A_ID] = [
        'id' => 'grant-template', 'template_version_id' => $versionId, 'team_id' => TEAM_A_ID,
        'status' => 'active', 'granted_by_user_id' => SUPER_ID, 'granted_at' => '2026-01-01 00:00:00.000000',
    ];
    $repository->cloudflareConnections[$connectionId] = [
        'id' => $connectionId, 'label' => 'CF', 'status' => 'active', 'admin_state' => 'enabled',
        'verification_status' => 'valid', 'credential_context_version' => 2,
    ];
    $repository->cloudflareZones[$zoneId] = [
        'id' => $zoneId, 'connection_id' => $connectionId, 'zone_name' => 'example.test',
        'normalized_zone_name' => 'example.test', 'status' => 'active', 'admin_state' => 'enabled',
        'verification_status' => 'valid', 'verified_credential_context_version' => 2,
        'cloudflare_status' => 'active',
    ];
    $repository->primaryDomains[$domainId] = [
        'id' => $domainId, 'zone_id' => $zoneId, 'domain_name' => 'pages.example.test',
        'normalized_domain_name' => 'pages.example.test', 'status' => 'active',
    ];
    $repository->domainGrants[$domainId . ':' . TEAM_A_ID] = [
        'id' => 'grant-domain', 'primary_domain_id' => $domainId, 'team_id' => TEAM_A_ID,
        'status' => 'active', 'granted_by_user_id' => SUPER_ID, 'granted_at' => '2026-01-01 00:00:00.000000',
    ];
    return [$repository, $principals, $versionId, $domainId];
}

/** @return array<string,mixed> */
function validDryRunInput(string $versionId, string $domainId): array
{
    return [
        'template_version_id' => $versionId, 'primary_domain_id' => $domainId, 'subdomain_prefix' => 'offer',
        'config' => json_decode((string) file_get_contents(dirname(__DIR__) . '/examples/configs/minimal-instance.json'), true, 128, JSON_THROW_ON_ERROR),
    ];
}

/** @return array<string,\Closure> */
return [
    'dry run rechecks authorization validates config and performs no writes' => static function (): void {
        [$repository, $p, $versionId, $domainId] = dryRunFixture();
        $service = new DeploymentDryRunService($repository);
        $before = serialize([$repository->instances, $repository->domainReservations, $repository->audits]);
        $result = $service->run($p[STAFF_A_ID], validDryRunInput($versionId, $domainId));
        TestHarness::assertSame('offer.pages.example.test', $result['domain']['fqdn']);
        TestHarness::assertTrue($result['configuration']['valid']);
        TestHarness::assertFalse($result['configuration']['values_returned']);
        TestHarness::assertFalse($result['mutations_performed']);
        TestHarness::assertSame(false, $result['plan']['steps'][0]['execute']);
        TestHarness::assertSame($before, serialize([$repository->instances, $repository->domainReservations, $repository->audits]));
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        TestHarness::assertNotContains('server-only note', $encoded);
        TestHarness::assertNotContains('pixel_ids', $encoded);
    },

    'dry run rejects cross team and revoked grants from server data' => static function (): void {
        [$repository, $p, $versionId, $domainId] = dryRunFixture();
        $service = new DeploymentDryRunService($repository);
        TestHarness::assertSame(404, TestHarness::assertThrows(
            static fn () => $service->run($p[STAFF_B_ID], validDryRunInput($versionId, $domainId)), ApiException::class,
        )->status);
        $repository->templateGrants[$versionId . ':' . TEAM_A_ID]['status'] = 'revoked';
        TestHarness::assertSame('template_not_available', TestHarness::assertThrows(
            static fn () => $service->run($p[STAFF_A_ID], validDryRunInput($versionId, $domainId)), ApiException::class,
        )->errorCode);
    },

    'dry run blocks duplicate fqdn and invalid url while config form contains explicit private visibility' => static function (): void {
        [$repository, $p, $versionId, $domainId] = dryRunFixture();
        $service = new DeploymentDryRunService($repository);
        $repository->instances[INSTANCE_A_ID]['normalized_fqdn'] = 'offer.pages.example.test';
        $input = validDryRunInput($versionId, $domainId);
        $input['config']['links']['download_url'] = 'http://unsafe.example.test/file';
        $result = $service->run($p[ADMIN_A_ID], $input);
        TestHarness::assertSame('blocked', $result['status']);
        TestHarness::assertFalse($result['configuration']['valid']);
        TestHarness::assertTrue(in_array('fqdn_used_by_instance', array_column($result['blockers'], 'code'), true));
        $form = $service->configForm($p[STAFF_A_ID], $versionId);
        $private = array_values(array_filter($form['fields'], static fn (array $field): bool => $field['path'] === 'private_note'));
        TestHarness::assertSame('private', $private[0]['visibility']);
        TestHarness::assertTrue(count($form['fields']) >= 10);
    },

    'dry run refuses stale zone credential context and active reservation' => static function (): void {
        [$repository, $p, $versionId, $domainId] = dryRunFixture();
        $service = new DeploymentDryRunService($repository);
        $zoneId = (string) $repository->primaryDomains[$domainId]['zone_id'];
        $repository->cloudflareZones[$zoneId]['verified_credential_context_version'] = 1;
        TestHarness::assertSame('primary_domain_not_usable', TestHarness::assertThrows(
            static fn () => $service->run($p[SUPER_ID], validDryRunInput($versionId, $domainId)), ApiException::class,
        )->errorCode);
        $repository->cloudflareZones[$zoneId]['verified_credential_context_version'] = 2;
        $repository->domainReservations[] = ['normalized_fqdn' => 'offer.pages.example.test', 'status' => 'held'];
        $result = $service->run($p[SUPER_ID], validDryRunInput($versionId, $domainId));
        TestHarness::assertTrue(in_array('fqdn_reserved', array_column($result['blockers'], 'code'), true));
    },

    'dry run API requires csrf and uses the authenticated principal' => static function (): void {
        [$repository, , $versionId, $domainId] = dryRunFixture();
        $authorization = new AuthorizationService();
        $sessions = new SessionService($repository);
        $kernel = new ControlPlaneKernel(
            $sessions,
            new AccountService($repository, $authorization),
            new ResourceAccessService($repository, $authorization),
            new OwnershipTransferService($repository, $authorization),
            dryRuns: new DeploymentDryRunService($repository),
        );
        $login = $sessions->login('staff-a@example.test', 'Correct horse battery staple 123!');
        $cookies = [ControlPlaneKernel::COOKIE => $login->cookieToken];
        $form = $kernel->handle(new Request('GET', "/api/v1/template-versions/{$versionId}/config-form", [], $cookies));
        TestHarness::assertSame(200, $form->status);
        $withoutCsrf = $kernel->handle(new Request('POST', '/api/v1/deployment-dry-runs', [], $cookies, validDryRunInput($versionId, $domainId)));
        TestHarness::assertSame(403, $withoutCsrf->status);
        $response = $kernel->handle(new Request(
            'POST', '/api/v1/deployment-dry-runs', ['X-CSRF-Token' => $login->csrfToken],
            $cookies, validDryRunInput($versionId, $domainId),
        ));
        TestHarness::assertSame(200, $response->status);
        TestHarness::assertFalse($response->body['data']['mutations_performed']);
    },
];
