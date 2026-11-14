<?php

declare(strict_types=1);

namespace LandingOps\Tests;

use LandingOps\ControlPlane\Cloudflare\WildcardDnsPolicyEvaluator;

/** @return array<string,\Closure> */
return [
    'wildcard policy reuses only a complete fresh exact applicable record' => static function (): void {
        $now = time();
        $record = [[
            'id' => 'wild-1', 'type' => 'A', 'name' => '*.pages.example.com',
            'content' => '203.0.113.10', 'proxied' => false, 'ttl' => 1,
        ]];
        $result = (new WildcardDnsPolicyEvaluator())->evaluate(
            'offer.pages.example.com', 'example.com', '203.0.113.10', [], $record, [], true, gmdate('c', $now), $now,
        );
        TestHarness::assertSame('wildcard_covered', $result['classification']);
        TestHarness::assertSame('reuse_existing_wildcard', $result['dns_strategy']);
        TestHarness::assertSame('*.pages.example.com', $result['applicable_wildcard']);
        TestHarness::assertSame(0, $result['dns_records_to_create']);
        TestHarness::assertFalse($result['dns_write_required']);
        TestHarness::assertFalse($result['write_operations_performed']);
    },

    'wildcard policy rejects exact overrides delegations and other server targets' => static function (): void {
        $now = time();
        $evaluator = new WildcardDnsPolicyEvaluator();
        $wildcard = [[
            'id' => 'wild-1', 'type' => 'A', 'name' => '*.pages.example.com',
            'content' => '203.0.113.11', 'proxied' => false, 'ttl' => 1,
        ]];
        $other = $evaluator->evaluate('offer.pages.example.com', 'example.com', '203.0.113.10', [], $wildcard, [], true, gmdate('c', $now), $now);
        TestHarness::assertSame('other_server', $other['classification']);
        $exact = [[
            'id' => 'aaaa-1', 'type' => 'AAAA', 'name' => 'offer.pages.example.com',
            'content' => '2001:db8::1', 'proxied' => false, 'ttl' => 1,
        ]];
        $delegation = [[
            'id' => 'ns-1', 'type' => 'NS', 'name' => 'pages.example.com',
            'content' => 'ns.example.net', 'proxied' => null, 'ttl' => 3600,
        ]];
        $blocked = $evaluator->evaluate('offer.pages.example.com', 'example.com', '203.0.113.10', $exact, [], $delegation, true, gmdate('c', $now), $now);
        TestHarness::assertSame('conflict', $blocked['classification']);
        TestHarness::assertTrue(in_array('exact_record_overrides_wildcard', array_column($blocked['issues'], 'code'), true));
        TestHarness::assertTrue(in_array('subdomain_delegation_present', array_column($blocked['issues'], 'code'), true));
    },

    'wildcard policy fails closed for proxy missing ip incomplete and stale evidence' => static function (): void {
        $now = time();
        $proxied = [[
            'id' => 'wild-1', 'type' => 'A', 'name' => '*.example.com',
            'content' => '203.0.113.10', 'proxied' => true, 'ttl' => 1,
        ]];
        $evaluator = new WildcardDnsPolicyEvaluator();
        TestHarness::assertSame('needs_attention', $evaluator->evaluate('offer.example.com', 'example.com', '203.0.113.10', [], $proxied, [], true, gmdate('c', $now), $now)['classification']);
        TestHarness::assertSame('needs_attention', $evaluator->evaluate('offer.example.com', 'example.com', null, [], [], [], true, gmdate('c', $now), $now)['classification']);
        TestHarness::assertSame('unsupported', $evaluator->evaluate('offer.example.com', 'example.com', '203.0.113.10', [], [], [], false, gmdate('c', $now - 600), $now)['classification']);
    },
];
