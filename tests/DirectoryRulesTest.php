<?php

declare(strict_types=1);

namespace AttackScout\WordPress\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Rules of the WordPress.org plugin directory that can be read in the files
 * (developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/,
 * readme format, checks of the Plugin Check tool). Escaping, nonces and
 * internationalisation are checked by the WordPress Coding Standards
 * (composer lint) and the other tests.
 */
final class DirectoryRulesTest extends BaseTestCase
{
    private const PLUGIN = __DIR__ . '/../attackscout';

    public function testReadmeFollowsTheDirectoryFormat(): void
    {
        $readme = self::read('readme.txt');
        self::assertMatchesRegularExpression('/^=== AttackScout ===\n/', $readme);
        foreach (['Contributors', 'Tags', 'Requires at least', 'Tested up to', 'Requires PHP', 'Stable tag', 'License', 'License URI'] as $header) {
            self::assertMatchesRegularExpression('/^' . $header . ': \S/m', $readme, $header);
        }
        preg_match('/^Tags: (.+)$/m', $readme, $m);
        self::assertLessThanOrEqual(5, count(explode(',', $m[1])), 'five tags at most');
        // Short description: first paragraph after the headers, 150 characters at most.
        preg_match('/License URI: \S+\n\n(.+?)\n\n/s', $readme, $m);
        self::assertNotEmpty($m[1]);
        self::assertLessThanOrEqual(150, mb_strlen($m[1]));
        foreach (['Description', 'External services', 'Installation', 'Frequently Asked Questions', 'Screenshots', 'Changelog', 'Upgrade Notice'] as $section) {
            self::assertStringContainsString("== $section ==", $readme);
        }
        $shots = explode('== Changelog ==', explode('== Screenshots ==', $readme)[1])[0];
        self::assertSame(2, preg_match_all('/^\d\. /m', $shots), 'one caption per screenshot to provide (screenshot-1.png, screenshot-2.png)');
    }

    public function testHeadersAgreeWithTheReadme(): void
    {
        $readme = self::read('readme.txt');
        $main = self::read('attackscout.php');
        preg_match('/^\s*\*\s*License:\s*(.+)$/m', $main, $license);
        self::assertStringContainsString('License: ' . trim($license[1]) . "\n", $readme, 'same licence in both files');
        self::assertSame('GPLv2 or later', trim($license[1]));
        self::assertStringContainsString('License URI: https://www.gnu.org/licenses/gpl-2.0.html', $readme);
        preg_match('/Tested up to:\s*(\d+\.\d+)/', $readme, $tested);
        preg_match('/Requires at least:\s*(\d+\.\d+)/', $readme, $min);
        self::assertTrue(version_compare($tested[1], $min[1], '>='));
        self::assertStringContainsString(' * Text Domain:       attackscout', $main);
        self::assertStringContainsString(' * Domain Path:       /languages', $main);
        self::assertStringNotContainsString('Plugin URI', $main, 'no Plugin URI equal to the Author URI');
        self::assertStringNotContainsString('Update URI', $main, 'updates come from WordPress.org only');
    }

    public function testEveryPhpFileRefusesDirectAccess(): void
    {
        foreach (self::files('php') as $file) {
            $code = (string) file_get_contents($file);
            if (substr($file, -13) === 'uninstall.php') {
                self::assertStringContainsString("if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {\n\texit;", $code);
                continue;
            }
            if (strpos($file, '/languages/') !== false) {
                self::assertStringStartsWith('<?php', $code);
                continue;
            }
            self::assertStringContainsString("if ( ! defined( 'ABSPATH' ) ) {\n\texit;", $code, $file);
        }
    }

    public function testNoTrackingNoRemoteCodeNoNag(): void
    {
        $forbidden = [
            '/\beval\s*\(/' => 'eval',
            '/\bbase64_(en|de)code\s*\(/' => 'obfuscation',
            '/\b(exec|shell_exec|system|passthru|proc_open|popen)\s*\(/' => 'shell',
            '/\bcurl_\w+\s*\(/' => 'HTTP outside the WordPress API',
            '/\bfile_get_contents\s*\(/' => 'remote read',
            '/\bwp_remote_(get|request|head)\s*\(/' => 'other HTTP call',
            '/\bwp_schedule_(single_)?event\s*\(/' => 'background task',
            '/[\'"](admin_notices|all_admin_notices|network_admin_notices)[\'"]/' => 'site-wide notice',
            '/[\'"](wp_head|wp_footer|wp_enqueue_scripts|the_content)[\'"]/' => 'output on the public site',
            '/\bwp_enqueue_script\s*\(/' => 'script',
            '/\bregister_activation_hook\s*\(/' => 'action at activation',
            '/Powered by/i' => 'credit link',
        ];
        $remote = 0;
        foreach (self::files('php') as $file) {
            if (strpos($file, '/languages/') !== false) {
                continue;
            }
            $code = (string) file_get_contents($file);
            foreach ($forbidden as $pattern => $why) {
                self::assertDoesNotMatchRegularExpression($pattern, $code, "$why in $file");
            }
            $remote += preg_match_all('/\bwp_remote_post\s*\(/', $code);
        }
        self::assertSame(1, $remote, 'one outgoing call only, the audit');
    }

    public function testNoResourceLoadedFromOutside(): void
    {
        foreach (array_merge(self::files('php'), self::files('css')) as $file) {
            if (strpos($file, '/languages/') !== false) {
                continue;
            }
            $code = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/<(script|img|iframe|link)\b[^>]*\b(src|href)=["\']?(https?:)?\/\//i', $code, $file);
            self::assertDoesNotMatchRegularExpression('/url\(\s*["\']?(https?:)?\/\//i', $code, $file);
            self::assertDoesNotMatchRegularExpression('/@import/i', $code, $file);
            // Every address written in the code is AttackScout's own, or the licence.
            preg_match_all('#https?://[^\s\'"<>)]*[^\s\'"<>).,]#', $code, $m);
            foreach ($m[0] as $url) {
                self::assertMatchesRegularExpression('#^https://(attackscout\.com|www\.gnu\.org)/#', $url . '/', "$url in $file");
            }
        }
    }

    public function testOnlyShippedFilesInThePluginFolder(): void
    {
        foreach (self::files('') as $file) {
            $name = basename($file);
            self::assertDoesNotMatchRegularExpression('/^\.|\.(sh|phar|exe|zip|log|map|md)$|^(composer|package)\.(json|lock)$/', $name, $file);
        }
    }

    private static function read(string $file): string
    {
        return (string) file_get_contents(self::PLUGIN . '/' . $file);
    }

    /** @return list<string> */
    private static function files(string $extension): array
    {
        $list = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator((string) realpath(self::PLUGIN), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($extension === '' || $f->getExtension() === $extension) {
                $list[] = str_replace('\\', '/', $f->getPathname());
            }
        }
        sort($list);
        return $list;
    }
}
