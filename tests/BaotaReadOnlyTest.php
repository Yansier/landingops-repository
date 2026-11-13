<?php

declare(strict_types=1);

namespace LandingOps\Tests;

use LandingOps\ControlPlane\Auth\AuthorizationService;
use LandingOps\ControlPlane\Auth\SessionService;
use LandingOps\ControlPlane\Baota\BaotaHttpResponse;
use LandingOps\ControlPlane\Baota\BaotaHttpTransport;
use LandingOps\ControlPlane\Baota\BaotaReadOnlyClient;
use LandingOps\ControlPlane\Baota\BaotaReadOnlyGateway;
use LandingOps\ControlPlane\Baota\BaotaSiteOwnershipEvaluator;
use LandingOps\ControlPlane\Baota\BaotaTransportException;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Http\ControlPlaneKernel;
use LandingOps\ControlPlane\Http\Request;
use LandingOps\ControlPlane\Security\AeadSecretCipher;
use LandingOps\ControlPlane\Service\BaotaConfigService;
use LandingOps\ControlPlane\Service\BaotaCompatibilityDiagnostic;
use LandingOps\ControlPlane\Service\BaotaReadOnlyService;
use LandingOps\ControlPlane\Service\AccountService;
use LandingOps\ControlPlane\Service\OwnershipTransferService;
use LandingOps\ControlPlane\Service\ResourceAccessService;
use LandingOps\Exception\LandingOpsException;

final class FakeBaotaTransport implements BaotaHttpTransport
{
    /** @var list<BaotaHttpResponse|BaotaTransportException> */
    public array $queue;
    /** @var list<array{base_url:string,path:string,fields:array<string,string|int>,timeout:float}> */
    public array $requests = [];

    public function __construct(BaotaHttpResponse|BaotaTransportException ...$queue)
    {
        $this->queue = $queue;
    }

    public function post(string $baseUrl, string $path, array $fields, float $timeoutSeconds): BaotaHttpResponse
    {
        $this->requests[] = ['base_url' => $baseUrl, 'path' => $path, 'fields' => $fields, 'timeout' => $timeoutSeconds];
        $next = array_shift($this->queue);
        if ($next instanceof BaotaTransportException) {
            throw $next;
        }
        if (!$next instanceof BaotaHttpResponse) {
            throw new \RuntimeException('Fake Baota response queue exhausted.');
        }
        return $next;
    }
}

final class FakeBaotaGateway implements BaotaReadOnlyGateway
{
    /** @var list<array<string,mixed>> */
    public array $siteRows = [];
    /** @var array<int,array{status:string,domains:list<string>}> */
    public array $domains = [];
    /** @var array<string,mixed> */
    public array $directory = ['status' => 'unsupported', 'path' => null, 'entry_count' => null, 'has_unknown_entries' => null, 'is_symlink' => null];
    public bool $inventoryComplete = true;
    public int $domainCalls = 0;
    /** @var list<int> */
    public array $failingDomainIds = [];

    public function systemSummary(string $baseUrl, string $apiKey): array
    {
        return ['panel_version' => '11.7.0', 'system' => 'Linux', 'version_status' => 'supported'];
    }

    public function sites(string $baseUrl, string $apiKey): array
    {
        return $this->siteRows;
    }

    public function siteInventory(string $baseUrl, string $apiKey): array
    {
        return [
            'sites' => $this->siteRows, 'complete' => $this->inventoryComplete,
            'pages' => 1, 'reason' => $this->inventoryComplete ? null : 'synthetic_incomplete', 'site_limit' => 200,
        ];
    }

    public function siteDomains(string $baseUrl, string $apiKey, int $siteId): array
    {
        $this->domainCalls++;
        if (in_array($siteId, $this->failingDomainIds, true)) {
            throw new ApiException(502, 'baota_timeout', 'synthetic timeout');
        }
        return $this->domains[$siteId] ?? ['status' => 'unsupported', 'domains' => []];
    }

    public function phpVersions(string $baseUrl, string $apiKey): array
    {
        return ['status' => 'supported', 'versions' => [['version' => '81', 'name' => 'PHP-8.1', 'running' => true]]];
    }

    public function sitePhpVersion(string $baseUrl, string $apiKey, string $siteName): array
    {
        return ['status' => 'supported', 'php_version' => '81'];
    }

