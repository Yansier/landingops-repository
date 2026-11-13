<?php

declare(strict_types=1);

namespace LandingOps\Tests;

use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\SessionService;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Http\ControlPlaneKernel;
use LandingOps\ControlPlane\Http\Request;
use LandingOps\ControlPlane\Security\AeadSecretCipher;
use LandingOps\ControlPlane\Service\AccountService;
use LandingOps\ControlPlane\Service\CloudflareConnectionService;
use LandingOps\ControlPlane\Service\DomainResourceService;
use LandingOps\ControlPlane\Service\OwnershipTransferService;
use LandingOps\ControlPlane\Service\ResourceAccessService;
use LandingOps\Exception\LandingOpsException;

/** @return array{0:CloudflareConnectionService,1:DomainResourceService,2:AeadSecretCipher,3:AeadSecretCipher} */
function cloudflareServices(MemoryControlPlaneRepository $repository): array
{
    $old = random_bytes(32);
    $new = random_bytes(32);
    $cipherV1 = new AeadSecretCipher(['v1' => $old], 'v1');
    $cipherV2 = new AeadSecretCipher(['v1' => $old, 'v2' => $new], 'v2');
    return [
        new CloudflareConnectionService($repository, $cipherV1, new AuthorizationService()),
        new DomainResourceService($repository, new AuthorizationService()),
        $cipherV1,
        $cipherV2,
    ];
}

