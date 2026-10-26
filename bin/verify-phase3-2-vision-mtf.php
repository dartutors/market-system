<?php
declare(strict_types=1);

/**
 * Deterministic checks for Phase 3.2 — AI Vision Multi-Timeframe Workspace
 * (app/Vision/*). Unlike every prior phase's OHLC-row fixtures, this phase's
 * engine consumes ALREADY-PARSED vision_analysis.md JSON (one per uploaded
 * screenshot) — so fixtures here are hand-built `['trend'=>..,
 * 'recommendation'=>['decision'=>..,'confidence'=>..]]` arrays per timeframe
 * label, with the weighted-vote arithmetic verified by hand in the comments
 * next to each fixture.
 *
 * There is no live AI-vision smoke test in this script: the per-image AI
 * call needs a real uploaded screenshot, which can't be meaningfully
 * fabricated the way live candle data can for every prior phase. The full
 * upload -> analyze -> decide pipeline was instead verified against a real
 * browser session (see the AI Vision module memory notes).
 *
 * Run with:
 *   php bin/verify-phase3-2-vision-mtf.php
 *
 * Exits 0 if all checks pass, 1 otherwise.
 */

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;
use App\Services\AiVisionSynthesisService;
use App\Vision\CompletenessScorer;
use App\Vision\TopDownAnalysisEngine;
use App\Vision\VisionDecisionEngine;
use App\Vision\VisionTimeframeCatalog;

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

/** One hand-built per-timeframe vision_analysis.md-shaped result. */
function slot(string $trend, string $decision = 'NO_TRADE', float $confidence = 50.0): array
{
    return ['trend' => $trend, 'recommendation' => ['decision' => $decision, 'confidence' => $confidence]];
}

echo "=== Phase 3.2 AI Vision Multi-Timeframe Workspace — Verification ===\n\n";

// ── 1. VisionTimeframeCatalog ────────────────────────────────────────────────
echo "--- VisionTimeframeCatalog ---\n";
$labels  = VisionTimeframeCatalog::labels();
$weights = VisionTimeframeCatalog::weights();
$buckets = VisionTimeframeCatalog::buckets();
checkTrue('Catalog has exactly 9 timeframe labels', count($labels) === 9, 'got ' . count($labels));
checkTrue('Every label has a positive weight', array_reduce($labels, static fn(bool $ok, string $l): bool => $ok && ($weights[$l] ?? 0.0) > 0.0, true));
$allBucketLabels = array_merge(...array_values($buckets));
sort($allBucketLabels);
$sortedLabels = $labels;
sort($sortedLabels);
checkTrue('Buckets partition all 9 labels exactly (no gaps, no overlaps)', $allBucketLabels === $sortedLabels, implode(',', $allBucketLabels));
checkTrue('Monthly has the highest weight (higher TF weighted more)', $weights['Monthly'] === max($weights));
checkTrue('M1 has the lowest weight', $weights['M1'] === min($weights));

