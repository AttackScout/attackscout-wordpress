<?php

declare(strict_types=1);

namespace AttackScout\WordPress\Tests;

use AttackScout_Plugin;
use WP_Fake;

final class PluginTest extends TestCase
{
    public function testMainFileDefinesTheAddresses(): void
    {
        self::assertSame('0.1.0', ATTACKSCOUT_VERSION);
        self::assertSame('https://attackscout.com', ATTACKSCOUT_API_URL);
        self::assertSame('https://attackscout.com', ATTACKSCOUT_SITE_URL);
    }

    public function testBootRegistersHooksAndTranslations(): void
    {
        $admin = AttackScout_Plugin::boot('/var/www/html/wp-content/plugins/attackscout/attackscout.php');
        self::assertArrayHasKey('admin_menu', WP_Fake::$hooks);
        self::assertArrayHasKey('plugin_action_links_attackscout/attackscout.php', WP_Fake::$hooks);
        self::assertSame([], WP_Fake::$requests, 'loading the plugin calls nothing');

        WP_Fake::run('init');
        self::assertSame([['load_plugin_textdomain', 'attackscout', false, 'attackscout/languages']], WP_Fake::calls('load_plugin_textdomain'));

        WP_Fake::run('admin_menu');
        self::assertSame('settings_page_attackscout', $admin->page_hook());
    }

    public function testUninstallRemovesTheOptions(): void
    {
        WP_Fake::$options['attackscout_api_key'] = self::KEY;
        WP_Fake::$options['attackscout_last_scan'] = ['grade' => 'A'];
        define('WP_UNINSTALL_PLUGIN', 'attackscout/attackscout.php');
        require dirname(__DIR__) . '/attackscout/uninstall.php';
        self::assertSame(['date_format' => 'd/m/Y'], WP_Fake::$options);
    }
}
