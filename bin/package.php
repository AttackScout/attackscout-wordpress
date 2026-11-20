<?php

// Builds build/attackscout.zip, the package to submit to
// WordPress.org (submission done by a person): php bin/package.php

declare(strict_types=1);

require __DIR__ . '/tools.php';

$root = dirname(__DIR__);
echo substr(AttackScout\WordPress\Tools\build_package($root . '/attackscout', $root . '/build'), strlen($root) + 1), "\n";
