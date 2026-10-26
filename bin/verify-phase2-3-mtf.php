<?php
declare(strict_types=1);

/**
 * Deterministic regression checks for the Phase 2.3 Sprint B additions
 * (pullback identification, entry confirmation, AI multi-timeframe summary,
 * MultiTimeframeDecisionEngine). Uses synthetic TimeframeAnalysis fixtures —
 * no network calls, no live Yahoo Finance data. USE_AI_SUMMARY is expected
 * to be off in this environment, so the AI summary path exercises its
 * rule-based fallback deterministically. Run with:
 *
 *   php bin/verify-phase2-3-mtf.php
 *
 * Exits 0 if all checks pass, 1 otherwise.
 */

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\MultiTimeframe\EntryConfirmationEngine;
use App\MultiTimeframe\MultiTimeframeDecisionEngine;
use App\MultiTimeframe\MultiTimeframeResult;
use App\MultiTimeframe\PullbackDetector;
use App\MultiTimeframe\TimeframeAnalysis;
use App\MultiTimeframe\TrendAlignmentEngine;

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

function makeAnalysis(
    string $label,
    string $trend,
    string $momentum,
    float $close,
    float $sma20,
    float $sma50,
    float $rsi14,
    float $atr14 = 0.001
): TimeframeAnalysis {
    return new TimeframeAnalysis(
        label: $label,
        interval: '1d',
        range: '2y',
        trend: $trend,
        momentum: $momentum,
        close: $close,
        sma20: $sma20,
        sma50: $sma50,
        rsi14: $rsi14,
        macdHist: 0.0001,
        atr14: $atr14,
        barsUsed: 100,
        fetchedAt: new DateTimeImmutable('now'),
    );
}

// ── Fixture: all-bullish set with a healthy H4 pullback ────────────────────
$byTimeframe = [
    'Monthly' => makeAnalysis('Monthly', 'bullish', 'strengthening', 1.2000, 1.1900, 1.1800, 65.0),
    'Weekly'  => makeAnalysis('Weekly',  'bullish', 'strengthening', 1.2000, 1.1900, 1.1800, 63.0),
    'Daily'   => makeAnalysis('Daily',   'bullish', 'strengthening', 1.2000, 1.1900, 1.1800, 62.0),
    // H4 has dipped to sma20 (a pullback) but is still above sma50 — structure intact.
    'H4'      => makeAnalysis('H4', 'neutral', 'neutral', 1.1895, 1.1900, 1.1800, 45.0),
    'H1'      => makeAnalysis('H1', 'bullish', 'strengthening', 1.2000, 1.1900, 1.1800, 61.0),
    'M30'     => makeAnalysis('M30', 'bullish', 'strengthening', 1.2000, 1.1900, 1.1800, 60.0),
    // M15/M5 have resumed the bullish direction — this is what confirms entry.
    'M15'     => makeAnalysis('M15', 'bullish', 'strengthening', 1.2000, 1.1900, 1.1800, 64.0),
    'M5'      => makeAnalysis('M5', 'bullish', 'strengthening', 1.2000, 1.1900, 1.1800, 66.0),
];

$alignment = (new TrendAlignmentEngine())->analyze('EURUSD=X', $byTimeframe);
checkTrue('TrendAlignmentEngine: all-bullish fixture yields bullish overall bias', $alignment->overallBias === 'bullish', $alignment->overallBias);

// ── 1. PullbackDetector ─────────────────────────────────────────────────────
$pullbacks = (new PullbackDetector())->detect($alignment, $byTimeframe);
checkTrue('PullbackDetector: H4 (near SMA20, above SMA50) is flagged as a pullback', $pullbacks['H4']->isPullback === true);
checkTrue('PullbackDetector: Daily (already bullish-aligned) is not flagged as a pullback', $pullbacks['Daily']->isPullback === false);

// Structure-broken fixture: price has fallen through SMA50 against a bullish bias.
$brokenAnalysis = makeAnalysis('H1', 'bearish', 'weakening', 1.1750, 1.1900, 1.1800, 30.0);
$brokenByTf      = $byTimeframe;
$brokenByTf['H1'] = $brokenAnalysis;
$brokenAlignment  = (new TrendAlignmentEngine())->analyze('EURUSD=X', $brokenByTf);
$brokenPullbacks  = (new PullbackDetector())->detect($brokenAlignment, $brokenByTf);
checkTrue(
    'PullbackDetector: a timeframe that broke through SMA50 is NOT a pullback (structure damage)',
    $brokenPullbacks['H1']->isPullback === false
);

// Neutral-bias fixture: nothing to pull back toward.
$neutralByTf = [
    'Daily' => makeAnalysis('Daily', 'neutral', 'neutral', 1.2000, 1.2000, 1.2000, 50.0),
];
$neutralAlignment = (new TrendAlignmentEngine())->analyze('EURUSD=X', $neutralByTf);
$neutralPullbacks = (new PullbackDetector())->detect($neutralAlignment, $neutralByTf);
checkTrue(
    'PullbackDetector: neutral overall bias produces no pullback signals',
    $neutralPullbacks['Daily']->isPullback === false
);