// ── 2. TopDownAnalysisEngine — all-agree bullish fixture ────────────────────
echo "\n--- TopDownAnalysisEngine (all-agree) ---\n";
// Long bucket (Monthly 9, Weekly 7, Daily 5) all bullish @80% -> normalized +1.0 -> bullish.
// Medium bucket (H4 3.5, H1 2.5) all bullish @70% -> bullish. Short bucket (M15 1.0, M5 0.6) bullish, M5 has a BUY trigger.
$allAgree = [
    'Monthly' => slot('bullish', 'NO_TRADE', 80.0),
    'Weekly'  => slot('bullish', 'NO_TRADE', 80.0),
    'Daily'   => slot('bullish', 'NO_TRADE', 80.0),
    'H4'      => slot('bullish', 'NO_TRADE', 70.0),
    'H1'      => slot('bullish', 'NO_TRADE', 70.0),
    'M15'     => slot('bullish', 'WAIT', 60.0),
    'M5'      => slot('bullish', 'BUY', 75.0),
];
$topDownAgree = (new TopDownAnalysisEngine())->analyze($allAgree);
checkTrue('All-agree: overall trend is bullish', $topDownAgree['overall_trend'] === 'bullish', $topDownAgree['overall_trend']);
checkTrue('All-agree: alignment is aligned', $topDownAgree['alignment'] === 'aligned', $topDownAgree['alignment']);
checkTrue('All-agree: no conflicts', $topDownAgree['conflicts'] === []);
checkTrue('All-agree: no potential reversal', $topDownAgree['potential_reversal'] === false);
checkTrue('All-agree: entry opportunity flagged (M5 BUY aligns with bullish bias)', $topDownAgree['entry_opportunity'] === true);
checkTrue('All-agree: lowest timeframe signal is M5 BUY', $topDownAgree['lowest_timeframe_signal']['label'] === 'M5' && $topDownAgree['lowest_timeframe_signal']['decision'] === 'BUY');
checkTrue('All-agree: momentum is strengthening (long and short buckets agree)', $topDownAgree['momentum'] === 'strengthening');

$decisionAgree = (new VisionDecisionEngine())->decide($topDownAgree, CompletenessScorer::score(array_keys($allAgree)), $allAgree);
checkTrue('All-agree: decision engine calls BUY', $decisionAgree['decision'] === 'BUY', $decisionAgree['decision']);
checkTrue('All-agree: risk level is not high', $decisionAgree['risk_level'] !== 'high', $decisionAgree['risk_level']);

// ── 3. TopDownAnalysisEngine — the spec's own worked example ────────────────
// Monthly/Weekly bullish (long bucket -> bullish despite Daily reading neutral,
// since a neutral vote contributes 0 rather than canceling the positives:
// weightedSum = 9*.8 + 7*.8 + 5*0 = 12.8, usedWeight = 7.2+5.6+2.5 = 15.3,
// normalized = 0.837 -> bullish). H4/H1 bearish (medium bucket -> bearish).
// M15/M5 bullish with a BUY trigger (short bucket -> bullish). Long vs medium
// disagree -> potential_reversal=true, alignment='conflicted'.
echo "\n--- TopDownAnalysisEngine (worked example: HTF conflict) ---\n";
$reversalFixture = [
    'Monthly' => slot('bullish', 'NO_TRADE', 80.0),
    'Weekly'  => slot('bullish', 'NO_TRADE', 80.0),
    'Daily'   => slot('neutral', 'NO_TRADE', 50.0),
    'H4'      => slot('bearish', 'NO_TRADE', 70.0),
    'H1'      => slot('bearish', 'WAIT', 60.0),
    'M15'     => slot('bullish', 'BUY', 65.0),
    'M5'      => slot('bullish', 'BUY', 70.0),
];
$topDownReversal = (new TopDownAnalysisEngine())->analyze($reversalFixture);
checkTrue('Worked example: long bucket bias is bullish', $topDownReversal['buckets']['long']['bias'] === 'bullish', (string) $topDownReversal['buckets']['long']['bias']);
checkTrue('Worked example: medium bucket bias is bearish', $topDownReversal['buckets']['medium']['bias'] === 'bearish', (string) $topDownReversal['buckets']['medium']['bias']);
checkTrue('Worked example: short bucket bias is bullish', $topDownReversal['buckets']['short']['bias'] === 'bullish', (string) $topDownReversal['buckets']['short']['bias']);
checkTrue('Worked example: potential reversal flagged (long vs medium conflict)', $topDownReversal['potential_reversal'] === true);
checkTrue('Worked example: alignment is conflicted', $topDownReversal['alignment'] === 'conflicted', $topDownReversal['alignment']);
checkTrue('Worked example: H4 and H1 appear as conflicting timeframes', in_array('H4', $topDownReversal['conflicting_timeframes'], true) && in_array('H1', $topDownReversal['conflicting_timeframes'], true));

