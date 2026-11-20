<?php

declare(strict_types=1);

// Tests offline: fake WordPress (tests/Fakes/wordpress.php), fake AttackScout
// API (WP_Fake::$http). The plugin is loaded by the first test (TestCase::setUp),
// so that its file-level code is measured too.

require dirname(__DIR__) . '/vendor/autoload.php';

define('ABSPATH', '/var/www/html/');

require __DIR__ . '/Fakes/wordpress.php';
require dirname(__DIR__) . '/bin/tools.php';

