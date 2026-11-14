<?php

declare(strict_types=1);

namespace LandingOps\Tests;

use LandingOps\ControlPlane\Cloudflare\CloudflareHttpResponse;
use LandingOps\ControlPlane\Cloudflare\CloudflareHttpTransport;
use LandingOps\ControlPlane\Cloudflare\CloudflareReadOnlyClient;
use LandingOps\ControlPlane\Cloudflare\CloudflareTransportException;
use LandingOps\ControlPlane\Exception\ApiException;
use LandingOps\ControlPlane\Service\CloudflareCompatibilityDiagnostic;

final class FakeCloudflareTransport implements CloudflareHttpTransport
{
    /** @var list<CloudflareHttpResponse|CloudflareTransportException> */
    public array $queue;
    /** @var list<string> */
    public array $paths = [];

    public function __construct(CloudflareHttpResponse|CloudflareTransportException ...$queue)
    {
        $this->queue = $queue;
    }

    public function get(string $path, string $token, float $timeoutSeconds): CloudflareHttpResponse
    {
        $this->paths[] = $path;
        $next = array_shift($this->queue);
        if ($next instanceof CloudflareTransportException) {
            throw $next;
        }
        if (!$next instanceof CloudflareHttpResponse) {
            throw new \RuntimeException('Fake response queue exhausted.');
        }
        return $next;
    }
}

function cfResponse(int $status, array $payload = []): CloudflareHttpResponse
{
    return new CloudflareHttpResponse($status, json_encode($payload, JSON_THROW_ON_ERROR));
}

/** @return array<string,\Closure> */
return [
    'cloudflare compatibility diagnostic checks exact wildcard and apex without exposing secrets' => static function (): void {
        $transport = new FakeCloudflareTransport(
            cfResponse(200, ['success' => true, 'result' => ['id' => 'token-id', 'status' => 'active']]),
            cfResponse(200, ['success' => true, 'result' => ['id' => 'zone_1', 'name' => 'example.com', 'status' => 'active', 'account' => ['id' => 'acct_1'], 'name_servers' => ['Ada.NS.Cloudflare.com.', 'Bob.NS.Cloudflare.com']]]),
            cfResponse(200, ['success' => true, 'result' => [], 'result_info' => ['total_pages' => 1]]),
            cfResponse(200, ['success' => true, 'result' => [['id' => 'wild_1', 'type' => 'A', 'name' => '*.example.com', 'content' => '203.0.113.1', 'proxied' => false, 'ttl' => 1]], 'result_info' => ['total_pages' => 1]]),
            cfResponse(200, ['success' => true, 'result' => [['id' => 'ns_1', 'type' => 'NS', 'name' => 'example.com', 'content' => 'ns.example.net', 'proxied' => null, 'ttl' => 3600]], 'result_info' => ['total_pages' => 1]]),
        );
        $report = (new CloudflareCompatibilityDiagnostic(new CloudflareReadOnlyClient($transport, static function (int $microseconds): void {})))
            ->run('never-output-this-token', 'zone_1', 'example.com', 'acct_1', 'www.example.com');
        TestHarness::assertSame('verified_read_only', $report['classification']);
        TestHarness::assertSame(1, $report['dns_read']['wildcard_exact_record_count']);
        TestHarness::assertSame(2, $report['zone']['authoritative_nameserver_count']);
        TestHarness::assertSame(1, $report['dns_read']['explicit_apex_ns_record_count']);
        TestHarness::assertFalse($report['write_operations_performed']);
        TestHarness::assertNotContains('never-output-this-token', json_encode($report, JSON_THROW_ON_ERROR));
        TestHarness::assertSame(5, count($transport->paths));
    },

    'cloudflare read only client verifies token zone and exact dns path' => static function (): void {
        $transport = new FakeCloudflareTransport(
            cfResponse(200, ['success' => true, 'result' => ['id' => 'token-id', 'status' => 'active']]),
            cfResponse(200, ['success' => true, 'result' => ['id' => 'zone_1', 'name' => 'Example.COM.', 'status' => 'active', 'account' => ['id' => 'acct_1'], 'name_servers' => ['ada.ns.cloudflare.com']]]),
            cfResponse(200, ['success' => true, 'result' => [['id' => 'dns_1', 'type' => 'A', 'name' => 'www.example.com', 'content' => '203.0.113.10', 'proxied' => true, 'ttl' => 1]], 'result_info' => ['total_pages' => 1]]),
        );
        $client = new CloudflareReadOnlyClient($transport, static function (int $microseconds): void {});
        TestHarness::assertSame('active', $client->verifyToken('synthetic-token')['status']);
        $zone = $client->zone('synthetic-token', 'zone_1');
        TestHarness::assertSame('example.com', $zone['name']);
        TestHarness::assertSame(1, $zone['authoritative_nameserver_count']);
        TestHarness::assertSame('A', $client->dnsRecords('synthetic-token', 'zone_1', 'www.example.com')[0]['type']);
        TestHarness::assertSame('/user/tokens/verify', $transport->paths[0]);
        TestHarness::assertSame('/zones/zone_1', $transport->paths[1]);
        TestHarness::assertContains('/zones/zone_1/dns_records?name.exact=www.example.com', $transport->paths[2]);
    },

    'cloudflare read only errors map 401 403 429 timeout and inactive token' => static function (): void {
        $cases = [
            'cloudflare_token_invalid' => [cfResponse(401)],
            'cloudflare_permission_denied' => [cfResponse(403)],
            'cloudflare_rate_limited' => [cfResponse(429), cfResponse(429)],
            'cloudflare_timeout' => [new CloudflareTransportException('timeout'), new CloudflareTransportException('timeout')],
            'cloudflare_token_inactive' => [cfResponse(200, ['success' => true, 'result' => ['id' => 'token-id', 'status' => 'disabled']])],
        ];
        foreach ($cases as $code => $responses) {
            $client = new CloudflareReadOnlyClient(new FakeCloudflareTransport(...$responses), static function (int $microseconds): void {});
            $error = TestHarness::assertThrows(static fn () => $client->verifyToken('synthetic-token'), ApiException::class);
            TestHarness::assertSame($code, $error->errorCode);
        }
    },

    'cloudflare identifiers reject url injection before transport' => static function (): void {
        $transport = new FakeCloudflareTransport();
        $client = new CloudflareReadOnlyClient($transport, static function (int $microseconds): void {});
        $error = TestHarness::assertThrows(
            static fn () => $client->zone('synthetic-token', 'https://attacker.invalid'),
            ApiException::class,
        );
        TestHarness::assertSame('cloudflare_identifier_invalid', $error->errorCode);
        TestHarness::assertSame([], $transport->paths);
    },

    'cloudflare zone response id must match the explicitly requested zone' => static function (): void {
        $transport = new FakeCloudflareTransport(cfResponse(200, ['success' => true, 'result' => [
            'id' => 'different_zone', 'name' => 'example.com', 'status' => 'active',
        ]]));
        $client = new CloudflareReadOnlyClient($transport, static function (int $microseconds): void {});
        $error = TestHarness::assertThrows(
            static fn () => $client->zone('synthetic-token', 'zone_1'),
            ApiException::class,
        );
        TestHarness::assertSame('cloudflare_zone_mismatch', $error->errorCode);
    },
];