$completenessReversal = CompletenessScorer::score(array_keys($reversalFixture));
$decisionReversal = (new VisionDecisionEngine())->decide($topDownReversal, $completenessReversal, $reversalFixture);
checkTrue('Worked example: decision engine calls WAIT despite M15/M5 BUY signals (matches the spec\'s own example exactly)', $decisionReversal['decision'] === 'WAIT', $decisionReversal['decision']);

// ── 4. TopDownAnalysisEngine — insufficient bucket data ─────────────────────
echo "\n--- TopDownAnalysisEngine (insufficient bucket data) ---\n";
// Only the long bucket (Monthly+Weekly+Daily, weight 21) has data — 69% of
// total weight (30.4), clearing the 40% completeness floor on its own, but
// only ONE bucket has data -> alignment must be 'insufficient_data', not
// a false-positive "aligned" from having nothing to disagree with.
$oneBucketOnly = [
    'Monthly' => slot('bullish', 'NO_TRADE', 80.0),
    'Weekly'  => slot('bullish', 'NO_TRADE', 80.0),
    'Daily'   => slot('bullish', 'NO_TRADE', 80.0),
];
$topDownOneBucket = (new TopDownAnalysisEngine())->analyze($oneBucketOnly);
checkTrue('One bucket only: alignment is insufficient_data', $topDownOneBucket['alignment'] === 'insufficient_data', $topDownOneBucket['alignment']);
$completenessOneBucket = CompletenessScorer::score(array_keys($oneBucketOnly));
checkTrue('One bucket only: completeness clears the 40% floor on its own', $completenessOneBucket['percentage'] >= 40.0, (string) $completenessOneBucket['percentage']);
$decisionOneBucket = (new VisionDecisionEngine())->decide($topDownOneBucket, $completenessOneBucket, $oneBucketOnly);
checkTrue('One bucket only: decision engine calls NO_TRADE (insufficient_data overrides high completeness)', $decisionOneBucket['decision'] === 'NO_TRADE', $decisionOneBucket['decision']);

// ── 5. CompletenessScorer ────────────────────────────────────────────────────
echo "\n--- CompletenessScorer ---\n";
$fullNine = CompletenessScorer::score(VisionTimeframeCatalog::labels());
checkTrue('Full 9-timeframe upload scores 100%', abs($fullNine['percentage'] - 100.0) < 1e-9, (string) $fullNine['percentage']);
checkTrue('Full 9-timeframe upload has no missing_important entries', $fullNine['missing_important'] === []);

$missingLongTerm = CompletenessScorer::score(['H4', 'H1', 'M30', 'M15', 'M5', 'M1']);
checkTrue('Missing all long-term timeframes reduces completeness well below 100%', $missingLongTerm['percentage'] < 40.0, (string) $missingLongTerm['percentage']);
checkTrue('Missing all long-term timeframes flags all 3 as missing_important', $missingLongTerm['missing_important'] === ['Monthly', 'Weekly', 'Daily']);
checkTrue('Missing all long-term timeframes produces 3 warnings', count($missingLongTerm['warnings']) === 3);

// ── 6. VisionDecisionEngine — low completeness ──────────────────────────────
echo "\n--- VisionDecisionEngine (low completeness) ---\n";
$onlyM5 = ['M5' => slot('bullish', 'BUY', 90.0)];
$topDownOnlyM5 = (new TopDownAnalysisEngine())->analyze($onlyM5);
$completenessOnlyM5 = CompletenessScorer::score(array_keys($onlyM5));
checkTrue('Single M5 upload scores under the 40% floor', $completenessOnlyM5['percentage'] < 40.0, (string) $completenessOnlyM5['percentage']);
$decisionOnlyM5 = (new VisionDecisionEngine())->decide($topDownOnlyM5, $completenessOnlyM5, $onlyM5);
checkTrue('Low completeness forces NO_TRADE regardless of the single signal', $decisionOnlyM5['decision'] === 'NO_TRADE', $decisionOnlyM5['decision']);

