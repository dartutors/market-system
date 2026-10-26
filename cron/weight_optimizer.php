<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\Consensus\WeightOptimizerService;

/**
 * Runs the historical weight-reweighting simulation and writes any
 * sufficiently-supported proposals to weight_change_proposals — never
 * applies anything itself (see App\Consensus\WeightOptimizerService,
 * App\Controllers\LearningController::decideWeightProposal()). No AI
 * calls, no market data fetches — just reads already-persisted
 * consensus_decisions rows, so this is cheap enough to run daily.
 * Suggested Task Scheduler cadence: once per day.
 */

$cacheDir = storage_path('cache');
$logsDir  = storage_path('logs');
foreach ([$cacheDir, $logsDir] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

$lockFile = $cacheDir . DIRECTORY_SEPARATOR . 'weight_optimizer.lock';
$logFile  = $logsDir  . DIRECTORY_SEPARATOR . 'weight_optimizer.log';

$fp = fopen($lockFile, 'c+');
if ($fp === false) {
    file_put_contents($logFile, date('[Y-m-d H:i:s]') . ' [ERROR] Unable to open lock file.' . PHP_EOL, FILE_APPEND | LOCK_EX);
    exit(1);
}
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fclose($fp);
    file_put_contents($logFile, date('[Y-m-d H:i:s]') . ' [SKIP]  Another weight optimizer run is already in progress.' . PHP_EOL, FILE_APPEND | LOCK_EX);
    exit(0);
}

try {
    $created = (new WeightOptimizerService())->generateProposals();
    file_put_contents(
        $logFile,
        date('[Y-m-d H:i:s]') . " [OK] {$created} new proposal(s) created." . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
    $output = ['status' => 'ok', 'proposals_created' => $created];
} catch (Throwable $e) {
    error_log('[weight_optimizer] Fatal: ' . $e->getMessage());
    file_put_contents($logFile, date('[Y-m-d H:i:s]') . ' [ERROR] ' . $e->getMessage() . PHP_EOL, FILE_APPEND | LOCK_EX);
    $output = ['status' => 'error', 'message' => $e->getMessage()];
}

flock($fp, LOCK_UN);
fclose($fp);

echo json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
