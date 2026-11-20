<?php

declare(strict_types=1);

namespace AttackScout\WordPress\Tests;

use AttackScout_Admin;
use AttackScout_Api;
use AttackScout_Report;
use WP_Error;
use WP_Fake;
use WPDieException;
use WPRedirectException;

final class AdminTest extends TestCase
{
    private const FREE_KEY_LINK = 'href="https://attackscout.com/espace/cles?utm_source=wordpress&#038;utm_medium=plugin&#038;utm_campaign=wordpress-cle" target="_blank" rel="noopener noreferrer">Create a free key</a>';

    private AttackScout_Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new AttackScout_Admin(new AttackScout_Api('https://api.attackscout.test'), 'https://www.boulangerie-martin.fr/wp-content/plugins/attackscout/', 'attackscout/attackscout.php');
    }

    public function testRegistersItsHooks(): void
    {
        $this->admin->register();
        self::assertSame(
            ['admin_menu', 'admin_init', 'wp_dashboard_setup', 'admin_enqueue_scripts', 'admin_post_attackscout_run_audit', 'admin_post_attackscout_forget_key', 'plugin_action_links_attackscout/attackscout.php'],
            array_keys(WP_Fake::$hooks)
        );
        self::assertArrayNotHasKey('admin_post_nopriv_attackscout_run_audit', WP_Fake::$hooks);
    }

    public function testSettingsPageAndOption(): void
    {
        $this->admin->add_menu();
        self::assertSame('settings_page_attackscout', $this->admin->page_hook());
        [$call] = WP_Fake::calls('add_options_page');
        self::assertSame(['add_options_page', 'AttackScout', 'AttackScout', 'manage_options', 'attackscout'], array_slice($call, 0, 5));

        WP_Fake::$options_page = false;
        $this->admin->add_menu();
        self::assertSame('', $this->admin->page_hook());

        $this->admin->register_settings();
        [$setting] = WP_Fake::calls('register_setting');
        self::assertSame('attackscout', $setting[1]);
        self::assertSame('attackscout_api_key', $setting[2]);
        self::assertFalse($setting[3]['show_in_rest']);
        self::assertSame([$this->admin, 'sanitize_key'], $setting[3]['sanitize_callback']);
    }

    public function testSanitizeKey(): void
    {
        self::assertSame('', $this->admin->sanitize_key(''));
        self::assertSame(self::KEY, $this->admin->sanitize_key(' ' . self::KEY . "\n"));
        WP_Fake::$options['attackscout_api_key'] = self::KEY;
        self::assertSame(self::KEY, $this->admin->sanitize_key(''), 'an empty field keeps the saved key');
        self::assertSame(self::KEY, $this->admin->sanitize_key(self::KEY));
        self::assertSame(self::KEY, $this->admin->sanitize_key(['x']));
        self::assertSame([], WP_Fake::calls('add_settings_error'));
        self::assertSame(self::KEY, $this->admin->sanitize_key('sk_live_123'));
        [$error] = WP_Fake::calls('add_settings_error');
        self::assertSame('attackscout_invalid_key', $error[2]);
        $other = str_replace('as_test_', 'as_live_', self::KEY);
        self::assertSame($other, $this->admin->sanitize_key($other));
    }

    public function testDashboardWidgetForAdministratorsOnly(): void
    {
        $this->admin->add_dashboard_widget();
        [$widget] = WP_Fake::calls('wp_add_dashboard_widget');
        self::assertSame('attackscout_dashboard', $widget[1]);
        WP_Fake::reset();
        WP_Fake::$caps = ['read'];
        $this->admin->add_dashboard_widget();
        self::assertSame([], WP_Fake::calls('wp_add_dashboard_widget'));
    }

    public function testStyleSheetOnItsPagesOnly(): void
    {
        $this->admin->enqueue_assets('settings_page_attackscout');
        self::assertSame([], WP_Fake::calls('wp_enqueue_style'), 'page hook unknown before the menu');
        $this->admin->add_menu();
        $this->admin->enqueue_assets('plugins.php');
        $this->admin->enqueue_assets('index.php');
        $this->admin->enqueue_assets('settings_page_attackscout');
        $calls = WP_Fake::calls('wp_enqueue_style');
        self::assertCount(2, $calls);
        self::assertSame(['wp_enqueue_style', 'attackscout-admin', 'https://www.boulangerie-martin.fr/wp-content/plugins/attackscout/assets/admin.css', [], '0.1.0'], $calls[0]);
    }

    public function testSettingsLink(): void
    {
        self::assertSame(
            ['<a href="https://www.boulangerie-martin.fr/wp-admin/options-general.php?page=attackscout">Settings</a>', 'deactivate'],
            $this->admin->action_links(['deactivate'])
        );
    }

    public function testPageWithoutKeyNorAudit(): void
    {
        $html = self::render([$this->admin, 'render_page']);
        self::assertStringContainsString('No audit yet.', $html);
        self::assertStringContainsString('name="attackscout_api_key" value=""', $html);
        self::assertStringContainsString('name="option_page" value="attackscout"', $html);
        self::assertStringNotContainsString('attackscout_run_audit', $html);
        self::assertStringNotContainsString('attackscout_forget_key', $html);
        self::assertStringContainsString(self::FREE_KEY_LINK, $html);
        self::assertStringContainsString('PHP ' . PHP_VERSION, $html);
        self::assertStringContainsString('WordPress 6.8.3', $html);
        self::assertSame([], WP_Fake::$requests);
    }

    public function testFreeKeyLinkOnlyWithoutKeyAndOnlyOnClick(): void
    {
        self::assertSame('https://attackscout.com/espace/cles?utm_source=wordpress&utm_medium=plugin&utm_campaign=wordpress-cle', AttackScout_Admin::free_key_url());
        $widget = self::render([$this->admin, 'render_widget']);
        self::assertStringContainsString(self::FREE_KEY_LINK, $widget);
        self::assertStringContainsString('class="button button-primary" href="https://attackscout.com/espace/cles', $widget);
        self::assertSame([], WP_Fake::$requests, 'the link makes no request');

        WP_Fake::$options['attackscout_api_key'] = self::KEY;
        self::assertStringNotContainsString('Create a free key', self::render([$this->admin, 'render_widget']));
        self::assertStringNotContainsString('Create a free key', self::render([$this->admin, 'render_page']));
    }

    public function testPageWithKeyAndAuditMakesNoRequest(): void
    {
        WP_Fake::$options['attackscout_api_key'] = self::KEY;
        WP_Fake::$options['attackscout_last_scan'] = AttackScout_Report::from_result(self::result());
        $html = self::render([$this->admin, 'render_page']);

        self::assertSame([], WP_Fake::$requests, 'displaying a page never calls AttackScout');
        self::assertStringNotContainsString(self::KEY, $html, 'the key is never shown in full');
        self::assertStringContainsString('<code>as_test_01234567</code>', $html);
        self::assertStringContainsString('<span class="attackscout-grade attackscout-grade-C">C</span>', $html);
        self::assertStringContainsString('Audit of 13/11/2026', $html);
        self::assertStringContainsString('3 points to fix', $html);
        self::assertStringContainsString('High</span> Email: 2', $html);
        self::assertStringContainsString('To fix this month</span> 01/12/2026 · TLS certificate expiry', $html);
        self::assertStringContainsString('In order</span> 01/03/2027 · Domain name expiry', $html);
        self::assertStringContainsString('To plan</span> 31/12/2027 · End of support: PHP 8.1', $html);
        self::assertStringContainsString('href="https://attackscout.com/r/tok_abc123?utm_source=wordpress&#038;utm_medium=plugin&#038;utm_campaign=dashboard#corriger-titre" target="_blank" rel="noopener noreferrer">How to fix these points</a>', $html);
        self::assertStringContainsString('href="https://attackscout.com/r/tok_abc123?utm_source=wordpress&#038;utm_medium=plugin&#038;utm_campaign=dashboard#surveiller" target="_blank" rel="noopener noreferrer">Monitor this site and get deadline alerts</a>', $html);
        self::assertStringContainsString('<input type="hidden" name="action" value="attackscout_run_audit" />', $html);
        self::assertStringContainsString('value="nonce-attackscout_run_audit"', $html);
        self::assertStringContainsString('value="nonce-attackscout_forget_key"', $html);
        self::assertStringContainsString('the domain name <code>www.boulangerie-martin.fr</code>, and nothing else', $html);
    }

    public function testPartialGradeOnePointAndOtherDeadline(): void
    {
        WP_Fake::$options['attackscout_api_key'] = self::KEY;
        $result = self::result();
        $result['grade'] = '';
        $result['findings'] = [['id' => 'A1', 'family' => 'tech', 'status' => 'fail', 'severity' => 'info']];
        $result['deadlines'] = [['date' => '2027-01-01T00:00:00Z', 'control' => 'A38', 'urgency' => 'now']];
        WP_Fake::$options['attackscout_last_scan'] = AttackScout_Report::from_result($result);
        $html = self::render([$this->admin, 'render_widget']);
        self::assertStringContainsString('attackscout-grade-partial">?</span>', $html);
        self::assertStringContainsString('Partial grade', $html);
        self::assertStringContainsString('1 point to fix', $html);
        self::assertStringContainsString('Info</span> Software versions: 1', $html);
        self::assertStringContainsString('Broken now</span> 01/01/2027 · Deadline (check A38)', $html);
    }

    public function testCleanResultWithoutLink(): void
    {
        $result = self::result();
        $result['id'] = '';
        $result['findings'] = [];
        $result['deadlines'] = [];
        WP_Fake::$options['attackscout_last_scan'] = AttackScout_Report::from_result($result);
        $html = self::render([$this->admin, 'render_widget']);
        self::assertStringContainsString('No point to fix in the public audit.', $html);
        self::assertStringNotContainsString('Main points', $html);
        self::assertStringNotContainsString('Deadlines', $html);
        self::assertStringNotContainsString('/r/', $html);
        self::assertStringContainsString('Add your AttackScout API key', $html);
    }

    public function testCleanResultOffersMonitoringOnly(): void
    {
        $result = self::result();
        $result['findings'] = [];
        WP_Fake::$options['attackscout_last_scan'] = AttackScout_Report::from_result($result);
        $html = self::render([$this->admin, 'render_widget']);
        self::assertStringNotContainsString('How to fix', $html);
        self::assertStringContainsString('#surveiller" target="_blank" rel="noopener noreferrer">Monitor this site', $html);
    }

    public function testAtMostThreeMainPoints(): void
    {
        $result = self::result();
        $result['findings'] = [];
        foreach (['email', 'web', 'dns', 'tls', 'http'] as $family) {
            $result['findings'][] = ['id' => 'A1', 'family' => $family, 'status' => 'fail', 'severity' => 'high'];
        }
        WP_Fake::$options['attackscout_last_scan'] = AttackScout_Report::from_result($result);
        $html = self::render([$this->admin, 'render_widget']);
        self::assertStringContainsString('5 points to fix', $html);
        self::assertSame(3, substr_count($html, 'attackscout-sev-'));
    }

    public function testBrokenStoredSummaryIsIgnored(): void
    {
        WP_Fake::$options['attackscout_last_scan'] = ['grade' => 'A'];
        self::assertStringContainsString('No audit yet.', self::render([$this->admin, 'render_widget']));
    }

    public function testPageNeedsTheCapability(): void
    {
        WP_Fake::$caps = [];
        self::assertSame('', self::render([$this->admin, 'render_page']));
    }

    public function testEnvironmentWarnings(): void
    {
        WP_Fake::$site_transients['php_check_' . md5(PHP_VERSION)] = ['is_secure' => false];
        WP_Fake::$site_transients['update_core'] = (object) ['updates' => [(object) ['response' => 'upgrade', 'current' => '6.9.1']]];
        $html = self::render([$this->admin, 'render_widget']);
        self::assertStringContainsString('no longer receives security updates', $html);
        self::assertStringContainsString('version 6.9.1 available', $html);

        WP_Fake::$site_transients['php_check_' . md5(PHP_VERSION)] = ['is_secure' => true];
        WP_Fake::$site_transients['update_core'] = (object) ['updates' => [(object) ['response' => 'latest', 'current' => '6.8.3']]];
        $html = self::render([$this->admin, 'render_widget']);
        self::assertStringNotContainsString('security updates', $html);
        self::assertStringNotContainsString('available', $html);

        WP_Fake::$site_transients['update_core'] = (object) ['updates' => 'none'];
        self::assertStringNotContainsString('available', self::render([$this->admin, 'render_widget']));
    }

    public function testRunFormHiddenForALocalSite(): void
    {
        WP_Fake::$options['attackscout_api_key'] = self::KEY;
        WP_Fake::$home = 'http://localhost:8080';
        $html = self::render([$this->admin, 'render_widget']);
        self::assertStringContainsString('no public domain name', $html);
        self::assertStringNotContainsString('attackscout_run_audit', $html);
    }

    public function testRunAudit(): void
    {
        self::assertSame(['type' => 'error', 'code' => 'no_key', 'retry' => 0], $this->admin->run_audit());

        WP_Fake::$options['attackscout_api_key'] = self::KEY;
        WP_Fake::$home = 'https://wp.test';
        self::assertSame(['type' => 'error', 'code' => 'local_site', 'retry' => 0], $this->admin->run_audit());
        self::assertSame([], WP_Fake::$requests);

        WP_Fake::$home = 'https://www.boulangerie-martin.fr';
        WP_Fake::$http = fn (): array => self::response(429, '{"error":{"code":"rate_limited","message":""}}', ['retry-after' => '120']);
        self::assertSame(['type' => 'error', 'code' => 'rate_limited', 'retry' => 120], $this->admin->run_audit());
        self::assertArrayNotHasKey('attackscout_last_scan', WP_Fake::$options);

        WP_Fake::$http = fn (): array => self::response(201, (string) json_encode(self::result()));
        self::assertSame(['type' => 'success', 'code' => 'scan_done', 'retry' => 0], $this->admin->run_audit());
        self::assertSame(AttackScout_Report::from_result(self::result()), WP_Fake::$options['attackscout_last_scan']);
        self::assertFalse(WP_Fake::$autoload['attackscout_last_scan']);
        self::assertSame('{"domain":"www.boulangerie-martin.fr"}', WP_Fake::$requests[1]['args']['body']);
    }

    public function testRunAuditActionChecksCapabilityAndNonce(): void
    {
        WP_Fake::$caps = [];
        try {
            $this->admin->handle_run_audit();
            self::fail('expected wp_die');
        } catch (WPDieException $e) {
            self::assertSame(403, $e->getCode());
        }
        self::assertSame([], WP_Fake::$checked_nonces);

        WP_Fake::$caps = ['manage_options'];
        WP_Fake::$nonce_ok = false;
        $this->expectException(WPDieException::class);
        try {
            $this->admin->handle_run_audit();
        } finally {
            self::assertSame(['attackscout_run_audit'], WP_Fake::$checked_nonces);
            self::assertSame([], WP_Fake::$requests);
        }
    }

    public function testRunAuditActionRedirectsWithANoticeShownOnce(): void
    {
        WP_Fake::$options['attackscout_api_key'] = self::KEY;
        WP_Fake::$http = fn (): array => self::response(201, (string) json_encode(self::result()));
        self::assertSame('https://www.boulangerie-martin.fr/wp-admin/index.php', $this->redirect([$this->admin, 'handle_run_audit']));
        self::assertSame([['set_transient', 'attackscout_notice_7', 300]], WP_Fake::calls('set_transient'));

        $html = self::render([$this->admin, 'render_widget']);
        self::assertStringContainsString('<div class="notice notice-success inline"><p>Audit done.</p></div>', $html);
        self::assertStringNotContainsString('notice-success', self::render([$this->admin, 'render_widget']));
    }

    public function testForgetKeyAction(): void
    {
        WP_Fake::$options['attackscout_api_key'] = self::KEY;
        WP_Fake::$referer = false;
        self::assertSame('https://www.boulangerie-martin.fr/wp-admin/options-general.php?page=attackscout', $this->redirect([$this->admin, 'handle_forget_key']));
        self::assertArrayNotHasKey('attackscout_api_key', WP_Fake::$options);
        self::assertSame(['attackscout_forget_key'], WP_Fake::$checked_nonces);
        self::assertStringContainsString('API key removed.', self::render([$this->admin, 'render_page']));
    }

    public function testErrorNotice(): void
    {
        WP_Fake::$transients['attackscout_notice_7'] = ['type' => 'error', 'code' => 'domain_excluded', 'retry' => 0];
        self::assertStringContainsString('<div class="notice notice-error inline"><p>The owner of this domain asked not to be audited.</p></div>', self::render([$this->admin, 'render_widget']));
        WP_Fake::$transients['attackscout_notice_7'] = ['type' => 'error'];
        self::assertStringNotContainsString('notice', self::render([$this->admin, 'render_widget']));
    }

    /** @return iterable<array{string, int, string}> */
    public static function notices(): iterable
    {
        yield ['rate_limited', 120, 'Too many requests. Try again in 2 min.'];
        yield ['rate_limited', 0, 'Too many requests. Try again later.'];
        yield ['scan_quota_exceeded', 30, 'Daily audit quota reached. Try again in 1 min.'];
        yield ['scan_quota_exceeded', 0, 'Daily audit quota reached. Try again tomorrow.'];
        yield ['api_key_revoked', 0, 'Your API key has been revoked. Create a new one in your AttackScout account.'];
        yield ['store_error', 0, 'Something went wrong on our side. Try again later.'];
    }

    public function testServiceNotOpenYet(): void
    {
        $before = 1795996799;
        $after = 1795996800;
        foreach (['network', 'unexpected', 'not_found'] as $code) {
            $notice = ['type' => 'error', 'code' => $code, 'retry' => 0];
            self::assertSame('The service will be available on Nov 30, 2026.', AttackScout_Admin::notice_message($notice, $before), $code);
            self::assertStringNotContainsString('will be available', AttackScout_Admin::notice_message($notice, $after), $code);
        }
        self::assertSame('AttackScout cannot be reached from this site. Try again later.', AttackScout_Admin::notice_message(['type' => 'error', 'code' => 'network', 'retry' => 0], $after));
        self::assertSame('Your API key has been revoked. Create a new one in your AttackScout account.', AttackScout_Admin::notice_message(['type' => 'error', 'code' => 'api_key_revoked', 'retry' => 0], $before));
        self::assertSame(gmmktime(0, 0, 0, 11, 30, 2026), AttackScout_Admin::SERVICE_OPENS);
    }

    public function testUnreachableApiShowsTheOpeningDateBeforeLaunch(): void
    {
        WP_Fake::$options['attackscout_api_key'] = self::KEY;
        WP_Fake::$http = static function () {
            return new WP_Error('http_request_failed', 'could not resolve host');
        };
        $notice = $this->admin->run_audit();
        self::assertSame('network', $notice['code']);
        self::assertSame(AttackScout_Admin::notice_message($notice), time() < AttackScout_Admin::SERVICE_OPENS
            ? 'The service will be available on Nov 30, 2026.'
            : 'AttackScout cannot be reached from this site. Try again later.');
    }

    /** @dataProvider notices */
    public function testNoticeMessages(string $code, int $retry, string $want): void
    {
        self::assertSame($want, AttackScout_Admin::notice_message(['type' => 'error', 'code' => $code, 'retry' => $retry]));
    }

    public function testLabels(): void
    {
        self::assertSame('Email', AttackScout_Admin::family_label('email'));
        self::assertSame('mystery', AttackScout_Admin::family_label('mystery'));
        self::assertSame('Critical', AttackScout_Admin::severity_label('critical'));
        self::assertSame('Info', AttackScout_Admin::severity_label('info'));
        self::assertSame('Good to know', AttackScout_Admin::urgency_label('info'));
        self::assertSame('Certificates move to 100 days', AttackScout_Admin::deadline_label(['kind' => 'cert100', 'name' => '', 'control' => 'A71']));
    }

    public function testTranslatedOutputIsEscaped(): void
    {
        WP_Fake::$translations['No audit yet.'] = '<img src=x onerror=alert(1)>';
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', self::render([$this->admin, 'render_widget']));
    }

    private function redirect(callable $action): string
    {
        try {
            $action();
        } catch (WPRedirectException $e) {
            return $e->getMessage();
        }
        self::fail('expected a redirect');
    }
}
