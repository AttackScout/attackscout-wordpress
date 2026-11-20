<?php

/**
 * Build tools of the plugin (not shipped): string extraction (.pot),
 * compilation of the translations (.mo and .l10n.php), package (.zip).
 * Used by bin/i18n.php, bin/package.php and the tests.
 */

declare(strict_types=1);

namespace AttackScout\WordPress\Tools;

const DOMAIN = 'attackscout';
// Function => [index of the text domain argument, has a plural].
const GETTEXT = [
    '__' => [1, false],
    '_e' => [1, false],
    'esc_html__' => [1, false],
    'esc_html_e' => [1, false],
    'esc_attr__' => [1, false],
    'esc_attr_e' => [1, false],
    '_n' => [3, true],
];

/**
 * Translatable strings of the plugin, in order of first appearance.
 *
 * @return array<string, array{msgid: string, plural: string, refs: list<string>, comment: string}>
 */
function extract_strings(string $plugin_dir): array
{
    $entries = [];
    $add = static function (string $msgid, string $plural, string $ref, string $comment) use (&$entries): void {
        $k = $plural === '' ? $msgid : $msgid . "\0" . $plural;
        if (!isset($entries[$k])) {
            $entries[$k] = ['msgid' => $msgid, 'plural' => $plural, 'refs' => [], 'comment' => ''];
        }
        $entries[$k]['refs'][] = $ref;
        if ($comment !== '' && $entries[$k]['comment'] === '') {
            $entries[$k]['comment'] = $comment;
        }
    };

    $main = $plugin_dir . '/attackscout.php';
    $header = (string) file_get_contents($main);
    foreach (['Plugin Name', 'Description'] as $field) {
        if (preg_match('/^\s*\*\s*' . $field . ':\s*(.+)$/m', $header, $m) === 1) {
            $add(trim($m[1]), '', 'attackscout.php', $field . ' of the plugin');
        }
    }

    foreach (php_files($plugin_dir) as $file) {
        $rel = substr($file, strlen($plugin_dir) + 1);
        $tokens = token_get_all((string) file_get_contents($file));
        $comment = '';
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && $t[0] === T_COMMENT && str_contains_compat($t[1], 'translators:')) {
                $comment = trim((string) preg_replace('#^/\*+|\*+/$|^//#', '', $t[1]));
                continue;
            }
            if (!is_array($t) || $t[0] !== T_STRING || !isset(GETTEXT[$t[1]])) {
                continue;
            }
            [$domain_at, $has_plural] = GETTEXT[$t[1]];
            $args = call_args($tokens, $i + 1);
            if (($args[$domain_at] ?? null) !== DOMAIN || !is_string($args[0]) || ($has_plural && !is_string($args[1]))) {
                continue; // a method of the same name, another domain, or a non-literal text
            }
            $add($args[0], $has_plural ? (string) $args[1] : '', $rel . ':' . $t[2], $comment);
            $comment = '';
        }
    }
    return $entries;
}

/** PHP 7.4 has no str_contains(). */
function str_contains_compat(string $haystack, string $needle): bool
{
    return strpos($haystack, $needle) !== false;
}

/**
 * Arguments of a call starting at the "(" token: the text of a single string
 * literal, or null for anything else.
 *
 * @param array<int, mixed> $tokens
 * @return list<string|null>
 */
function call_args(array $tokens, int $start): array
{
    $i = $start;
    while (isset($tokens[$i]) && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
        $i++;
    }
    if (($tokens[$i] ?? null) !== '(') {
        return [];
    }
    $args = [];
    $parts = [];
    $depth = 0;
    for ($i++; isset($tokens[$i]); $i++) {
        $t = $tokens[$i];
        if (in_array($t, ['(', '['], true)) {
            $depth++;
        } elseif (in_array($t, [')', ']'], true) && $depth-- === 0 || $t === ',' && $depth === 0) {
            $args[] = count($parts) === 1 && is_array($parts[0]) && $parts[0][0] === T_CONSTANT_ENCAPSED_STRING ? unquote($parts[0][1]) : null;
            $parts = [];
            if ($t !== ',') {
                break;
            }
            continue;
        }
        if (!is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT], true)) {
            $parts[] = $t;
        }
    }
    return $args;
}

