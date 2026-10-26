<?php
declare(strict_types=1);

/**
 * Deterministic regression checks for Phase 2.8 — AI Learning Engine
 * (App\Learning\*, App\Models\AiLearningMemory, and — most critically —
 * that the new 'calibration' ScoreEngine factor changes NOTHING for any
 * existing installation by default).
 *
 * ConfidenceCalibrationService is tested via its pure calculateFromBands()
 * against a synthetic fixture (no signal_events rows are touched — that
 * table has no safe delete path). PatternLearningService/TradeJournal
 * pattern stats ARE tested against real inserted rows, cleaned up via
 * TradeJournal::softDelete() (same convention as
 * bin/verify-phase2-6-journal.php) plus a manual pattern_statistics delete
 * for the row it creates (that table has no soft-delete concept at all).
 *
 * Run with:
 *   php bin/verify-phase2-8-learning.php
 *
 * Exits 0 if all checks pass, 1 otherwise.
 */

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\Config\Score as ScoreConfig;
use App\Core\Database;
use App\Decision\ScoreEngine;
use App\Learning\ConfidenceCalibrationService;
use App\Learning\PatternLearningService;
use App\Learning\RecommendationHistoryService;
use App\Learning\SetupPatternCatalog;
use App\Models\AiLearningMemory;
use App\Models\TradeJournal;
use App\Services\FeatureFlagService;

$failures = 0;