    public function directoryObservation(string $normalizedPath): array
    {
        return $this->directory + ['path' => $normalizedPath];
    }
}

function baotaResponse(int $status, array $payload): BaotaHttpResponse
{
    return new BaotaHttpResponse($status, json_encode($payload, JSON_THROW_ON_ERROR));
}

/** @return array{0:MemoryControlPlaneRepository,1:array<string,\LandingOps\ControlPlane\Auth\Principal>,2:BaotaConfigService} */
function baotaFixture(): array
{
    [$repository, $principals] = controlFixture();
    $cipher = new AeadSecretCipher(['v1' => str_repeat('b', 32)], 'v1');
    return [$repository, $principals, new BaotaConfigService($repository, $cipher)];
}

/** @return array<string,\Closure> */
return [
    'baota compatibility diagnostic is bounded sanitized and blocks incomplete detail evidence' => static function (): void {
        $gateway = new FakeBaotaGateway();
        for ($i = 1; $i <= 21; $i++) {
            $gateway->siteRows[] = [
                'external_id' => $i, 'name' => "site{$i}.example.test", 'path' => "/www/wwwroot/site{$i}",
                'status' => '1', 'metadata_complete' => true,
            ];
            $gateway->domains[$i] = ['status' => 'supported', 'domains' => ["site{$i}.example.test"]];
        }
        $report = (new BaotaCompatibilityDiagnostic($gateway))->run('https://panel.example.test:8888', 'never-output-this-key');
        TestHarness::assertSame('needs_attention', $report['classification']);
        TestHarness::assertSame(20, $report['sites']['detail_queries_used']);
        TestHarness::assertFalse($report['sites']['details_complete']);
        TestHarness::assertFalse($report['write_operations_performed']);
        TestHarness::assertNotContains('never-output-this-key', json_encode($report, JSON_THROW_ON_ERROR));
    },

    'baota client uses only documented read only actions and paginates sites' => static function (): void {
        putenv('LANDINGOPS_BAOTA_ALLOWED_BASE_URLS=https://panel.example.test:8888');
        $firstPage = [];
        for ($i = 1; $i <= 100; $i++) {
            $firstPage[] = ['id' => $i, 'name' => "site{$i}.example.test", 'path' => "/www/wwwroot/site{$i}", 'status' => '1'];
        }
        $transport = new FakeBaotaTransport(
            baotaResponse(200, ['data' => $firstPage]),
            baotaResponse(200, ['data' => [['id' => '101', 'name' => 'last.example.test']]]),
            baotaResponse(200, ['domains' => [['name' => 'WWW.Example.Test.']]]),
            baotaResponse(200, [['version' => '81', 'name' => 'PHP-8.1', 'status' => true]]),
            baotaResponse(200, ['phpversion' => '81']),
        );
        $client = new BaotaReadOnlyClient($transport, static fn (): int => 1700000000, static function (int $microseconds): void {});
        $sites = $client->sites('https://panel.example.test:8888', 'synthetic-secret');
        TestHarness::assertSame(101, count($sites));
        TestHarness::assertFalse($sites[100]['metadata_complete']);
        TestHarness::assertSame('supported', $client->siteDomains('https://panel.example.test:8888', 'synthetic-secret', 1)['status']);
        TestHarness::assertSame('supported', $client->phpVersions('https://panel.example.test:8888', 'synthetic-secret')['status']);
        TestHarness::assertSame('81', $client->sitePhpVersion('https://panel.example.test:8888', 'synthetic-secret', 'www.example.test')['php_version']);
        TestHarness::assertSame(['/data', '/data', '/site', '/site', '/site'], array_column($transport->requests, 'path'));
        TestHarness::assertSame('getData', $transport->requests[0]['fields']['action']);
        TestHarness::assertSame('sites', $transport->requests[0]['fields']['table']);
        TestHarness::assertSame(md5('1700000000' . md5('synthetic-secret')), $transport->requests[0]['fields']['request_token']);
        TestHarness::assertFalse(in_array('synthetic-secret', $transport->requests[0]['fields'], true));
        putenv('LANDINGOPS_BAOTA_ALLOWED_BASE_URLS');
    },

    'baota client maps auth rate limit timeout invalid response and blocks untrusted origin' => static function (): void {
        putenv('LANDINGOPS_BAOTA_ALLOWED_BASE_URLS=https://panel.example.test:8888');
        $cases = [
            'baota_auth_failed' => [baotaResponse(401, [])],
            'baota_rate_limited' => [baotaResponse(429, []), baotaResponse(429, [])],
            'baota_timeout' => [new BaotaTransportException('timeout'), new BaotaTransportException('timeout')],
            'baota_response_invalid' => [new BaotaHttpResponse(200, '{broken')],
        ];
        foreach ($cases as $code => $responses) {
            $client = new BaotaReadOnlyClient(new FakeBaotaTransport(...$responses), static fn (): int => 1700000000, static function (int $microseconds): void {});
            $error = TestHarness::assertThrows(static fn () => $client->systemSummary('https://panel.example.test:8888', 'synthetic-secret'), ApiException::class);
            TestHarness::assertSame($code, $error->errorCode);
        }
        $transport = new FakeBaotaTransport();
        $client = new BaotaReadOnlyClient($transport);
        $error = TestHarness::assertThrows(static fn () => $client->systemSummary('https://127.0.0.1:8888', 'synthetic-secret'), ApiException::class);
        TestHarness::assertSame('baota_target_not_allowed', $error->errorCode);
        TestHarness::assertSame([], $transport->requests);
        putenv('LANDINGOPS_BAOTA_ALLOWED_BASE_URLS');
    },

    'baota pagination failure returns an explicitly incomplete inventory' => static function (): void {
        putenv('LANDINGOPS_BAOTA_ALLOWED_BASE_URLS=https://panel.example.test:8888');
        $firstPage = [];
        for ($i = 1; $i <= 100; $i++) {
            $firstPage[] = ['id' => $i, 'name' => "site{$i}.example.test", 'path' => "/www/wwwroot/site{$i}"];
        }
        $client = new BaotaReadOnlyClient(new FakeBaotaTransport(
            baotaResponse(200, ['data' => $firstPage]),
            new BaotaTransportException('timeout'),
            new BaotaTransportException('timeout'),
        ), static fn (): int => 1700000000, static function (int $microseconds): void {});
        $inventory = $client->siteInventory('https://panel.example.test:8888', 'synthetic-secret');
        TestHarness::assertFalse($inventory['complete']);
        TestHarness::assertSame('pagination_failed', $inventory['reason']);
        TestHarness::assertSame(100, count($inventory['sites']));
        putenv('LANDINGOPS_BAOTA_ALLOWED_BASE_URLS');
    },

    'baota singleton config encrypts rotates and preserves explicit disabled state' => static function (): void {
        [$repository, $p, $configs] = baotaFixture();
        $created = $configs->save($p[SUPER_ID], ['base_url' => 'https://panel.example.test:8888', 'api_key' => 'first-secret-key'], 'req-create');
        TestHarness::assertSame('enabled', $created['admin_state']);
        TestHarness::assertSame('unverified', $created['verification_status']);
        TestHarness::assertFalse(str_contains((string) $repository->baotaConfig['api_key_ciphertext'], 'first-secret-key'));
        TestHarness::assertFalse(array_key_exists('api_key_ciphertext', $created));
        TestHarness::assertFalse(array_key_exists('base_url', $created));
        TestHarness::assertSame('https://p***.example.test:8888', $created['base_url_hint']);
        TestHarness::assertSame('first-secret-key', $configs->decryptApiKey($repository->baotaConfig ?? []));
        $cipher = new AeadSecretCipher(['v1' => str_repeat('b', 32)], 'v1');
        TestHarness::assertThrows(static fn () => $cipher->decrypt(
            (string) $repository->baotaConfig['api_key_ciphertext'],
            (string) $repository->baotaConfig['secret_key_version'],
            'landingops:cloudflare-token:' . $repository->baotaConfig['id'],
        ), LandingOpsException::class);
        foreach ([ADMIN_A_ID, STAFF_A_ID] as $actorId) {
            TestHarness::assertSame(403, TestHarness::assertThrows(static fn () => $configs->get($p[$actorId]), ApiException::class)->status);
        }
        $disabled = $configs->save($p[SUPER_ID], ['admin_state' => 'disabled', 'expected_version' => 1], 'req-disable');
        $rotated = $configs->save($p[SUPER_ID], ['api_key' => 'second-secret-key', 'expected_version' => $disabled['version']], 'req-rotate');
        TestHarness::assertSame('disabled', $rotated['admin_state']);
        TestHarness::assertSame('unverified', $rotated['verification_status']);
        TestHarness::assertSame('second-secret-key', $configs->decryptApiKey($repository->baotaConfig ?? []));
        $duplicate = TestHarness::assertThrows(
            static fn () => $repository->insertBaotaConfig($repository->baotaConfig ?? []),
            \RuntimeException::class,
        );
        TestHarness::assertContains('singleton', $duplicate->getMessage());
    },

    'baota discovery keeps external sites unowned and precheck is conservative' => static function (): void {
        [$repository, $p, $configs] = baotaFixture();
        $configs->save($p[SUPER_ID], ['base_url' => 'https://panel.example.test:8888', 'api_key' => 'synthetic-secret'], 'req-create');
        $gateway = new FakeBaotaGateway();
        $gateway->siteRows = [[
            'external_id' => 7, 'name' => 'legacy.example.test', 'path' => '/www/wwwroot/legacy',
            'status' => '1', 'metadata_complete' => true,
        ]];
        $gateway->domains[7] = ['status' => 'supported', 'domains' => ['legacy.example.test', 'alias.example.test']];
        $service = new BaotaReadOnlyService($repository, $configs, $gateway);
        $service->verify($p[SUPER_ID], 1, 'req-verify');
        $discovery = $service->sites($p[SUPER_ID]);
        TestHarness::assertSame('external', $discovery['sites'][0]['ownership']['classification']);
        TestHarness::assertTrue($discovery['inventory']['complete']);
        putenv('LANDINGOPS_BAOTA_SITE_ROOT=/www/wwwroot/landingops');
        $conflict = $service->precheck($p[SUPER_ID], 'alias.example.test');
        TestHarness::assertSame('conflict', $conflict['classification']);
        $gateway->directory = ['status' => 'unknown', 'path' => null, 'entry_count' => null, 'has_unknown_entries' => null, 'is_symlink' => true];
        $symlink = $service->precheck($p[SUPER_ID], 'new.example.test');
        TestHarness::assertSame('conflict', $symlink['classification']);
        TestHarness::assertSame('disabled', $service->isolation($p[SUPER_ID])['php_deployment']['status']);
        putenv('LANDINGOPS_BAOTA_SITE_ROOT');
    },

    'baota discovery enforces detail budget and marks large or incomplete results' => static function (): void {
        [$repository, $p, $configs] = baotaFixture();
        $configs->save($p[SUPER_ID], ['base_url' => 'https://panel.example.test:8888', 'api_key' => 'synthetic-secret'], 'req-create');
        $gateway = new FakeBaotaGateway();
        for ($i = 1; $i <= 45; $i++) {
            $gateway->siteRows[] = [
                'external_id' => $i, 'name' => "site{$i}.example.test", 'path' => "/www/wwwroot/site{$i}",
                'status' => '1', 'metadata_complete' => true,
            ];
            $gateway->domains[$i] = ['status' => 'supported', 'domains' => ["site{$i}.example.test"]];
        }
        $service = new BaotaReadOnlyService($repository, $configs, $gateway);
        $service->verify($p[SUPER_ID], 1, 'req-verify');
        $discovery = $service->sites($p[SUPER_ID]);
        TestHarness::assertSame(20, $gateway->domainCalls);
        TestHarness::assertFalse($discovery['inventory']['complete']);
        TestHarness::assertSame('detail_budget_exhausted', $discovery['inventory']['reason']);
        TestHarness::assertSame('budget_exhausted', $discovery['sites'][44]['domain_capability']);
        putenv('LANDINGOPS_BAOTA_SITE_ROOT=/www/wwwroot/landingops');
        TestHarness::assertSame('unsupported', $service->precheck($p[SUPER_ID], 'unused.example.test')['classification']);
        putenv('LANDINGOPS_BAOTA_SITE_ROOT');
    },

    'baota domain detail failure cannot produce a clear precheck' => static function (): void {
        [$repository, $p, $configs] = baotaFixture();
        $configs->save($p[SUPER_ID], ['base_url' => 'https://panel.example.test:8888', 'api_key' => 'synthetic-secret'], 'req-create');
        $gateway = new FakeBaotaGateway();
        $gateway->siteRows = [[
            'external_id' => 7, 'name' => 'legacy.example.test', 'path' => '/www/wwwroot/legacy',
            'status' => '1', 'metadata_complete' => true,
        ]];
        $gateway->failingDomainIds = [7];
        $service = new BaotaReadOnlyService($repository, $configs, $gateway);
        $service->verify($p[SUPER_ID], 1, 'req-verify');
        putenv('LANDINGOPS_BAOTA_SITE_ROOT=/www/wwwroot/landingops');
        $result = $service->precheck($p[SUPER_ID], 'unused.example.test');
        TestHarness::assertSame('unsupported', $result['classification']);
        TestHarness::assertFalse($result['inventory']['complete']);
        TestHarness::assertSame('detail_query_failed', $result['inventory']['reason']);
        putenv('LANDINGOPS_BAOTA_SITE_ROOT');
    },

    'baota ownership requires external id exact bindings path and reliable marker' => static function (): void {
        $evaluator = new BaotaSiteOwnershipEvaluator();
        $instance = [
            'id' => INSTANCE_A_ID, 'baota_site_external_id' => '7', 'normalized_fqdn' => 'owned.example.test',
            'baota_site_root_path' => '/www/wwwroot/owned', 'ownership_marker_hash' => str_repeat('a', 64),
        ];
        $site = [
            'external_id' => 7, 'domains' => ['owned.example.test'], 'path' => '/www/wwwroot/owned',
            'ownership_marker_hash' => str_repeat('a', 64),
        ];
        TestHarness::assertSame('managed', $evaluator->evaluate($site, [$instance])['classification']);
        $site['domains'][] = 'alias.example.test';
        TestHarness::assertSame('unverified', $evaluator->evaluate($site, [$instance])['classification']);
        unset($site['ownership_marker_hash']);
        TestHarness::assertSame('unverified', $evaluator->evaluate($site, [$instance])['classification']);
    },

    'baota API routes enforce session csrf super admin and secret redaction' => static function (): void {
        [$repository, , $configs] = baotaFixture();
        $authorization = new AuthorizationService();
        $gateway = new FakeBaotaGateway();
        $readOnly = new BaotaReadOnlyService($repository, $configs, $gateway, $authorization);
        $kernel = new ControlPlaneKernel(
            new SessionService($repository),
            new AccountService($repository, $authorization),
            new ResourceAccessService($repository, $authorization),
            new OwnershipTransferService($repository, $authorization),
            baotaConfigs: $configs,
            baotaReadOnly: $readOnly,
        );
        $login = $kernel->handle(new Request('POST', '/api/v1/login', [], [], [
            'email' => 'root@example.test', 'password' => 'Correct horse battery staple 123!',
        ]));
        preg_match('/landingops_session=([^;]+)/', $login->headers['Set-Cookie'], $matches);
        $cookie = rawurldecode($matches[1]);
        $csrf = $login->body['data']['csrf_token'];
        $created = $kernel->handle(new Request(
            'PUT', '/api/v1/settings/baota', ['X-CSRF-Token' => $csrf],
            [ControlPlaneKernel::COOKIE => $cookie],
            ['base_url' => 'https://panel.example.test:8888', 'api_key' => 'api-route-secret'],
        ));
        TestHarness::assertSame(200, $created->status);
        $encoded = json_encode($created->body, JSON_THROW_ON_ERROR);
        TestHarness::assertNotContains('api-route-secret', $encoded);
        TestHarness::assertNotContains('api_key_ciphertext', $encoded);
        $verified = $kernel->handle(new Request(
            'POST', '/api/v1/settings/baota/verifications', ['X-CSRF-Token' => $csrf],
            [ControlPlaneKernel::COOKIE => $cookie], ['expected_version' => 1],
        ));
        TestHarness::assertSame(200, $verified->status);

        $staffLogin = $kernel->handle(new Request('POST', '/api/v1/login', [], [], [
            'email' => 'staff-a@example.test', 'password' => 'Correct horse battery staple 123!',
        ]));
        preg_match('/landingops_session=([^;]+)/', $staffLogin->headers['Set-Cookie'], $staffMatches);
        $forbidden = $kernel->handle(new Request(
            'GET', '/api/v1/settings/baota', [],
            [ControlPlaneKernel::COOKIE => rawurldecode($staffMatches[1])],
        ));
        TestHarness::assertSame(403, $forbidden->status);
    },
];