function unquote(string $literal): string
{
    $body = substr($literal, 1, -1);
    if ($literal[0] === "'") {
        return str_replace(["\\'", '\\\\'], ["'", '\\'], $body);
    }
    return stripcslashes($body);
}

/** @return list<string> */
function php_files(string $dir): array
{
    $files = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var \SplFileInfo $f */
        $path = str_replace('\\', '/', $f->getPathname());
        if ($f->getExtension() === 'php' && strpos($path, '/languages/') === false) {
            $files[] = $path;
        }
    }
    sort($files);
    return $files;
}

function po_string(string $s): string
{
    return '"' . addcslashes($s, "\\\"\n\t") . '"';
}

/**
 * The .pot file.
 *
 * @param array<string, array{msgid: string, plural: string, refs: list<string>, comment: string}> $entries
 */
function make_pot(array $entries, string $version): string
{
    $out = "# Copyright (C) AttackScout\n# This file is distributed under the GPL-2.0-or-later.\n"
        . "msgid \"\"\nmsgstr \"\"\n"
        . po_string('Project-Id-Version: AttackScout ' . $version . "\n") . "\n"
        . po_string("MIME-Version: 1.0\n") . "\n"
        . po_string("Content-Type: text/plain; charset=UTF-8\n") . "\n"
        . po_string("Content-Transfer-Encoding: 8bit\n") . "\n"
        . po_string('X-Domain: ' . DOMAIN . "\n") . "\n";
    foreach ($entries as $e) {
        $out .= "\n";
        if ($e['comment'] !== '') {
            $out .= '#. ' . $e['comment'] . "\n";
        }
        $out .= '#: ' . implode(' ', $e['refs']) . "\n";
        $out .= 'msgid ' . po_string($e['msgid']) . "\n";
        if ($e['plural'] !== '') {
            $out .= 'msgid_plural ' . po_string($e['plural']) . "\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n";
        } else {
            $out .= "msgstr \"\"\n";
        }
    }
    return $out;
}

/**
 * Entries of a .po file: key "msgid" or "msgid\0plural", value list of forms.
 *
 * @return array<string, list<string>>
 */
function parse_po(string $text): array
{
    $entries = [];
    $cur = [];
    $field = '';
    $flush = static function () use (&$cur, &$entries): void {
        if (isset($cur['msgid'])) {
            $key = $cur['msgid'] . (isset($cur['msgid_plural']) ? "\0" . $cur['msgid_plural'] : '');
            $forms = [];
            foreach ($cur as $k => $v) {
                if (strpos($k, 'msgstr') === 0) {
                    $forms[] = $v;
                }
            }
            $entries[$key] = $forms;
        }
        $cur = [];
    };
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (preg_match('/^(msgid|msgid_plural|msgstr(?:\[\d\])?)\s+(".*")$/', $line, $m) === 1) {
            if ($m[1] === 'msgid') {
                $flush();
            }
            $field = $m[1];
            $cur[$field] = unquote($m[2]);
        } elseif ($line[0] === '"' && $field !== '') {
            $cur[$field] .= unquote($line);
        }
    }
    $flush();
    return $entries;
}

/**
 * Compiled .mo file (sorted, without hash table).
 *
 * @param array<string, list<string>> $entries
 */
function make_mo(array $entries): string
{
    $entries = array_filter($entries, static fn (array $forms): bool => implode('', $forms) !== '');
    ksort($entries, SORT_STRING);
    $n = count($entries);
    $origs = '';
    $trans = '';
    $otable = [];
    $ttable = [];
    foreach ($entries as $k => $forms) {
        $otable[] = [strlen((string) $k), strlen($origs)];
        $origs .= $k . "\0";
        $t = implode("\0", $forms);
        $ttable[] = [strlen($t), strlen($trans)];
        $trans .= $t . "\0";
    }
    $ostart = 28 + 16 * $n;
    $tstart = $ostart + strlen($origs);
    $out = pack('V7', 0x950412de, 0, $n, 28, 28 + 8 * $n, 0, $ostart);
    foreach ($otable as [$len, $off]) {
        $out .= pack('V2', $len, $ostart + $off);
    }
    foreach ($ttable as [$len, $off]) {
        $out .= pack('V2', $len, $tstart + $off);
    }
    return $out . $origs . $trans;
}

