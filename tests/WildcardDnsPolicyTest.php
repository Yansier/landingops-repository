<?php

declare(strict_types=1);

namespace LandingOps\Tests;

use LandingOps\ControlPlane\Cloudflare\WildcardDnsPolicyEvaluator;

/** @return array{id:string,type:string,name:string,content:string,proxied:?bool,ttl:?int} */
function wildcardRecord(string $name, string $content = '203.0.113.10', string $type = 'A', ?bool $proxied = false, ?int $ttl = 1): array
{
    return ['id' => hash('sha256', $name . $type . $content), 'type' => $type, 'name' => $name,
        'content' => $content, 'proxied' => $proxied, 'ttl' => $ttl];
}

/** @return array<string,\Closure> */
return [
    'root wildcard covers multi level names under standard nameservers without writes' => static function (): void {
        $now = time();
        $result = (new WildcardDnsPolicyEvaluator())->evaluate(
            'download.pages.example.com', 'example.com', '203.0.113.10', [],
            ['*.pages.example.com' => [], '*.example.com' => [wildcardRecord('*.example.com')]],
            ['pages.example.com' => []], [], 'standard', true, gmdate('c', $now), $now,
        );
        TestHarness::assertSame('wildcard_covered', $result['classification']);
        TestHarness::assertSame('*.example.com', $result['applicable_wildcard']);
        TestHarness::assertSame(['*.pages.example.com', '*.example.com'], $result['wildcard_candidates']);
        TestHarness::assertSame(0, $result['dns_records_to_create']);
        TestHarness::assertSame(0, $result['dns_records_to_update']);
        TestHarness::assertSame(0, $result['dns_records_to_delete']);
        TestHarness::assertFalse($result['write_operations_performed']);
    },

    'more specific wildcard is authoritative and cannot fall back to a broader record' => static function (): void {
        $now = time();
        $evaluator = new WildcardDnsPolicyEvaluator();
        $covered = $evaluator->evaluate(
            'download.pages.example.com', 'example.com', '203.0.113.10', [],
            [
                '*.pages.example.com' => [wildcardRecord('*.pages.example.com')],
                '*.example.com' => [wildcardRecord('*.example.com', '203.0.113.99')],
            ],
            ['pages.example.com' => []], [], 'standard', true, gmdate('c', $now), $now,
        );
        TestHarness::assertSame('wildcard_covered', $covered['classification']);
        TestHarness::assertSame('*.pages.example.com', $covered['applicable_wildcard']);

        $blocked = $evaluator->evaluate(
            'download.pages.example.com', 'example.com', '203.0.113.10', [],
            [
                '*.pages.example.com' => [wildcardRecord('*.pages.example.com', '203.0.113.99')],
                '*.example.com' => [wildcardRecord('*.example.com')],
            ],
            ['pages.example.com' => []], [], 'standard', true, gmdate('c', $now), $now,
        );
        TestHarness::assertSame('other_server', $blocked['classification']);
    },

    'intermediate records and delegation block a broader wildcard' => static function (): void {
        $now = time();
        $evaluator = new WildcardDnsPolicyEvaluator();
        foreach (['TXT', 'A', 'CNAME'] as $type) {
            $result = $evaluator->evaluate(
                'download.pages.example.com', 'example.com', '203.0.113.10', [],
                ['*.pages.example.com' => [], '*.example.com' => [wildcardRecord('*.example.com')]],
                ['pages.example.com' => [wildcardRecord('pages.example.com', 'synthetic', $type, null, 300)]],
                [], 'standard', true, gmdate('c', $now), $now,
            );
            TestHarness::assertSame('conflict', $result['classification']);
            TestHarness::assertTrue(in_array('specific_ancestor_record_present', array_column($result['issues'], 'code'), true));
        }
        $delegated = $evaluator->evaluate(
            'download.pages.example.com', 'example.com', '203.0.113.10', [],
            ['*.pages.example.com' => [], '*.example.com' => [wildcardRecord('*.example.com')]],
            ['pages.example.com' => [wildcardRecord('pages.example.com', 'ns.example.net', 'NS', null, 3600)]],
            [], 'standard', true, gmdate('c', $now), $now,
        );
        TestHarness::assertSame('conflict', $delegated['classification']);
        TestHarness::assertTrue(in_array('subdomain_delegation_present', array_column($delegated['issues'], 'code'), true));
    },

    'empty non terminal follows standard advanced and unknown nameserver policy conservatively' => static function (): void {
        $now = time();
        $args = [
            'download.pages.example.com', 'example.com', '203.0.113.10', [],
            ['*.pages.example.com' => [], '*.example.com' => [wildcardRecord('*.example.com')]],
            ['pages.example.com' => []], ['pages.example.com'],
        ];
        $evaluator = new WildcardDnsPolicyEvaluator();
        TestHarness::assertSame('wildcard_covered', $evaluator->evaluate(...[...$args, 'standard', true, gmdate('c', $now), $now])['classification']);
        $advanced = $evaluator->evaluate(...[...$args, 'advanced', true, gmdate('c', $now), $now]);
        TestHarness::assertSame('conflict', $advanced['classification']);
        TestHarness::assertTrue(in_array('advanced_nameserver_ent_blocks_wildcard', array_column($advanced['issues'], 'code'), true));
        $unknown = $evaluator->evaluate(...[...$args, 'unknown', true, gmdate('c', $now), $now]);
        TestHarness::assertSame('unsupported', $unknown['classification']);
        TestHarness::assertTrue(in_array('nameserver_mode_unknown_for_ent', array_column($unknown['issues'], 'code'), true));
    },

    'wildcard policy fails closed for exact proxy ttl missing ip incomplete and stale evidence' => static function (): void {
        $now = time();
        $evaluator = new WildcardDnsPolicyEvaluator();
        $wildcard = static fn (array $records): array => ['*.example.com' => $records];
        $exact = [wildcardRecord('offer.example.com', '2001:db8::1', 'AAAA', false, 1)];
        TestHarness::assertSame('conflict', $evaluator->evaluate('offer.example.com', 'example.com', '203.0.113.10', $exact, $wildcard([wildcardRecord('*.example.com')]), [], [], 'standard', true, gmdate('c', $now), $now)['classification']);
        TestHarness::assertSame('needs_attention', $evaluator->evaluate('offer.example.com', 'example.com', '203.0.113.10', [], $wildcard([wildcardRecord('*.example.com', proxied: true)]), [], [], 'standard', true, gmdate('c', $now), $now)['classification']);
        TestHarness::assertSame('needs_attention', $evaluator->evaluate('offer.example.com', 'example.com', '203.0.113.10', [], $wildcard([wildcardRecord('*.example.com', ttl: 300)]), [], [], 'standard', true, gmdate('c', $now), $now)['classification']);
        TestHarness::assertSame('needs_attention', $evaluator->evaluate('offer.example.com', 'example.com', null, [], [], [], [], 'standard', true, gmdate('c', $now), $now)['classification']);
        TestHarness::assertSame('unsupported', $evaluator->evaluate('offer.example.com', 'example.com', '203.0.113.10', [], [], [], [], 'standard', false, gmdate('c', $now - 600), $now)['classification']);
    },
];
