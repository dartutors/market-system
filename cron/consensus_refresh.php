<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\Consensus\OutcomeResolver;
use App\Services\DecisionIntelligenceEngine;

/**
 * Computes and persists a fresh Decision Intelligence recommendation for
 * every configured market, then resolves any past BUY/SELL decisions whose
 * expected duration has elapsed against real forward price action.
 * Deliberately separate from cron/market_scan.php (which runs every 5
 * minutes) — this one calls Claude AND OpenAI directly per market (2 AI
 * calls, not primary/fallback), so it needs a much longer interval to stay
 * affordable. Suggested Task Scheduler cadence: every 30 minutes.
 */

$cacheDir = storage_path('cache');
$logsDir  = storage_path('logs');
foreach ([$cacheDir, $logsDir] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

$lockFile = $cacheDir . DIRECTORY_SEPARATOR . 'consensus_refresh.lock';
$logFile  = $logsDir  . DIRECTORY_SEPARATOR . 'consensus_refresh.log';

$fp = fopen($lockFile, 'c+');
if ($fp === false) {
    file_put_contents($logFile, date('[Y-m-d H:i:s]') . ' [ERROR] Unable to open lock file.' . PHP_EOL, FILE_APPEND | LOCK_EX);
    exit(1);
}
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fclose($fp);
    file_put_contents($logFile, date('[Y-m-d H:i:s]') . ' [SKIP]  Another consensus refresh is already running.' . PHP_EOL, FILE_APPEND | LOCK_EX);
    exit(0);
}

try {
    $results = (new DecisionIntelligenceEngine())->decideAll(configured_markets());
    $summary = [];
    foreach ($results as $label => $result) {
        $summary[] = "{$label}: {$result['overall_decision']} ({$result['consensus_score']}%, agree {$result['agreement_percentage']}%, "
            . count($result['conflicts']) . ' conflicts)';
    }

    $outcomeSummary = ['checked' => 0, 'resolved' => 0, 'errors' => 0];
    try {
        $outcomeSummary = (new OutcomeResolver())->resolvePending();
    } catch (Throwable $e) {
        error_log('[consensus_refresh] Outcome resolution failed: ' . $e->getMessage());
    }

    file_put_contents(
        $logFile,
        date('[Y-m-d H:i:s]') . ' [OK] ' . implode(' | ', $summary)
        . " | outcomes: checked={$outcomeSummary['checked']} resolved={$outcomeSummary['resolved']} errors={$outcomeSummary['errors']}" . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
    $output = ['status' => 'ok', 'markets' => array_keys($results), 'outcomes' => $outcomeSummary];
} catch (Throwable $e) {
    error_log('[consensus_refresh] Fatal: ' . $e->getMessage());
    file_put_contents($logFile, date('[Y-m-d H:i:s]') . ' [ERROR] ' . $e->getMessage() . PHP_EOL, FILE_APPEND | LOCK_EX);
    $output = ['status' => 'error', 'message' => $e->getMessage()];
}

flock($fp, LOCK_UN);
fclose($fp);

echo json_encode($output, JSON_PRETTY_PRINT) . PHP_EOL;
