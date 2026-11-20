<?php

// Regenerates languages/attackscout.pot from the code, then the .mo and
// .l10n.php files from each .po file: php bin/i18n.php

declare(strict_types=1);

require __DIR__ . '/tools.php';

$plugin = dirname(__DIR__) . '/attackscout';
foreach (AttackScout\WordPress\Tools\build_translations($plugin, AttackScout\WordPress\Tools\plugin_version($plugin)) as $file) {
    echo substr($file, strlen(dirname(__DIR__)) + 1), "\n";
}
