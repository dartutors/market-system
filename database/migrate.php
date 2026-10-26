<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

$files = glob(__DIR__ . '/migrations/*.php') ?: [];
sort($files, SORT_STRING);

foreach ($files as $file) {
    echo 'Running: ' . basename($file) . PHP_EOL;
    require $file;
    echo 'Done: ' . basename($file) . PHP_EOL;
}

echo 'All migrations completed.' . PHP_EOL;
