<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\Models\CurrencyStrengthSnapshot;
use App\Models\PasswordReset;

/**
 * Daily housekeeping — expired password-reset tokens, aged currency-
 * strength snapshots, and oversized plaintext log files. None of this
 * existed before Phase 20's certification pass flagged it: every write
 * path this app has is additive-only with no cron doing cleanup, so these
 * tables/files would otherwise grow forever. Intended cadence: once daily
 * (Windows Task Scheduler), same lock-file/log idiom as the other cron/*
 * scripts so a slow run can't stack up overlapping instances.
 */

$cacheDir = storage_path('cache');
$logsDir  = storage_path('logs');
foreach ([$cacheDir, $logsDir] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

$lockFile = $cacheDir . DIRECTORY_SEPARATOR . 'maintenance.lock';
$logFile  = $logsDir  . DIRECTORY_SEPARATOR . 'maintenance.log';

$fp = fopen($lockFile, 'c+');
if ($fp === false) {
    file_put_contents($logFile, date('[Y-m-d H:i:s]') . ' [ERROR] Unable to open lock file.' . PHP_EOL, FILE_APPEND | LOCK_EX);
    exit(1);
}
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fclose($fp);
    file_put_contents($logFile, date('[Y-m-d H:i:s]') . ' [SKIP]  Another maintenance run is already active.' . PHP_EOL, FILE_APPEND | LOCK_EX);
    exit(0);
}

/** Keeps a log file from growing unbounded — truncates to its last N lines once it crosses the size threshold. */
$rotateLog = static function (string $path, int $maxBytes = 5 * 1024 * 1024, int $keepLines = 5000): ?int {
    if (!is_file($path) || filesize($path) <= $maxBytes) {
        return null;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return null;
    }
    $before = count($lines);
    $kept = array_slice($lines, -$keepLines);
    file_put_contents($path, implode(PHP_EOL, $kept) . PHP_EOL, LOCK_EX);
    return $before - count($kept);
};

$results = [];

try {
    $results['password_resets_deleted'] = PasswordReset::deleteExpired();
} catch (Throwable $e) {
    $results['password_resets_error'] = $e->getMessage();
    error_log('[maintenance] password_resets cleanup failed: ' . $e->getMessage());
}

try {
    $results['currency_snapshots_deleted'] = CurrencyStrengthSnapshot::deleteOlderThan(180);
} catch (Throwable $e) {
    $results['currency_snapshots_error'] = $e->getMessage();
    error_log('[maintenance] currency_strength_snapshots cleanup failed: ' . $e->getMessage());
}

try {
    // A rejected/failed AI Vision or Trade Journal screenshot upload used
    // to leave its staged file in storage/uploads/tmp forever (fixed at the
    // source in VisionController/TradeJournalController this same pass) —
    // this sweeps anything that predates that fix, or any future edge case
    // that still slips through. 24h is generous headroom above any real
    // upload-to-persist latency.
    $stagingDir = base_path('storage/uploads/tmp');
    $swept = 0;
    if (is_dir($stagingDir)) {
        foreach (scandir($stagingDir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $stagingDir . DIRECTORY_SEPARATOR . $name;
            if (is_file($path) && (time() - (int) filemtime($path)) > 86400) {
                if (@unlink($path)) $swept++;
            }
        }
    }
    $results['staged_uploads_swept'] = $swept;
} catch (Throwable $e) {
    $results['staged_uploads_error'] = $e->getMessage();
    error_log('[maintenance] staged uploads sweep failed: ' . $e->getMessage());
}

foreach (['cron.log', 'calendar_refresh.log', 'market_stream.log', 'maintenance.log', 'php_errors.log'] as $name) {
    $path = $logsDir . DIRECTORY_SEPARATOR . $name;
    try {
        $trimmed = $rotateLog($path);
        if ($trimmed !== null) {
            $results['log_rotated'][$name] = $trimmed;
        }
    } catch (Throwable $e) {
        error_log("[maintenance] log rotation failed for {$name}: " . $e->getMessage());
    }
}

file_put_contents(
    $logFile,
    date('[Y-m-d H:i:s]') . ' [OK] ' . json_encode($results, JSON_UNESCAPED_SLASHES) . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

flock($fp, LOCK_UN);
fclose($fp);

echo json_encode($results, JSON_PRETTY_PRINT) . PHP_EOL;
