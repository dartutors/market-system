<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\MarketData\MarketStreamService;

/**
 * Phase 6 entry point — single-poll mode by default, same lock-file idiom
 * as cron/market_scan.php, suitable for a Windows Task Scheduler entry
 * every N minutes. Pass --watch to instead loop forever at
 * MARKET_STREAM_INTERVAL_SECONDS (default 30s) — only use --watch if you
 * intend to run this as a long-lived process (e.g. via NSSM as a Windows
 * service), never via Task Scheduler (which would stack up overlapping
 * long-lived instances).
 *
 *   php cron/market_stream.php            # one poll cycle, then exit
 *   php cron/market_stream.php --watch    # loop forever until killed
 */

$cacheDir = storage_path('cache');
$logsDir  = storage_path('logs');
foreach ([$cacheDir, $logsDir] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

$lockFile = $cacheDir . DIRECTORY_SEPARATOR . 'market_stream.lock';
$logFile  = $logsDir  . DIRECTORY_SEPARATOR . 'market_stream.log';
$watch    = in_array('--watch', $argv ?? [], true);

$fp = fopen($lockFile, 'c+');
if ($fp === false) {
    file_put_contents($logFile, date('[Y-m-d H:i:s]') . ' [ERROR] Unable to open lock file.' . PHP_EOL, FILE_APPEND | LOCK_EX);
    exit(1);
}
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fclose($fp);
    file_put_contents($logFile, date('[Y-m-d H:i:s]') . ' [SKIP]  Another market-stream run is already active.' . PHP_EOL, FILE_APPEND | LOCK_EX);
    exit(0);
}

$service = new MarketStreamService();

$logTick = static function (array $notifications) use ($logFile): void {
    foreach ($notifications as $n) {
        $status = $n['error'] !== null ? 'ERROR' : ($n['changed'] ? 'CHANGED' : 'unchanged');
        $detail = $n['error'] ?? ($n['quote']['mid'] ?? '');
        file_put_contents(
            $logFile,
            date('[Y-m-d H:i:s]') . " [{$status}] {$n['label']}: {$detail}" . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
};

if ($watch) {
    // Never returns — releasing the lock here would be misleading (the
    // process holds it for its entire lifetime); flock is released
    // automatically when this process exits.
    $interval = max(1, (int) env('MARKET_STREAM_INTERVAL_SECONDS', 30));
    $service->watch($interval, $logTick);
}

$result = $service->poll();
$logTick($result);

flock($fp, LOCK_UN);
fclose($fp);

echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
