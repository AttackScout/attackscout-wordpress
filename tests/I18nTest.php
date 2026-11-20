<?php

declare(strict_types=1);

namespace AttackScout\WordPress\Tests;

use AttackScout\WordPress\Tools;
use ZipArchive;

/**
 * Translations (French, Spanish) and package. The generated files must be
 * the output of bin/i18n.php: run `composer i18n` after a text change.
 */
final class I18nTest extends TestCase
{
    private const PLUGIN = __DIR__ . '/../attackscout';
    private const LOCALES = ['fr_FR', 'es_ES', 'de_DE', 'it_IT', 'pt_PT'];

    public function testPotIsUpToDate(): void
    {
        $pot = Tools\make_pot(Tools\extract_strings(self::dir()), Tools\plugin_version(self::dir()));
        self::assertSame($pot, file_get_contents(self::dir() . '/languages/attackscout.pot'), 'run composer i18n');
    }

    public function testEveryStringIsTranslated(): void
    {
        $strings = Tools\extract_strings(self::dir());
        self::assertGreaterThan(60, count($strings));
        foreach (self::LOCALES as $locale) {
            $po = Tools\parse_po((string) file_get_contents(self::dir() . "/languages/attackscout-$locale.po"));
            self::assertStringContainsString('Language: ' . $locale, $po[''][0]);
            foreach ($strings as $key => $entry) {
                self::assertArrayHasKey($key, $po, "$locale: {$entry['msgid']}");
                foreach ($po[$key] as $form) {
                    self::assertNotSame('', $form, "$locale: {$entry['msgid']}");
                }
                self::assertSame(self::placeholders($entry['msgid']), self::placeholders($po[$key][0]), "$locale: {$entry['msgid']}");
            }
            self::assertCount(count($strings) + 1, $po, "$locale: obsolete entries");
        }
    }

    public function testCompiledFilesAreUpToDate(): void
    {
        foreach (self::LOCALES as $locale) {
            $base = self::dir() . "/languages/attackscout-$locale";
            $po = Tools\parse_po((string) file_get_contents("$base.po"));
            self::assertSame(Tools\make_mo($po), file_get_contents("$base.mo"), 'run composer i18n');
            self::assertSame(Tools\make_l10n_php($po), file_get_contents("$base.l10n.php"), 'run composer i18n');
            $data = require "$base.l10n.php";
            self::assertSame('attackscout', $data['domain']);
            self::assertSame(substr($locale, 0, 2) === 'fr' ? 'nplurals=2; plural=(n > 1);' : 'nplurals=2; plural=(n != 1);', $data['plural-forms']);
            self::assertArrayHasKey("%d point to fix\0%d points to fix", $data['messages']);
        }
    }

    public function testServiceOpeningMessageInEveryLanguage(): void
    {
        $source = 'The service will be available on Nov 30, 2026.';
        foreach (self::LOCALES as $locale) {
            $po = Tools\parse_po((string) file_get_contents(self::dir() . "/languages/attackscout-$locale.po"));
            self::assertMatchesRegularExpression('/30[.\/]11[.\/]2026/', $po[$source][0], $locale);
        }
    }

    public function testMoFileReadsBack(): void
    {
        $mo = Tools\make_mo(['' => ["Language: fr_FR\n"], 'b' => ['B'], "one\0many" => ['un', 'plusieurs'], 'empty' => ['']]);
        $h = unpack('Vmagic/Vrev/Vn/Vo/Vt', $mo);
        self::assertSame(0x950412de, $h['magic']);
        self::assertSame(3, $h['n']);
        $read = static function (int $table, int $i) use ($mo): string {
            $e = unpack('Vlen/Voff', substr($mo, $table + 8 * $i, 8));
            return substr($mo, $e['off'], $e['len']);
        };
        self::assertSame(['', 'b', "one\0many"], [$read($h['o'], 0), $read($h['o'], 1), $read($h['o'], 2)]);
        self::assertSame("un\0plusieurs", $read($h['t'], 2));
    }

    public function testPoParser(): void
    {
        $po = Tools\parse_po("# comment\nmsgid \"\"\nmsgstr \"\"\n\"Language: es_ES\\n\"\n\n#: x.php:1\nmsgid \"Say \\\"hi\\\"\"\nmsgstr \"Di \"\n\"\\\"hola\\\"\"\n");
        self::assertSame(['' => ["Language: es_ES\n"], 'Say "hi"' => ['Di "hola"']], $po);
    }

    public function testExtractorSkipsOtherDomainsAndNonLiterals(): void
    {
        $dir = sys_get_temp_dir() . '/as-i18n-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/attackscout.php", "<?php\n/**\n * Plugin Name: X\n */\n__( 'A', 'attackscout' ); __( 'B', 'other' ); __( \$v, 'attackscout' ); \$o->__ ; _n( 'c', 'cs', 2, 'attackscout' ); __( 'A', 'attackscout' );\n");
        $strings = Tools\extract_strings($dir);
        self::assertSame(['X', 'A', "c\0cs"], array_keys($strings));
        self::assertSame(['attackscout.php:5', 'attackscout.php:5'], $strings['A']['refs']);
        unlink("$dir/attackscout.php");
        rmdir($dir);
    }

    public function testPackage(): void
    {
        $out = sys_get_temp_dir() . '/as-pkg-' . bin2hex(random_bytes(4));
        $zip_path = Tools\build_package(self::dir(), $out);
        self::assertSame("$out/attackscout.zip", $zip_path);
        $first = hash_file('sha256', $zip_path);
        touch(self::dir() . '/readme.txt');
        self::assertSame($zip_path, Tools\build_package(self::dir(), $out), 'a second build replaces the archive');
        self::assertSame($first, hash_file('sha256', $zip_path), 'the archive is reproducible');
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zip_path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        foreach (['attackscout/attackscout.php', 'attackscout/readme.txt', 'attackscout/uninstall.php', 'attackscout/includes/class-attackscout-admin.php', 'attackscout/assets/admin.css', 'attackscout/languages/attackscout-fr_FR.mo', 'attackscout/languages/attackscout-es_ES.l10n.php'] as $f) {
            self::assertContains($f, $names);
        }
        foreach ($names as $n) {
            self::assertStringStartsWith('attackscout/', $n);
            self::assertStringNotContainsString('test', $n);
        }
        unlink($zip_path);
        rmdir($out);
    }

    public function testReadmeHeadersMatchThePlugin(): void
    {
        $readme = (string) file_get_contents(self::dir() . '/readme.txt');
        $main = (string) file_get_contents(self::dir() . '/attackscout.php');
        self::assertStringContainsString('Stable tag: ' . Tools\plugin_version(self::dir()), $readme);
        foreach (['Requires at least', 'Requires PHP'] as $h) {
            preg_match('/' . $h . ':\s*(\S+)/', $main, $m);
            self::assertStringContainsString("$h: {$m[1]}", $readme);
        }
        self::assertStringContainsString('== External services ==', $readme);
        self::assertStringContainsString("define( 'ATTACKSCOUT_VERSION', '" . Tools\plugin_version(self::dir()) . "' );", $main);
    }

    private static function dir(): string
    {
        return (string) realpath(self::PLUGIN);
    }

    /** @return list<string> */
    private static function placeholders(string $s): array
    {
        preg_match_all('/%(\d\$)?[sd]/', $s, $m);
        $list = $m[0];
        sort($list);
        return $list;
    }
}
