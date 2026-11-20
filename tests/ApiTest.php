<?php

declare(strict_types=1);

namespace AttackScout\WordPress\Tests;

use AttackScout_Api;
use WP_Error;
use WP_Fake;

final class ApiTest extends TestCase
{
    public function testSendsTheDomainAloneWithTheKeyInTheHeader(): void
    {
        WP_Fake::$http = fn (): array => self::response(201, (string) json_encode(self::result()));
        $res = (new AttackScout_Api('https://api.attackscout.test/'))->run_public_scan(self::KEY, 'www.boulangerie-martin.fr');

        self::assertSame(['ok' => true, 'result' => self::result()], $res);
        self::assertCount(1, WP_Fake::$requests);
        $req = WP_Fake::$requests[0];
        self::assertSame('https://api.attackscout.test/api/v1/public/scans', $req['url']);
        self::assertSame('{"domain":"www.boulangerie-martin.fr"}', $req['args']['body']);
        self::assertSame('Bearer ' . self::KEY, $req['args']['headers']['Authorization']);
        self::assertSame('application/json', $req['args']['headers']['Content-Type']);
        self::assertSame(0, $req['args']['redirection']);
        self::assertSame(75, $req['args']['timeout']);
        self::assertSame('AttackScout-WordPress/0.1.0', $req['args']['user-agent']);
    }

    public function testNetworkError(): void
    {
        WP_Fake::$http = fn (): WP_Error => new WP_Error('http_request_failed', 'cURL error 28');
        self::assertSame(['ok' => false, 'code' => 'network', 'retry_after' => 0], $this->call());
    }

    public function testSuccessThatIsNotAResult(): void
    {
        WP_Fake::$http = fn (): array => self::response(200, '{"id": 3, "findings": []}');
        self::assertSame(['ok' => false, 'code' => 'unexpected', 'retry_after' => 0], $this->call());
        WP_Fake::$http = fn (): array => self::response(200, '<html>');
        self::assertSame(['ok' => false, 'code' => 'unexpected', 'retry_after' => 0], $this->call());
    }

    public function testStableErrorCodeAndRetryAfter(): void
    {
        WP_Fake::$http = fn (): array => self::response(429, '{"error":{"code":"scan_quota_exceeded","message":"…"}}', ['retry-after' => '3600']);
        self::assertSame(['ok' => false, 'code' => 'scan_quota_exceeded', 'retry_after' => 3600], $this->call());
    }

    public function testErrorWithoutBodyAndUnreadableRetryAfter(): void
    {
        WP_Fake::$http = fn (): array => self::response(502, 'Bad gateway', ['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT']);
        self::assertSame(['ok' => false, 'code' => 'unexpected', 'retry_after' => 0], $this->call());
        WP_Fake::$http = fn (): array => self::response(403, '{"error":{"code":42}}');
        self::assertSame(['ok' => false, 'code' => 'unexpected', 'retry_after' => 0], $this->call());
    }

    /** @return array<string, mixed> */
    private function call(): array
    {
        return (new AttackScout_Api('https://api.attackscout.test'))->run_public_scan(self::KEY, 'boulangerie-martin.fr');
    }
}