function checkTrue(string $label, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "PASS  {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

// ── 1. THE critical check: calibration factor's default weight is 0.0 ──────
// This is what makes the whole DecisionEngine integration safe — prove it
// directly against the real, unmodified App\Config\Score::weights().
$weights = ScoreConfig::weights();
checkTrue('App\Config\Score::weights() includes a calibration key', array_key_exists('calibration', $weights));
checkTrue('App\Config\Score::weights() calibration defaults to 0.0 (zero effect until explicitly configured)', abs($weights['calibration']) < 0.0001, (string) $weights['calibration']);
checkTrue('App\Config\Score::weights() still sums to 1.0 with calibration included', abs(array_sum($weights) - 1.0) < 0.0001, (string) array_sum($weights));

// ScoreEngine must produce byte-identical results to before with a 100%-confidence
// scenario across the original 6 factors, whether or not 'calibration' is present.
$factorsWithout = ['technical' => 100.0, 'momentum' => 100.0, 'news' => 100.0, 'calendar' => 100.0, 'backtest' => 100.0, 'ai' => 100.0];
$factorsWith    = $factorsWithout + ['calibration' => 0.0]; // even an extreme low calibration value...
$resultWithout  = (new ScoreEngine())->score($factorsWithout);
$resultWith     = (new ScoreEngine())->score($factorsWith);
checkTrue(
    'ScoreEngine::score() confidence is identical with or without an explicit calibration value (weight 0.0 default)',
    abs($resultWithout->confidence - $resultWith->confidence) < 0.0001,
    "{$resultWithout->confidence} vs {$resultWith->confidence}"
);
checkTrue('ScoreEngine::score() with all 6 original factors at 100 still yields confidence 100', abs($resultWithout->confidence - 100.0) < 0.01, (string) $resultWithout->confidence);

// ── 2. ConfidenceCalibrationService — pure math against a synthetic fixture ─
$fixtureBands = [
    ['band' => '80%+',      'total' => 10, 'wins' => 9], // actual 90%, assumed 85 -> error +5
    ['band' => '70-79%',    'total' => 20, 'wins' => 8], // actual 40%, assumed 75 -> error -35
    ['band' => '60-69%',    'total' => 5,  'wins' => 3], // actual 60%, assumed 65 -> error -5
    ['band' => 'Below 60%', 'total' => 0,  'wins' => 0], // no sample
];
$calibration = ConfidenceCalibrationService::calculateFromBands($fixtureBands);
checkTrue('ConfidenceCalibrationService: 80%+ band actual_win_rate is 90.0', abs($calibration['by_band'][0]['actual_win_rate'] - 90.0) < 0.01);
checkTrue('ConfidenceCalibrationService: 80%+ band calibration_error is +5.0 (underconfident)', abs($calibration['by_band'][0]['calibration_error'] - 5.0) < 0.01);
checkTrue('ConfidenceCalibrationService: 70-79% band calibration_error is -35.0 (badly overconfident)', abs($calibration['by_band'][1]['calibration_error'] - (-35.0)) < 0.01);
checkTrue('ConfidenceCalibrationService: 0-sample band does not error and reads 0% actual', $calibration['by_band'][3]['actual_win_rate'] === 0.0);
// Weighted avg error = (5*10 + -35*20 + -5*5) / 35 = (50 - 700 - 25) / 35 = -675/35 = -19.2857
checkTrue('ConfidenceCalibrationService: sample_size sums only bands with data', $calibration['sample_size'] === 35, (string) $calibration['sample_size']);
checkTrue('ConfidenceCalibrationService: avg_calibration_error matches the hand-computed weighted average', abs($calibration['avg_calibration_error'] - (-19.29)) < 0.01, (string) $calibration['avg_calibration_error']);
checkTrue('ConfidenceCalibrationService: overall_factor is 50 + avg_error, clamped to 0-100', abs($calibration['overall_factor'] - (50.0 - 19.29)) < 0.1, (string) $calibration['overall_factor']);
checkTrue('ConfidenceCalibrationService: well_calibrated is false when |avg_error| > 5', $calibration['well_calibrated'] === false);

$emptyCalibration = ConfidenceCalibrationService::calculateFromBands([]);
checkTrue('ConfidenceCalibrationService: an empty bands array degrades to neutral (factor 50, no error)', $emptyCalibration['overall_factor'] === 50.0 && $emptyCalibration['sample_size'] === 0);

// Extreme-overconfidence fixture must clamp at 0, not go negative.
$extremeBands = [['band' => '80%+', 'total' => 100, 'wins' => 0]]; // actual 0%, assumed 85 -> error -85
$extremeCalibration = ConfidenceCalibrationService::calculateFromBands($extremeBands);
checkTrue('ConfidenceCalibrationService: overall_factor clamps at 0.0, never negative', $extremeCalibration['overall_factor'] === 0.0, (string) $extremeCalibration['overall_factor']);

// ── 3. AiLearningMemory round-trip ──────────────────────────────────────────
$memoryId = AiLearningMemory::remember('verify_test_key', ['foo' => 'bar', 'n' => 42], 7);
checkTrue('AiLearningMemory::remember() returns a positive insert id', $memoryId > 0);
$readBack = AiLearningMemory::latest('verify_test_key');
checkTrue('AiLearningMemory::latest() round-trips the JSON value', $readBack['value'] === ['foo' => 'bar', 'n' => 42]);
checkTrue('AiLearningMemory::latest() round-trips sample_size', $readBack['sample_size'] === 7);
checkTrue('AiLearningMemory::latest() returns null for an unknown key', AiLearningMemory::latest('no_such_key_xyz') === null);
$history = AiLearningMemory::history('verify_test_key', 5);
checkTrue('AiLearningMemory::history() includes the row just written', count($history) >= 1);
Database::connection()->prepare('DELETE FROM ai_learning_memory WHERE memory_key = :k')->execute(['k' => 'verify_test_key']);
checkTrue('Cleanup: verify_test_key memory rows removed', AiLearningMemory::latest('verify_test_key') === null);

// ── 4. SetupPatternCatalog ──────────────────────────────────────────────────
checkTrue('SetupPatternCatalog::isValid() accepts a known pattern', SetupPatternCatalog::isValid('breakout'));
checkTrue('SetupPatternCatalog::isValid() rejects an unknown pattern', !SetupPatternCatalog::isValid('made_up_pattern'));
checkTrue('SetupPatternCatalog::label() falls back to the raw key for an unknown pattern', SetupPatternCatalog::label('made_up_pattern') === 'made_up_pattern');

// ── 5. PatternLearningService (real insert + cleanup) ───────────────────────
$journalId = TradeJournal::create([
    'market_label'  => 'EUR/USD',
    'direction'     => 'BUY',
    'setup_pattern' => 'breakout',
    'entry_price'   => 1.2000,
]);
TradeJournal::close($journalId, ['exit_price' => 1.2050, 'profit_loss' => 42.0, 'outcome' => 'win']);

$grouped = (new PatternLearningService())->rebuild();
$breakoutRow = null;
foreach ($grouped as $row) {
    if ($row['setup_pattern'] === 'breakout') { $breakoutRow = $row; break; }
}
checkTrue('PatternLearningService::rebuild() picks up the test trade under "breakout"', $breakoutRow !== null && $breakoutRow['occurrences'] >= 1);

TradeJournal::softDelete($journalId);
Database::connection()->prepare("DELETE FROM pattern_statistics WHERE pattern_name = 'breakout' AND market_label = 'ALL'")->execute();
checkTrue('Cleanup: test trade_journal entry is soft-deleted', TradeJournal::findById($journalId) === null);

// ── 6. RecommendationHistoryService — shape check against real ai_decisions ─
$history = (new RecommendationHistoryService())->recent(5);
checkTrue('RecommendationHistoryService::recent() returns an array', is_array($history));
if (!empty($history)) {
    $first = $history[0];
    checkTrue(
        'RecommendationHistoryService::recent() rows have the expected keys',
        array_diff(['id', 'market_label', 'decision', 'confidence', 'created_at', 'logged', 'outcome', 'profit_loss'], array_keys($first)) === []
    );
    checkTrue('RecommendationHistoryService::recent() logged is a bool', is_bool($first['logged']));
}

// ── 7. Feature flag / module registration ───────────────────────────────────
checkTrue('FeatureFlagService::knownFlags() includes AI_LEARNING', in_array('AI_LEARNING', FeatureFlagService::knownFlags(), true));
checkTrue('feature_enabled(AI_LEARNING) is boolean', is_bool(feature_enabled('AI_LEARNING')));

echo "\n";
echo $failures === 0
    ? "ALL CHECKS PASSED\n"
    : "{$failures} CHECK(S) FAILED\n";

exit($failures === 0 ? 0 : 1);