// ── 7. AiVisionSynthesisService — rule-based fallback ───────────────────────
echo "\n--- AiVisionSynthesisService (rule-based fallback) ---\n";
$originalUseAi = $_ENV['USE_AI_SUMMARY'] ?? null;
$originalUseAiServer = $_SERVER['USE_AI_SUMMARY'] ?? null;
unset($_ENV['USE_AI_SUMMARY'], $_SERVER['USE_AI_SUMMARY']);

$synthesis = (new AiVisionSynthesisService())->synthesize('EUR/USD', $allAgree, $topDownAgree, CompletenessScorer::score(array_keys($allAgree)), $decisionAgree);
checkTrue('Rule-based synthesis is used when USE_AI_SUMMARY is unset', $synthesis['provider'] === 'rule_based', $synthesis['provider']);
checkTrue('Rule-based narrative contains a Conclusion section', str_contains($synthesis['narrative'], 'Conclusion:'));
checkTrue('Rule-based narrative mentions every uploaded timeframe', array_reduce(array_keys($allAgree), static fn(bool $ok, string $l): bool => $ok && str_contains($synthesis['narrative'], $l), true));
checkTrue('Rule-based synthesis agrees_with_technical is true', $synthesis['agrees_with_technical'] === true);

if ($originalUseAi === null) { unset($_ENV['USE_AI_SUMMARY']); } else { $_ENV['USE_AI_SUMMARY'] = $originalUseAi; }
if ($originalUseAiServer === null) { unset($_SERVER['USE_AI_SUMMARY']); } else { $_SERVER['USE_AI_SUMMARY'] = $originalUseAiServer; }

// ── 8. Structural wiring ────────────────────────────────────────────────────
echo "\n--- Structural wiring ---\n";
$tableExists = Database::connection()->query("SHOW TABLES LIKE 'vision_sessions'")->fetch();
checkTrue('vision_sessions table exists (migration 031 applied)', (bool) $tableExists);

$sessionIdColumn = Database::connection()->query("SHOW COLUMNS FROM uploaded_charts LIKE 'session_id'")->fetch();
checkTrue('uploaded_charts.session_id column exists (migration 032 applied)', (bool) $sessionIdColumn);

$indexRow = Database::connection()->query("SHOW INDEX FROM uploaded_charts WHERE Key_name = 'idx_uploaded_charts_session'")->fetch();
checkTrue('uploaded_charts.session_id has an index', (bool) $indexRow);

$controllerSrc = (string) file_get_contents(__DIR__ . '/../app/Controllers/VisionController.php');
checkTrue('VisionController imports TopDownAnalysisEngine', str_contains($controllerSrc, 'use App\Vision\TopDownAnalysisEngine;'));
checkTrue('VisionController imports VisionDecisionEngine', str_contains($controllerSrc, 'use App\Vision\VisionDecisionEngine;'));
checkTrue('VisionController persists VisionSession', str_contains($controllerSrc, 'VisionSession::create('));
checkTrue('VisionController still gates on feature_enabled(AI_VISION) (no new flag introduced)', str_contains($controllerSrc, "feature_enabled('AI_VISION')"));

$moduleSrc = (string) file_get_contents(__DIR__ . '/../app/Modules/Vision/VisionModule.php');
checkTrue('VisionModule::services() lists the new App\Vision engine classes', str_contains($moduleSrc, 'TopDownAnalysisEngine::class') && str_contains($moduleSrc, 'VisionDecisionEngine::class'));

$indexSrc = (string) file_get_contents(__DIR__ . '/../public/index.php');
checkTrue('public/index.php still routes GET /vision (unchanged route, no new module/flag)', str_contains($indexSrc, "'/vision'"));
checkTrue('public/index.php still routes POST /vision/analyze', str_contains($indexSrc, "'/vision/analyze'"));

echo "\n";
echo $failures === 0
    ? "ALL CHECKS PASSED\n"
    : "{$failures} CHECK(S) FAILED\n";

exit($failures === 0 ? 0 : 1);
