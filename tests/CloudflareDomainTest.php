<?php

declare(strict_types=1);

namespace LandingOps\Tests;

use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\SessionService;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Http\ControlPlaneKernel;
use LandingOps\ControlPlane\Http\Request;
use LandingOps\ControlPlane\Security\AeadSecretCipher;
use LandingOps\ControlPlane\Cloudflare\CloudflareReadOnlyClient;
use LandingOps\ControlPlane\Service\AccountService;
use LandingOps\ControlPlane\Service\CloudflareConnectionService;
use LandingOps\ControlPlane\Service\CloudflareDnsPrecheckService;
use LandingOps\ControlPlane\Service\CloudflareVerificationService;
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
        $rotated = $rotating->update($principals[SUPER_ID], $id, [
            'api_token' => $tokenV2, 'expected_version' => 1,
        ], 'cf-rotate');
        $stored = $repository->cloudflareConnections[$id];
        TestHarness::assertSame('v2', $stored['secret_key_version']);
        TestHarness::assertSame($tokenV2, $cipherV2->decrypt($stored['token_ciphertext'], 'v2', 'landingops:cloudflare-token:' . $id));
        TestHarness::assertSame('unverified', $rotated['status']);
        $disabled = $rotating->update($principals[SUPER_ID], $id, [
            'admin_state' => 'disabled', 'expected_version' => 2,
        ], 'cf-disable');
        TestHarness::assertSame('disabled', $disabled['admin_state']);
        $rotatedWhileDisabled = $rotating->update($principals[SUPER_ID], $id, [
            'api_token' => 'third-synthetic-token', 'expected_version' => 3,
        ], 'cf-rotate-disabled');
        TestHarness::assertSame('disabled', $rotatedWhileDisabled['admin_state']);
        TestHarness::assertSame('unverified', $rotatedWhileDisabled['verification_status']);
        TestHarness::assertSame('disabled', $rotatedWhileDisabled['status']);
        $conflict = TestHarness::assertThrows(
            static fn () => $rotating->update($principals[SUPER_ID], $id, [
                'admin_state' => 'enabled', 'expected_version' => 3,
            ], 'cf-stale-enable'),
            ApiException::class,
        );
        TestHarness::assertSame(409, $conflict->status);
        $enabled = $rotating->update($principals[SUPER_ID], $id, [
            'admin_state' => 'enabled', 'expected_version' => 4,
        ], 'cf-enable');
        TestHarness::assertSame('enabled', $enabled['admin_state']);
        TestHarness::assertTrue(count(array_filter(
            $repository->audits,
            static fn (array $audit): bool => in_array($audit['action'], ['cloudflare.connection_disable', 'cloudflare.connection_enable'], true),
        )) === 2);
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

    'token and account changes invalidate every zone credential binding' => static function (): void {
        [$repository, $principals] = controlFixture();
        [$connections, $domains, $cipher] = cloudflareServices($repository);
        $connection = $connections->create($principals[SUPER_ID], [
            'label' => 'Multi zone', 'account_external_id' => 'acct_1', 'api_token' => 'old-synthetic-token',
        ], 'create');
        $zoneA = $domains->createZone($principals[SUPER_ID], [
            'connection_id' => $connection['id'], 'external_zone_id' => 'zone_A', 'zone_name' => 'a.example.com',
        ], 'zone-a');
        $zoneB = $domains->createZone($principals[SUPER_ID], [
            'connection_id' => $connection['id'], 'external_zone_id' => 'zone_B', 'zone_name' => 'b.example.com',
        ], 'zone-b');
        $clientFor = static fn (string $zoneId, string $name): CloudflareReadOnlyClient => new CloudflareReadOnlyClient(
            new FakeCloudflareTransport(
                cfResponse(200, ['success' => true, 'result' => ['id' => 'token', 'status' => 'active']]),
                cfResponse(200, ['success' => true, 'result' => ['id' => $zoneId, 'name' => $name, 'status' => 'active', 'account' => ['id' => 'acct_1']]]),
                cfResponse(200, ['success' => true, 'result' => [], 'result_info' => ['total_pages' => 1]]),
            ),
            static function (int $microseconds): void {},
        );
        (new CloudflareVerificationService($repository, $cipher, $clientFor('zone_A', 'a.example.com')))
            ->verify($principals[SUPER_ID], $connection['id'], $zoneA['id'], 1, 1, 'verify-a-old');
        $repository->cloudflareConnections[$connection['id']]['last_verification_attempt_at'] = null;
        (new CloudflareVerificationService($repository, $cipher, $clientFor('zone_B', 'b.example.com')))
            ->verify($principals[SUPER_ID], $connection['id'], $zoneB['id'], 3, 1, 'verify-b-old');
        $domains->updateZone($principals[SUPER_ID], $zoneB['id'], ['admin_state' => 'disabled', 'expected_version' => 2], 'disable-b');

        $rotated = $connections->update($principals[SUPER_ID], $connection['id'], [
            'api_token' => 'zone-a-only-synthetic-token', 'expected_version' => 5,
        ], 'rotate');
        TestHarness::assertSame(2, $rotated['credential_context_version']);
        TestHarness::assertSame('unverified', $repository->cloudflareZones[$zoneA['id']]['verification_status']);
        TestHarness::assertSame('unverified', $repository->cloudflareZones[$zoneB['id']]['verification_status']);
        TestHarness::assertSame('disabled', $repository->cloudflareZones[$zoneB['id']]['admin_state']);
        TestHarness::assertSame(null, $repository->cloudflareZones[$zoneB['id']]['verified_credential_context_version']);

        $stale = TestHarness::assertThrows(
            static fn () => (new CloudflareVerificationService($repository, $cipher, $clientFor('zone_A', 'a.example.com')))
                ->verify($principals[SUPER_ID], $connection['id'], $zoneA['id'], 6, 2, 'verify-a-stale'),
            ApiException::class,
        );
        TestHarness::assertSame(409, $stale->status);

        (new CloudflareVerificationService($repository, $cipher, $clientFor('zone_A', 'a.example.com')))
            ->verify($principals[SUPER_ID], $connection['id'], $zoneA['id'], 6, 3, 'verify-a-new');
        TestHarness::assertSame('valid', $repository->cloudflareZones[$zoneA['id']]['verification_status']);
        TestHarness::assertSame(2, $repository->cloudflareZones[$zoneA['id']]['verified_credential_context_version']);
        TestHarness::assertSame('unverified', $repository->cloudflareZones[$zoneB['id']]['verification_status']);

        $domains->updateZone($principals[SUPER_ID], $zoneB['id'], ['admin_state' => 'enabled', 'expected_version' => 4], 'enable-b');
        $repository->cloudflareConnections[$connection['id']]['last_verification_attempt_at'] = null;
        (new CloudflareVerificationService($repository, $cipher, $clientFor('zone_B', 'b.example.com')))
            ->verify($principals[SUPER_ID], $connection['id'], $zoneB['id'], 8, 5, 'verify-b-new');
        TestHarness::assertSame(2, $repository->cloudflareZones[$zoneB['id']]['verified_credential_context_version']);

        $changedAccount = $connections->update($principals[SUPER_ID], $connection['id'], [
            'account_external_id' => 'acct_2', 'expected_version' => 10,
        ], 'account-change');
        TestHarness::assertSame(3, $changedAccount['credential_context_version']);
        TestHarness::assertSame('unverified', $repository->cloudflareZones[$zoneA['id']]['verification_status']);
        TestHarness::assertSame('unverified', $repository->cloudflareZones[$zoneB['id']]['verification_status']);
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
        $repository->cloudflareConnections[$connection['id']]['verification_status'] = 'valid';
        $repository->cloudflareZones[$zone['id']]['status'] = 'active';
        $repository->cloudflareZones[$zone['id']]['verification_status'] = 'valid';
        $repository->cloudflareZones[$zone['id']]['verified_credential_context_version'] = 1;
        $repository->cloudflareZones[$zone['id']]['cloudflare_status'] = 'active';
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

        $connections->update($principals[SUPER_ID], $connection['id'], [
            'admin_state' => 'disabled', 'expected_version' => 1,
        ], 'disable-connection');
        TestHarness::assertSame([], $domains->listDomains($principals[STAFF_A_ID]));
        TestHarness::assertSame(1, count($domains->listDomains($principals[ADMIN_A_ID])));
        $repository->cloudflareConnections[$connection['id']]['status'] = 'active';
        $repository->cloudflareConnections[$connection['id']]['admin_state'] = 'enabled';
        $domains->updateZone($principals[SUPER_ID], $zone['id'], [
            'admin_state' => 'disabled', 'expected_version' => 1,
        ], 'disable-zone');
        TestHarness::assertSame([], $domains->listDomains($principals[STAFF_A_ID]));
        TestHarness::assertSame(1, count($domains->listDomains($principals[ADMIN_A_ID])));
        $repository->cloudflareZones[$zone['id']]['status'] = 'active';
        $repository->cloudflareZones[$zone['id']]['admin_state'] = 'enabled';
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

    'read only verification commits trusted state only after token zone and dns checks' => static function (): void {
        [$repository, $principals] = controlFixture();
        [$connections, $domains, $cipher] = cloudflareServices($repository);
        $connection = $connections->create($principals[SUPER_ID], ['label' => 'Verify', 'account_external_id' => 'acct_1', 'api_token' => 'synthetic-token-value'], 'create');
        $zone = $domains->createZone($principals[SUPER_ID], ['connection_id' => $connection['id'], 'external_zone_id' => 'zone_1', 'zone_name' => 'example.com'], 'zone');
        $transport = new FakeCloudflareTransport(
            cfResponse(200, ['success' => true, 'result' => ['id' => 'token_1', 'status' => 'active']]),
            cfResponse(200, ['success' => true, 'result' => ['id' => 'zone_1', 'name' => 'example.com', 'status' => 'active', 'account' => ['id' => 'acct_1']]]),
            cfResponse(200, ['success' => true, 'result' => [], 'result_info' => ['total_pages' => 1]]),
        );
        $service = new CloudflareVerificationService($repository, $cipher, new CloudflareReadOnlyClient($transport, static function (int $microseconds): void {}));
        $result = $service->verify($principals[SUPER_ID], $connection['id'], $zone['id'], 1, 1, 'verify');
        TestHarness::assertSame('success', $result['status']);
        TestHarness::assertSame('valid', $repository->cloudflareConnections[$connection['id']]['verification_status']);
        TestHarness::assertSame('valid', $repository->cloudflareZones[$zone['id']]['verification_status']);
        TestHarness::assertSame('active', $repository->cloudflareZones[$zone['id']]['cloudflare_status']);
        TestHarness::assertSame(3, count($transport->paths));
    },

    'wrong cloudflare zone is rejected and never trusted' => static function (): void {
        [$repository, $principals] = controlFixture();
        [$connections, $domains, $cipher] = cloudflareServices($repository);
        $connection = $connections->create($principals[SUPER_ID], ['label' => 'Mismatch', 'api_token' => 'synthetic-token-value'], 'create');
        $zone = $domains->createZone($principals[SUPER_ID], ['connection_id' => $connection['id'], 'external_zone_id' => 'zone_expected', 'zone_name' => 'example.com'], 'zone');
        $transport = new FakeCloudflareTransport(
            cfResponse(200, ['success' => true, 'result' => ['id' => 'token_1', 'status' => 'active']]),
            cfResponse(200, ['success' => true, 'result' => ['id' => 'zone_other', 'name' => 'other.example', 'status' => 'active']]),
        );
        $service = new CloudflareVerificationService($repository, $cipher, new CloudflareReadOnlyClient($transport, static function (int $microseconds): void {}));
        $error = TestHarness::assertThrows(
            static fn () => $service->verify($principals[SUPER_ID], $connection['id'], $zone['id'], 1, 1, 'verify'),
            ApiException::class,
        );
        TestHarness::assertSame('cloudflare_zone_mismatch', $error->errorCode);
        TestHarness::assertSame('unverified', $repository->cloudflareZones[$zone['id']]['verification_status']);
        TestHarness::assertSame('invalid', $repository->cloudflareConnections[$connection['id']]['verification_status']);
    },

    'dns precheck classifies clear compatible and conflicting records without writes' => static function (): void {
        [$repository, $principals] = controlFixture();
        [$connections, $domains, $cipher] = cloudflareServices($repository);
        $connection = $connections->create($principals[SUPER_ID], ['label' => 'DNS', 'api_token' => 'synthetic-token-value'], 'create');
        $zone = $domains->createZone($principals[SUPER_ID], ['connection_id' => $connection['id'], 'external_zone_id' => 'zone_dns', 'zone_name' => 'example.com'], 'zone');
        $domain = $domains->createDomain($principals[SUPER_ID], ['zone_id' => $zone['id'], 'domain_name' => 'pages.example.com'], 'domain');
        $repository->cloudflareConnections[$connection['id']]['verification_status'] = 'valid';
        $repository->cloudflareZones[$zone['id']]['verification_status'] = 'valid';
        $repository->cloudflareZones[$zone['id']]['verified_credential_context_version'] = 1;
        $repository->cloudflareZones[$zone['id']]['cloudflare_status'] = 'active';
        $responses = [
            cfResponse(200, ['success' => true, 'result' => [], 'result_info' => ['total_pages' => 1]]),
            cfResponse(200, ['success' => true, 'result' => [['id' => 'a1', 'type' => 'A', 'name' => 'www.pages.example.com', 'content' => '203.0.113.10', 'proxied' => false, 'ttl' => 1]], 'result_info' => ['total_pages' => 1]]),
            cfResponse(200, ['success' => true, 'result' => [['id' => 'c1', 'type' => 'CNAME', 'name' => 'www.pages.example.com', 'content' => 'other.example.net', 'proxied' => false, 'ttl' => 1]], 'result_info' => ['total_pages' => 1]]),
            cfResponse(200, ['success' => true, 'result' => [['id' => 'p1', 'type' => 'A', 'name' => 'www.pages.example.com', 'content' => '203.0.113.10', 'proxied' => true, 'ttl' => 1]], 'result_info' => ['total_pages' => 1]]),
            cfResponse(200, ['success' => true, 'result' => [['id' => 't1', 'type' => 'A', 'name' => 'www.pages.example.com', 'content' => '203.0.113.10', 'proxied' => false, 'ttl' => 300]], 'result_info' => ['total_pages' => 1]]),
            cfResponse(200, ['success' => true, 'result' => [['id' => 'm1', 'type' => 'A', 'name' => 'www.pages.example.com', 'content' => '203.0.113.10']], 'result_info' => ['total_pages' => 1]]),
            cfResponse(200, ['success' => true, 'result' => [['id' => 'v1', 'type' => 'AAAA', 'name' => 'www.pages.example.com', 'content' => '2001:db8::1', 'proxied' => false, 'ttl' => 1]], 'result_info' => ['total_pages' => 1]]),
        ];
        $service = new CloudflareDnsPrecheckService($repository, $cipher, new CloudflareReadOnlyClient(new FakeCloudflareTransport(...$responses), static function (int $microseconds): void {}), confirmedPublicIpv4: '203.0.113.10');
        TestHarness::assertSame('clear', $service->check($principals[SUPER_ID], $domain['id'], 'www.pages.example.com')['classification']);
        TestHarness::assertSame('compatible', $service->check($principals[SUPER_ID], $domain['id'], 'www.pages.example.com')['classification']);
        TestHarness::assertSame('conflict', $service->check($principals[SUPER_ID], $domain['id'], 'www.pages.example.com')['classification']);
        $proxied = $service->check($principals[SUPER_ID], $domain['id'], 'www.pages.example.com');
        TestHarness::assertSame('conflict', $proxied['classification']);
        TestHarness::assertSame('proxied_mismatch', $proxied['issues'][0]['code']);
        $ttl = $service->check($principals[SUPER_ID], $domain['id'], 'www.pages.example.com');
        TestHarness::assertSame('conflict', $ttl['classification']);
        TestHarness::assertSame('ttl_mismatch', $ttl['issues'][0]['code']);
        $missing = $service->check($principals[SUPER_ID], $domain['id'], 'www.pages.example.com');
        TestHarness::assertSame('needs_configuration', $missing['classification']);
        TestHarness::assertSame(2, count($missing['issues']));
        $aaaa = $service->check($principals[SUPER_ID], $domain['id'], 'www.pages.example.com');
        TestHarness::assertSame('conflict', $aaaa['classification']);
        TestHarness::assertContains('通配符', implode(' ', $aaaa['limitations']));
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
