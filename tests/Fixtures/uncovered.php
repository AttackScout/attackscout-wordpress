<?php

declare(strict_types=1);

// Lists the uncovered statements of a Clover report: php uncovered.php build/clover.xml
$xml = simplexml_load_file($argv[1] ?? 'build/clover.xml');
if ($xml === false) {
    fwrite(STDERR, "unreadable clover file\n");
    exit(1);
}
foreach ($xml->xpath('//file') ?: [] as $file) {
    $missed = [];
    foreach ($file->line as $line) {
        if ((string) $line['type'] === 'stmt' && (int) $line['count'] === 0) {
            $missed[] = (int) $line['num'];
        }
    }
    if ($missed !== []) {
        echo $file['name'], ': ', implode(', ', $missed), "\n";
    }
}
