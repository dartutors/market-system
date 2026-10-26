<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\Services\AiSummaryService;
use App\Services\MarketScanService;
use App\Services\NotificationService;

// ── Ensure storage directories exist ─────────────────────────────────────────
$cacheDir = storage_path('cache');
$logsDir  = storage_path('logs');
foreach ([$cacheDir, $logsDir] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

$lockFile       = $cacheDir . DIRECTORY_SEPARATOR . 'market_scan.lock';
$logFile        = $logsDir  . DIRECTORY_SEPARATOR . 'cron.log';

// ── Acquire exclusive non-blocking lock ──────────────────────────────────────
$fp = fopen($lockFile, 'c+');
if ($fp === false) {
    file_put_contents($logFile, date('[Y-m-d H:i:s]') . ' [ERROR] Unable to open lock file.' . PHP_EOL, FILE_APPEND | LOCK_EX);
    exit(1);
}
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fclose($fp);
    file_put_contents($logFile, date('[Y-m-d H:i:s]') . ' [SKIP]  Another scan is already running.' . PHP_EOL, FILE_APPEND | LOCK_EX);
    exit(0);
}

// ── Run market scan ───────────────────────────────────────────────────────────
try {
    $result = (new MarketScanService())->run();
} catch (Throwable $e) {
    $result = ['status' => 'error', 'message' => $e->getMessage()];
}

$status  = strtoupper((string) ($result['status']  ?? 'ok'));
$message = (string) ($result['message'] ?? '');
$details = (string) ($result['details'] ?? '');
file_put_contents(
    $logFile,
    date('[Y-m-d H:i:s]') . " [{$status}] {$message}" . ($details !== '' ? " | {$details}" : '') . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

// ── End-of-day AI report (fires once around 20:00 EAT) ───────────────────────
// Uses a dated file flag instead of APCu (APCu is not available in all XAMPP installs).
$nowEat      = now_eat();
$currentHour = (int) $nowEat->format('H');
$currentMin  = (int) $nowEat->format('i');
$reportFlag  = $cacheDir . DIRECTORY_SEPARATOR . 'daily_report_' . $nowEat->format('Y-m-d') . '.flag';

// Send once at 20:00–20:15 EAT; flag file prevents duplicate sends on the same day.
if ($currentHour === 20 && $currentMin <= 15 && !file_exists($reportFlag)) {
    try {
        $reportText = (new AiSummaryService())->dailyReport();

        if ($reportText !== '') {
            (new NotificationService())->sendSignalEmail(
                'Daily Market Report — ' . $nowEat->format('Y-m-d'),
                $reportText
            );
        }

        // Write flag file so subsequent cron runs within the same day do not resend.
        file_put_contents($reportFlag, date('Y-m-d H:i:s'));

        file_put_contents(
            $logFile,
            date('[Y-m-d H:i:s]') . ' [REPORT] Daily report sent.' . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

    } catch (Throwable $e) {
        error_log('[Cron] Daily report failed: ' . $e->getMessage());
        file_put_contents(
            $logFile,
            date('[Y-m-d H:i:s]') . ' [REPORT-ERR] ' . $e->getMessage() . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}

// ── Release lock ─────────────────────────────────────────────────────────────
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
