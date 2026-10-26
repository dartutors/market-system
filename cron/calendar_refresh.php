<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\Services\CalendarRefreshService;

// ── Ensure storage directories exist ─────────────────────────────────────────
$cacheDir = storage_path('cache');
$logsDir  = storage_path('logs');

foreach ([$cacheDir, $logsDir] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

$lockFile = $cacheDir . DIRECTORY_SEPARATOR . 'calendar_refresh.lock';
$logFile  = $logsDir  . DIRECTORY_SEPARATOR . 'calendar_refresh.log';

// ── Acquire exclusive non-blocking lock ──────────────────────────────────────
$fp = fopen($lockFile, 'c+');

if ($fp === false) {
    $msg = date('[Y-m-d H:i:s]') . ' [ERROR] Unable to open lock file: ' . $lockFile . PHP_EOL;
    file_put_contents($logFile, $msg, FILE_APPEND | LOCK_EX);
    echo json_encode(['status' => 'error', 'message' => 'Lock file unavailable.']) . PHP_EOL;
    exit(1);
}

if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fclose($fp);
    $msg = date('[Y-m-d H:i:s]') . ' [SKIP]  Another calendar refresh is already running.' . PHP_EOL;
    file_put_contents($logFile, $msg, FILE_APPEND | LOCK_EX);
    echo json_encode(['status' => 'skipped', 'message' => 'Refresh already running.']) . PHP_EOL;
    exit(0);
}

// ── Run refresh ───────────────────────────────────────────────────────────────
try {
    $result = (new CalendarRefreshService())->refresh(30);
} catch (\Throwable $e) {
    $result = [
        'status'  => 'error',
        'message' => $e->getMessage(),
    ];
    error_log('[calendar_refresh] Fatal: ' . $e->getMessage());
}

// ── Log result ───────────────────────────────────────────────────────────────
$status   = strtoupper((string) ($result['status']          ?? 'OK'));
$inserted = (int) ($result['inserted']        ?? 0);
$skipped  = (int) ($result['skipped']         ?? 0);
$deleted  = (int) ($result['deleted_expired'] ?? 0);
$errors   = (array) ($result['errors']        ?? []);

$logLine = date('[Y-m-d H:i:s]')
    . " [{$status}] inserted={$inserted} skipped={$skipped} deleted_expired={$deleted}"
    . (!empty($errors) ? ' errors=' . count($errors) : '')
    . PHP_EOL;

file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

// ── Release lock and output ───────────────────────────────────────────────────
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