/**
 * Translation file in the PHP format of WordPress 6.5 (.l10n.php).
 *
 * @param array<string, list<string>> $entries
 */
function make_l10n_php(array $entries): string
{
    $headers = [];
    foreach (explode("\n", $entries[''][0] ?? '') as $line) {
        if (strpos($line, ':') !== false) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }
    $messages = [];
    foreach ($entries as $k => $forms) {
        if ($k !== '' && implode('', $forms) !== '') {
            $messages[$k] = implode("\0", $forms);
        }
    }
    ksort($messages, SORT_STRING);
    $data = [
        'domain' => DOMAIN,
        'plural-forms' => $headers['plural-forms'] ?? 'nplurals=2; plural=(n != 1);',
        'language' => $headers['language'] ?? '',
        'project-id-version' => $headers['project-id-version'] ?? '',
        'messages' => $messages,
    ];
    return "<?php\n// Generated by bin/i18n.php from the .po file. Do not edit.\nreturn " . var_export($data, true) . ";\n";
}

/**
 * Writes the .pot, then the .mo and .l10n.php of each .po file.
 *
 * @return list<string> written files
 */
function build_translations(string $plugin_dir, string $version): array
{
    $lang_dir = $plugin_dir . '/languages';
    $written = [$lang_dir . '/' . DOMAIN . '.pot'];
    file_put_contents($written[0], make_pot(extract_strings($plugin_dir), $version));
    foreach (glob($lang_dir . '/*.po') ?: [] as $po) {
        $entries = parse_po((string) file_get_contents($po));
        $base = substr($po, 0, -3);
        file_put_contents($base . '.mo', make_mo($entries));
        file_put_contents($base . '.l10n.php', make_l10n_php($entries));
        $written[] = $base . '.mo';
        $written[] = $base . '.l10n.php';
    }
    return $written;
}

/** Version of the plugin, read from its header. */
function plugin_version(string $plugin_dir): string
{
    preg_match('/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents($plugin_dir . '/attackscout.php'), $m);
    return $m[1] ?? '0.0.0';
}

/**
 * Package for WordPress.org: attackscout.zip with the attackscout/ folder
 * (the version is in the plugin header, the folder is named after the slug).
 *
 * Reproducible: files in name order, same date for every entry
 * (SOURCE_DATE_EPOCH, or 2026-01-01 00:00 UTC), same rights (0644), so that
 * two builds of the same sources give the same bytes.
 */
function build_package(string $plugin_dir, string $out_dir): string
{
    if (!is_dir($out_dir)) {
        mkdir($out_dir, 0777, true);
    }
    $zip_path = $out_dir . '/attackscout.zip';
    if (file_exists($zip_path)) {
        unlink($zip_path);
    }
    $epoch = getenv('SOURCE_DATE_EPOCH');
    $mtime = is_string($epoch) && ctype_digit($epoch) ? (int) $epoch : 1767225600;
    $tz = date_default_timezone_get();
    date_default_timezone_set('UTC');
    $files = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($plugin_dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var \SplFileInfo $f */
        $files[] = str_replace('\\', '/', $f->getPathname());
    }
    sort($files, SORT_STRING);
    // Dated copies: ZipArchive takes the date of the file on disk.
    $tmp = sys_get_temp_dir() . '/as-zip-' . bin2hex(random_bytes(6));
    mkdir($tmp);
    $zip = new \ZipArchive();
    $zip->open($zip_path, \ZipArchive::CREATE);
    $copies = [];
    foreach ($files as $i => $file) {
        $entry = 'attackscout/' . substr($file, strlen($plugin_dir) + 1);
        $copy = $tmp . '/' . $i;
        copy($file, $copy);
        touch($copy, $mtime);
        $copies[] = $copy;
        $zip->addFile($copy, $entry);
        $zip->setCompressionName($entry, \ZipArchive::CM_DEFLATE);
        $zip->setExternalAttributesName($entry, \ZipArchive::OPSYS_UNIX, 0100644 << 16);
    }
    $zip->close();
    foreach ($copies as $copy) {
        unlink($copy);
    }
    rmdir($tmp);
    date_default_timezone_set($tz);
    return $zip_path;
}
