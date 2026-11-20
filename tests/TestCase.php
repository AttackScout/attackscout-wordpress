<?php

declare(strict_types=1);

namespace AttackScout\WordPress\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use WP_Fake;

abstract class TestCase extends BaseTestCase
{
    public const KEY = 'as_test_0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        // Loaded as WordPress loads it, once, inside a measured test.
        require_once dirname(__DIR__) . '/attackscout/attackscout.php';
        WP_Fake::reset();
    }

    /** Output of a callback. */
    protected static function render(callable $cb): string
    {
        ob_start();
        try {
            $cb();
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
    }

    /**
     * Response of the WordPress HTTP API.
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected static function response(int $code, string $body, array $headers = []): array
    {
        return ['response' => ['code' => $code, 'message' => ''], 'body' => $body, 'headers' => $headers];
    }

    /** @return array<string, mixed> ScanResult of the API (apps/api/openapi.yaml). */
    protected static function result(): array
    {
        return [
            'id' => 'tok_abc123',
            'domain' => 'boulangerie-martin.fr',
            'siteHost' => 'www.boulangerie-martin.fr',
            'createdAt' => '2026-11-13T10:00:00Z',
            'durationMs' => 12000,
            'grade' => 'C',
            'gradingVersion' => '1',
            'unverifiable' => 0,
            'coverage' => ['verified' => 40, 'total' => 42],
            'fixesToA' => [],
            'findings' => [
                ['id' => 'A1', 'family' => 'email', 'status' => 'fail', 'severity' => 'high', 'code' => 'spf_missing'],
                ['id' => 'A2', 'family' => 'email', 'status' => 'fail', 'severity' => 'medium', 'code' => 'dmarc_none'],
                ['id' => 'A3', 'family' => 'email', 'status' => 'fail', 'severity' => 'critical', 'code' => 'x', 'superseded' => true],
                ['id' => 'A40', 'family' => 'http', 'status' => 'fail', 'severity' => 'low', 'code' => 'hsts_missing'],
                ['id' => 'A18', 'family' => 'domain', 'status' => 'pass', 'code' => 'domain_expiry_ok'],
            ],
            'deadlines' => [
                ['date' => '2026-12-01T00:00:00Z', 'control' => 'A68', 'code' => 'cert_renewal_soon', 'urgency' => 'this_month'],
                ['date' => '2027-03-01T00:00:00Z', 'control' => 'A18', 'code' => 'domain_expiry_ok', 'urgency' => 'ok'],
                ['date' => '2027-12-31T00:00:00Z', 'control' => 'A102', 'code' => 'php_eol', 'urgency' => 'plan', 'details' => ['product' => 'php', 'label' => 'PHP', 'cycle' => '8.1']],
            ],
        ];
    }
}
