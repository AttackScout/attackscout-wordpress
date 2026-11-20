<?php

declare(strict_types=1);

namespace AttackScout\WordPress\Tests;

use AttackScout_Report;

final class ReportTest extends TestCase
{
    /** @return iterable<array{string, string}> */
    public static function domains(): iterable
    {
        yield ['https://www.boulangerie-martin.fr', 'www.boulangerie-martin.fr'];
        yield ['https://Boutique.Example.COM./blog', 'boutique.example.com'];
        yield ['http://localhost:8080', ''];
        yield ['http://127.0.0.1/wp', ''];
        yield ['http://[::1]/', ''];
        yield ['https://intranet', ''];
        yield ['https://mon-site.local', ''];
        yield ['https://wp.test', ''];
        yield ['https://wp.example', ''];
        yield ['https://box.home.arpa', ''];
        yield ['pas une adresse', ''];
    }

    /** @dataProvider domains */
    public function testPublicDomain(string $url, string $want): void
    {
        self::assertSame($want, AttackScout_Report::public_domain($url));
    }

    public function testSummaryOfAResult(): void
    {
        self::assertSame([
            'id' => 'tok_abc123',
            'grade' => 'C',
            'created_at' => 1794564000,
            'failed' => 3,
            'verified' => 40,
            'total' => 42,
            'families' => [
                ['family' => 'email', 'count' => 2, 'worst' => 'high'],
                ['family' => 'http', 'count' => 1, 'worst' => 'low'],
            ],
            'deadlines' => [
                ['date' => 1796083200, 'control' => 'A68', 'urgency' => 'this_month', 'kind' => 'cert', 'name' => ''],
                ['date' => 1803859200, 'control' => 'A18', 'urgency' => 'ok', 'kind' => 'domain', 'name' => ''],
                ['date' => 1830211200, 'control' => 'A102', 'urgency' => 'plan', 'kind' => 'eol', 'name' => 'PHP 8.1'],
            ],
        ], AttackScout_Report::from_result(self::result()));
    }

    public function testKeepsOnlyKnownValues(): void
    {
        $deadline = ['date' => '2027-01-01T00:00:00Z', 'control' => '<b>A1</b>', 'urgency' => 'urgent!', 'details' => ['label' => '<script>x</script>WordPress']];
        $summary = AttackScout_Report::from_result([
            'id' => 12,
            'grade' => 'Z',
            'createdAt' => 'hier',
            'coverage' => ['verified' => '40'],
            'findings' => [
                'not an object',
                ['status' => 'fail', 'family' => 'unknown', 'severity' => 'extreme'],
                ['status' => 'fail', 'family' => 'dns', 'severity' => 'medium'],
                ['status' => 'fail', 'family' => 'tls', 'severity' => 'medium'],
                ['status' => 'fail', 'family' => 'tls', 'severity' => 'medium'],
            ],
            'deadlines' => array_merge([['date' => 'jamais'], ['control' => 'A18']], array_fill(0, 8, $deadline)),
        ]);
        self::assertSame('', $summary['id']);
        self::assertSame('', $summary['grade']);
        self::assertSame(0, $summary['created_at']);
        self::assertSame(0, $summary['verified']);
        self::assertSame(0, $summary['total']);
        self::assertSame(4, $summary['failed']);
        self::assertSame([
            ['family' => 'tls', 'count' => 2, 'worst' => 'medium'],
            ['family' => 'dns', 'count' => 1, 'worst' => 'medium'],
            ['family' => 'web', 'count' => 1, 'worst' => 'info'],
        ], $summary['families']);
        self::assertCount(AttackScout_Report::MAX_DEADLINES, $summary['deadlines']);
        self::assertSame(['date' => 1798761600, 'control' => '', 'urgency' => 'info', 'kind' => 'eol', 'name' => 'WordPress'], $summary['deadlines'][0]);
    }

    public function testEmptyResult(): void
    {
        $summary = AttackScout_Report::from_result(['findings' => 'none', 'deadlines' => null]);
        self::assertSame([], $summary['families']);
        self::assertSame([], $summary['deadlines']);
        self::assertSame(0, $summary['failed']);
    }

    public function testDeadlineKinds(): void
    {
        self::assertSame(['kind' => 'domain', 'name' => ''], AttackScout_Report::deadline_kind('A18', []));
        self::assertSame(['kind' => 'cert', 'name' => ''], AttackScout_Report::deadline_kind('A70', []));
        self::assertSame(['kind' => 'cert100', 'name' => ''], AttackScout_Report::deadline_kind('A71', ['label' => 7]));
        self::assertSame(['kind' => 'other', 'name' => ''], AttackScout_Report::deadline_kind('A38', ['label' => '']));
        self::assertSame(['kind' => 'eol', 'name' => 'nginx'], AttackScout_Report::deadline_kind('A99', ['label' => 'nginx', 'cycle' => 3]));
    }
}