/** @return array<string,\Closure> */
return [
    'cloudflare tokens are authenticated encrypted rotated and never exposed' => static function (): void {
        [$repository, $principals] = controlFixture();
        [$connections, , $cipherV1, $cipherV2] = cloudflareServices($repository);
        $tokenV1 = 'token-secret-value-v1';
        $created = $connections->create($principals[SUPER_ID], [
            'label' => 'Account A', 'account_external_id' => 'acct_A', 'api_token' => $tokenV1,
        ], 'cf-create');
        $id = (string) $created['id'];
        $stored = $repository->cloudflareConnections[$id];
        TestHarness::assertFalse($stored['token_ciphertext'] === $tokenV1);
        TestHarness::assertSame('v1', $stored['secret_key_version']);
        TestHarness::assertSame($tokenV1, $cipherV1->decrypt($stored['token_ciphertext'], 'v1', 'landingops:cloudflare-token:' . $id));
        TestHarness::assertNotContains($tokenV1, json_encode($created, JSON_THROW_ON_ERROR));
        TestHarness::assertNotContains('token_ciphertext', json_encode($connections->list($principals[SUPER_ID]), JSON_THROW_ON_ERROR));
        TestHarness::assertNotContains($tokenV1, json_encode($repository->audits, JSON_THROW_ON_ERROR));
        TestHarness::assertSame('unverified', $created['status']);

        $rotating = new CloudflareConnectionService($repository, $cipherV2, new AuthorizationService());
        $tokenV2 = 'token-secret-value-v2';
        $rotated = $rotating->update($principals[SUPER_ID], $id, ['api_token' => $tokenV2], 'cf-rotate');
        $stored = $repository->cloudflareConnections[$id];
        TestHarness::assertSame('v2', $stored['secret_key_version']);
        TestHarness::assertSame($tokenV2, $cipherV2->decrypt($stored['token_ciphertext'], 'v2', 'landingops:cloudflare-token:' . $id));
        TestHarness::assertSame('unverified', $rotated['status']);
        TestHarness::assertThrows(
            static fn () => $cipherV1->decrypt($stored['token_ciphertext'], 'v2', 'landingops:cloudflare-token:' . $id),
            LandingOpsException::class,
        );
        TestHarness::assertThrows(
            static fn () => $connections->list($principals[ADMIN_A_ID]),
            ApiException::class,
        );
    },

    'multiple cloudflare connections isolate zones and normalize duplicate domains' => static function (): void {
        [$repository, $principals] = controlFixture();
        [$connections, $domains] = cloudflareServices($repository);
        $a = $connections->create($principals[SUPER_ID], ['label' => 'A', 'api_token' => 'token-value-A'], 'a');
        $b = $connections->create($principals[SUPER_ID], ['label' => 'B', 'api_token' => 'token-value-B'], 'b');
        $zoneA = $domains->createZone($principals[SUPER_ID], [
            'connection_id' => $a['id'], 'external_zone_id' => 'zone_A', 'zone_name' => 'Example.COM.',
        ], 'zone-a');
        $zoneB = $domains->createZone($principals[SUPER_ID], [
            'connection_id' => $b['id'], 'external_zone_id' => 'zone_B', 'zone_name' => 'example.net',
        ], 'zone-b');
        TestHarness::assertSame($a['id'], $zoneA['connection_id']);
        TestHarness::assertSame($b['id'], $zoneB['connection_id']);
        TestHarness::assertSame('example.com', $zoneA['normalized_zone_name']);
        $duplicateExternal = TestHarness::assertThrows(
            static fn () => $domains->createZone($principals[SUPER_ID], [
                'connection_id' => $a['id'], 'external_zone_id' => 'zone_A', 'zone_name' => 'other.example.org',
            ], 'zone-external-duplicate'),
            ApiException::class,
        );
        TestHarness::assertSame('cloudflare_zone_conflict', $duplicateExternal->errorCode);

        $domain = $domains->createDomain($principals[SUPER_ID], [
            'zone_id' => $zoneA['id'], 'domain_name' => 'Pages.Example.COM.',
        ], 'domain-a');
        TestHarness::assertSame('pages.example.com', $domain['normalized_domain_name']);
        $duplicate = TestHarness::assertThrows(
            static fn () => $domains->createDomain($principals[SUPER_ID], [
                'zone_id' => $zoneA['id'], 'domain_name' => 'PAGES.EXAMPLE.COM',
            ], 'domain-duplicate'),
            ApiException::class,
        );
        TestHarness::assertSame('primary_domain_conflict', $duplicate->errorCode);
        $mismatch = TestHarness::assertThrows(
            static fn () => $domains->createDomain($principals[SUPER_ID], [
                'zone_id' => $zoneB['id'], 'domain_name' => 'outside.example.org',
            ], 'domain-mismatch'),
            ApiException::class,
        );
        TestHarness::assertSame('domain_zone_mismatch', $mismatch->errorCode);
    },

    'domain grants enforce role scope revoke regrant and upstream status' => static function (): void {
        [$repository, $principals] = controlFixture();
        [$connections, $domains] = cloudflareServices($repository);
        $connection = $connections->create($principals[SUPER_ID], ['label' => 'Grant', 'api_token' => 'token-grant-value'], 'c');
        $zone = $domains->createZone($principals[SUPER_ID], [
            'connection_id' => $connection['id'], 'external_zone_id' => 'zone_grant', 'zone_name' => 'grants.test',
        ], 'z');
        $domain = $domains->createDomain($principals[SUPER_ID], [
            'zone_id' => $zone['id'], 'domain_name' => 'pages.grants.test',
        ], 'd');
        $domains->grant($principals[SUPER_ID], $domain['id'], TEAM_A_ID, 'grant');
        TestHarness::assertSame(1, count($domains->listDomains($principals[ADMIN_A_ID])));
        TestHarness::assertSame([], $domains->listDomains($principals[ADMIN_B_ID]));
        TestHarness::assertSame([], $domains->listDomains($principals[STAFF_A_ID]));

        $repository->cloudflareConnections[$connection['id']]['status'] = 'active';
        $repository->cloudflareZones[$zone['id']]['status'] = 'active';
        TestHarness::assertSame(1, count($domains->listDomains($principals[STAFF_A_ID])));
        $domains->revoke($principals[SUPER_ID], $domain['id'], TEAM_A_ID, 'revoke');
        TestHarness::assertSame([], $domains->listDomains($principals[ADMIN_A_ID]));
        TestHarness::assertSame([], $domains->listDomains($principals[STAFF_A_ID]));
        TestHarness::assertSame(STAFF_A_ID, $repository->instances[INSTANCE_A_ID]['current_owner_user_id']);
        $domains->grant($principals[SUPER_ID], $domain['id'], TEAM_A_ID, 'regrant');
        TestHarness::assertSame(1, count($domains->listDomains($principals[STAFF_A_ID])));
        TestHarness::assertTrue(count(array_filter(
            $repository->audits,
            static fn (array $audit): bool => in_array($audit['action'], ['domain.grant_set', 'domain.grant_revoke'], true),
        )) === 3);

        $connections->update($principals[SUPER_ID], $connection['id'], ['status' => 'disabled'], 'disable-connection');
        TestHarness::assertSame([], $domains->listDomains($principals[STAFF_A_ID]));
        TestHarness::assertSame(1, count($domains->listDomains($principals[ADMIN_A_ID])));
        $repository->cloudflareConnections[$connection['id']]['status'] = 'active';
        $domains->updateZone($principals[SUPER_ID], $zone['id'], ['status' => 'disabled'], 'disable-zone');
        TestHarness::assertSame([], $domains->listDomains($principals[STAFF_A_ID]));
        TestHarness::assertSame(1, count($domains->listDomains($principals[ADMIN_A_ID])));
        $repository->cloudflareZones[$zone['id']]['status'] = 'active';
        $domains->updateDomain($principals[SUPER_ID], $domain['id'], ['status' => 'disabled'], 'disable-domain');
        TestHarness::assertSame([], $domains->listDomains($principals[STAFF_A_ID]));
        TestHarness::assertSame(1, count($domains->listDomains($principals[ADMIN_A_ID])));
        $forbidden = TestHarness::assertThrows(
            static fn () => $domains->grant($principals[ADMIN_A_ID], $domain['id'], TEAM_B_ID, 'forged'),
            ApiException::class,
        );
        TestHarness::assertSame(403, $forbidden->status);
    },

    'unconfigured key blocks token writes without leaking input' => static function (): void {
        [$repository, $principals] = controlFixture();
        $service = new CloudflareConnectionService($repository, null, new AuthorizationService());
        $exception = TestHarness::assertThrows(
            static fn () => $service->create($principals[SUPER_ID], [
                'label' => 'Missing key', 'api_token' => 'never-log-this-token',
            ], 'missing-key'),
            ApiException::class,
        );
        TestHarness::assertSame(503, $exception->status);
        TestHarness::assertNotContains('never-log-this-token', $exception->getMessage());
    },

    'cloudflare and domain API routes require session csrf and server authorization' => static function (): void {
        [$repository] = controlFixture();
        [$connections, $domains] = cloudflareServices($repository);
        $authorization = new AuthorizationService();
        $kernel = new ControlPlaneKernel(
            new SessionService($repository),
            new AccountService($repository, $authorization),
            new ResourceAccessService($repository, $authorization),
            new OwnershipTransferService($repository, $authorization),
            cloudflareConnections: $connections,
            domains: $domains,
        );
        $login = $kernel->handle(new Request('POST', '/api/v1/login', [], [], [
            'email' => 'root@example.test', 'password' => 'Correct horse battery staple 123!',
        ]));
        preg_match('/landingops_session=([^;]+)/', $login->headers['Set-Cookie'], $matches);
        $cookie = rawurldecode($matches[1]);
        $csrf = $login->body['data']['csrf_token'];
        $created = $kernel->handle(new Request(
            'POST', '/api/v1/cloudflare-connections', ['X-CSRF-Token' => $csrf],
            [ControlPlaneKernel::COOKIE => $cookie],
            ['label' => 'API connection', 'api_token' => 'api-route-secret-token'],
        ));
        TestHarness::assertSame(201, $created->status);
        $encoded = json_encode($created->body, JSON_THROW_ON_ERROR);
        TestHarness::assertNotContains('api-route-secret-token', $encoded);
        TestHarness::assertNotContains('token_ciphertext', $encoded);

        $staffLogin = $kernel->handle(new Request('POST', '/api/v1/login', [], [], [
            'email' => 'staff-a@example.test', 'password' => 'Correct horse battery staple 123!',
        ]));
        preg_match('/landingops_session=([^;]+)/', $staffLogin->headers['Set-Cookie'], $staffMatches);
        $forbidden = $kernel->handle(new Request(
            'GET', '/api/v1/cloudflare-connections', [],
            [ControlPlaneKernel::COOKIE => rawurldecode($staffMatches[1])],
        ));
        TestHarness::assertSame(403, $forbidden->status);
    },
];