// ── 2. EntryConfirmationEngine ──────────────────────────────────────────────
$entry = (new EntryConfirmationEngine())->confirm($alignment, $pullbacks, $byTimeframe);
checkTrue('EntryConfirmationEngine: pullback + M15/M5 resumption => confirmed', $entry->confirmed === true, $entry->state);
checkTrue('EntryConfirmationEngine: confirming timeframes include M15 and M5', in_array('M15', $entry->confirmingTimeframes, true) && in_array('M5', $entry->confirmingTimeframes, true));

// No-pullback fixture: fully aligned bullish set, nothing retracing.
$noPullbackByTf = $byTimeframe;
$noPullbackByTf['H4'] = makeAnalysis('H4', 'bullish', 'strengthening', 1.2000, 1.1900, 1.1800, 62.0);
$noPullbackAlignment = (new TrendAlignmentEngine())->analyze('EURUSD=X', $noPullbackByTf);
$noPullbacks         = (new PullbackDetector())->detect($noPullbackAlignment, $noPullbackByTf);
$noPullbackEntry     = (new EntryConfirmationEngine())->confirm($noPullbackAlignment, $noPullbacks, $noPullbackByTf);
checkTrue('EntryConfirmationEngine: no pullback anywhere => no_setup', $noPullbackEntry->state === 'no_setup', $noPullbackEntry->state);

// Pullback-without-resumption fixture: H4 pulls back, M15/M5 haven't resumed yet.
$pullbackOnlyByTf = $byTimeframe;
$pullbackOnlyByTf['M15'] = makeAnalysis('M15', 'neutral', 'neutral', 1.1980, 1.1900, 1.1800, 50.0);
$pullbackOnlyByTf['M5']  = makeAnalysis('M5', 'neutral', 'neutral', 1.1980, 1.1900, 1.1800, 50.0);
$pullbackOnlyAlignment   = (new TrendAlignmentEngine())->analyze('EURUSD=X', $pullbackOnlyByTf);
$pullbackOnlyPullbacks   = (new PullbackDetector())->detect($pullbackOnlyAlignment, $pullbackOnlyByTf);
$pullbackOnlyEntry       = (new EntryConfirmationEngine())->confirm($pullbackOnlyAlignment, $pullbackOnlyPullbacks, $pullbackOnlyByTf);
checkTrue('EntryConfirmationEngine: pullback without M15/M5 resumption => pullback_only', $pullbackOnlyEntry->state === 'pullback_only', $pullbackOnlyEntry->state);

// ── 3. MultiTimeframeDecisionEngine composition ─────────────────────────────
$decision = (new MultiTimeframeDecisionEngine())->decide('EURUSD=X', 'EUR/USD', $byTimeframe);
checkTrue('MultiTimeframeDecisionEngine: confirmed bullish setup => BUY recommendation', $decision->recommendation === 'BUY', $decision->recommendation);
checkTrue(
    'MultiTimeframeDecisionEngine: AI summary provider is rule_based, claude, or openai',
    in_array($decision->aiSummary['provider'], ['rule_based', 'claude', 'openai'], true),
    $decision->aiSummary['provider']
);
checkTrue('MultiTimeframeDecisionEngine: AI summary narrative is non-empty', trim((string) $decision->aiSummary['narrative']) !== '');

// Force the rule-based path deterministically regardless of this environment's USE_AI_SUMMARY setting.
$_ENV['USE_AI_SUMMARY'] = 'false';
$ruleBasedDecision = (new MultiTimeframeDecisionEngine())->decide('EURUSD=X', 'EUR/USD', $byTimeframe);
checkTrue(
    'MultiTimeframeAiSummaryService: falls back to rule_based when USE_AI_SUMMARY is off',
    $ruleBasedDecision->aiSummary['provider'] === 'rule_based',
    $ruleBasedDecision->aiSummary['provider']
);
unset($_ENV['USE_AI_SUMMARY']);

$neutralDecision = (new MultiTimeframeDecisionEngine())->decide('EURUSD=X', 'EUR/USD', $neutralByTf);
checkTrue('MultiTimeframeDecisionEngine: neutral bias => NO_TRADE recommendation', $neutralDecision->recommendation === 'NO_TRADE', $neutralDecision->recommendation);

$decisionArray = $decision->toArray();
checkTrue(
    'MultiTimeframeDecision::toArray() contains all Sprint B keys',
    array_diff(['symbol', 'alignment', 'pullbacks', 'entry', 'ai_summary', 'recommendation', 'recommendation_confidence', 'generated_at'], array_keys($decisionArray)) === []
);

echo "\n";
echo $failures === 0
    ? "ALL CHECKS PASSED\n"
    : "{$failures} CHECK(S) FAILED\n";

exit($failures === 0 ? 0 : 1);
