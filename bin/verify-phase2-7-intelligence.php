<?php
declare(strict_types=1);

/**
 * Deterministic regression checks for the Phase 2.7 Market Intelligence
 * Engine (CurrencyStrengthCalculator, VolatilityIndexCalculator,
 * PairStrengthRanker, CorrelationMatrixCalculator, SentimentIndexService,
 * NewsImpactScorer). Uses synthetic PairSnapshot fixtures for the pure math
 * checks (no network needed); SentimentIndexService/NewsImpactScorer read
 * the real ai_summaries/news_items tables (read-only). Run with:
 *
 *   php bin/verify-phase2-7-intelligence.php
 *
 * Exits 0 if all checks pass, 1 otherwise.
 */

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\MarketIntelligence\CorrelationMatrixCalculator;
use App\MarketIntelligence\CurrencyStrengthCalculator;
use App\MarketIntelligence\MarketIntelligenceEngine;
use App\MarketIntelligence\NewsImpactScorer;
use App\MarketIntelligence\PairSnapshot;
use App\MarketIntelligence\PairStrengthRanker;
use App\MarketIntelligence\SentimentIndexService;
use App\MarketIntelligence\VolatilityIndexCalculator;
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

function makeSnapshot(
    string $label,
    float $dailyReturnPct,
    float $atrPercent,
    float $adx14,
    string $trend,
    array $closeSeries
): PairSnapshot {
    return new PairSnapshot(
        label: $label,
        symbol: $label,
        close: end($closeSeries),
        dailyReturnPct: $dailyReturnPct,
        periodReturnPct: $dailyReturnPct * 3,
        sma20: 1.0,
        sma50: 1.0,
        rsi14: 50.0,
        atr14: 0.001,
        atrPercent: $atrPercent,
        adx14: $adx14,
        trend: $trend,
        momentum: 'neutral',
        closeSeries: $closeSeries,
    );
}

// ── Fixture: 3 synthetic pairs with known, hand-computable relationships ───
// EUR/USD and GBP/USD move in lockstep (perfect positive correlation);
// USD/JPY moves inversely to both (perfect negative correlation).
$eurCloses = [1.10, 1.11, 1.12, 1.11, 1.13, 1.14, 1.13, 1.15, 1.16, 1.15];
$gbpCloses = [1.20, 1.21, 1.22, 1.21, 1.23, 1.24, 1.23, 1.25, 1.26, 1.25]; // same shape as EUR
$jpyCloses = [150.0, 149.0, 148.0, 149.0, 147.0, 146.0, 147.0, 145.0, 144.0, 145.0]; // inverse shape

$snapshots = [
    'EUR/USD' => makeSnapshot('EUR/USD', 1.5, 0.4, 30.0, 'bullish', $eurCloses),
    'GBP/USD' => makeSnapshot('GBP/USD', -0.8, 0.9, 45.0, 'bearish', $gbpCloses),
    'USD/JPY' => makeSnapshot('USD/JPY', 0.3, 0.2, 15.0, 'neutral', $jpyCloses),
];

// ── 1. CurrencyStrengthCalculator ───────────────────────────────────────────
// exposureVector() is always computed as a fixed BUY decomposition (base +1 /
// quote -1) — it measures the currency's own move, not a trade direction —
// so each pair's dailyReturnPct attributes directly to its base currency and
// inversely to its quote currency:
//   EUR/USD (+1.5): EUR += +1.5, USD += -1.5
//   GBP/USD (-0.8): GBP += -0.8, USD += +0.8  (USD running total: -0.7)
//   USD/JPY (+0.3): USD += +0.3 (running total: -0.4), JPY += -0.3
// EUR: 1.5/1 = 1.5 | GBP: -0.8/1 = -0.8 | USD: -0.4/3 = -0.1333 | JPY: -0.3/1 = -0.3
$strength = CurrencyStrengthCalculator::calculate($snapshots);
checkTrue('CurrencyStrengthCalculator: EUR strength matches hand-computed value', abs($strength['EUR'] - 1.5) < 0.001, (string) $strength['EUR']);
checkTrue('CurrencyStrengthCalculator: GBP strength matches hand-computed value', abs($strength['GBP'] - (-0.8)) < 0.001, (string) $strength['GBP']);
checkTrue('CurrencyStrengthCalculator: USD strength averages across all 3 pairs it appears in', abs($strength['USD'] - (-0.4 / 3)) < 0.001, (string) $strength['USD']);
checkTrue('CurrencyStrengthCalculator: JPY strength matches hand-computed value', abs($strength['JPY'] - (-0.3)) < 0.001, (string) $strength['JPY']);
checkTrue('CurrencyStrengthCalculator: result is sorted strongest first', array_key_first($strength) === 'EUR', (string) array_key_first($strength));

// ── 2. VolatilityIndexCalculator ────────────────────────────────────────────
$volatility = VolatilityIndexCalculator::calculate($snapshots);
checkTrue('VolatilityIndexCalculator: overall_index is the mean of the 3 ATR%s', abs($volatility['overall_index'] - ((0.4 + 0.9 + 0.2) / 3)) < 0.001, (string) $volatility['overall_index']);
checkTrue('VolatilityIndexCalculator: GBP/USD (0.9%) is flagged high', $volatility['by_pair']['GBP/USD']['level'] === 'high');
checkTrue('VolatilityIndexCalculator: USD/JPY (0.2%) is flagged low', $volatility['by_pair']['USD/JPY']['level'] === 'low');
checkTrue('VolatilityIndexCalculator: EUR/USD (0.4%) is flagged normal', $volatility['by_pair']['EUR/USD']['level'] === 'normal');

// ── 3. PairStrengthRanker ────────────────────────────────────────────────────
$ranked = PairStrengthRanker::rank($snapshots);
checkTrue('PairStrengthRanker: bullish EUR/USD (adx 30) ranks above neutral USD/JPY (score 0)', $ranked[0]['label'] === 'EUR/USD');
checkTrue('PairStrengthRanker: bearish GBP/USD (adx 45, signed -45) ranks last', $ranked[count($ranked) - 1]['label'] === 'GBP/USD');
checkTrue('PairStrengthRanker: neutral trend always signs to a zero score regardless of ADX', $ranked[1]['signed_score'] === 0.0, (string) $ranked[1]['signed_score']);

// ── 4. CorrelationMatrixCalculator ──────────────────────────────────────────
$correlations = CorrelationMatrixCalculator::calculate($snapshots);
$byPairKey = [];
foreach ($correlations as $c) {
    $byPairKey[$c['pair_a'] . '|' . $c['pair_b']] = $c;
}
checkTrue(
    'CorrelationMatrixCalculator: EUR/USD vs GBP/USD (identical shape) is a near-perfect positive correlation',
    $byPairKey['EUR/USD|GBP/USD']['coefficient'] > 0.99,
    (string) $byPairKey['EUR/USD|GBP/USD']['coefficient']
);
checkTrue(
    'CorrelationMatrixCalculator: EUR/USD vs USD/JPY (inverse shape) is a near-perfect negative correlation',
    $byPairKey['EUR/USD|USD/JPY']['coefficient'] < -0.99,
    (string) $byPairKey['EUR/USD|USD/JPY']['coefficient']
);
checkTrue('CorrelationMatrixCalculator: near-perfect positive correlation is labeled "strong positive"', $byPairKey['EUR/USD|GBP/USD']['label'] === 'strong positive');
checkTrue('CorrelationMatrixCalculator: 3 pairs produce exactly 3 unique combinations', count($correlations) === 3, (string) count($correlations));
checkTrue('CorrelationMatrixCalculator: results are sorted by |coefficient| descending', abs($correlations[0]['coefficient']) >= abs($correlations[1]['coefficient']));

// Degenerate case: fewer than 2 data points must not error or divide by zero.
$degenerate = [
    'A' => makeSnapshot('A', 0.0, 0.1, 10.0, 'neutral', [1.0]),
    'B' => makeSnapshot('B', 0.0, 0.1, 10.0, 'neutral', [1.0]),
];
$degenerateResult = CorrelationMatrixCalculator::calculate($degenerate);
checkTrue('CorrelationMatrixCalculator: a single-point close series degrades to coefficient 0.0, no error', $degenerateResult[0]['coefficient'] === 0.0);

// ── 5. SentimentIndexService (reads the real ai_summaries table, read-only) ─
$sentiment = (new SentimentIndexService())->calculate();
checkTrue('SentimentIndexService::calculate() index is within -100..100', $sentiment['index'] >= -100.0 && $sentiment['index'] <= 100.0, (string) $sentiment['index']);
checkTrue('SentimentIndexService::calculate() returns a non-empty label', trim($sentiment['label']) !== '');

// ── 6. NewsImpactScorer (reads the real news_items table, read-only) ───────
$newsImpact = (new NewsImpactScorer())->calculate();
checkTrue('NewsImpactScorer::calculate() average_impact is within 0..100', $newsImpact['average_impact'] >= 0.0 && $newsImpact['average_impact'] <= 100.0, (string) $newsImpact['average_impact']);
checkTrue('NewsImpactScorer::calculate() high_impact_count does not exceed recent count', $newsImpact['high_impact_count'] <= count($newsImpact['recent']));

// ── 7. Feature flag ──────────────────────────────────────────────────────────
checkTrue('FeatureFlagService::knownFlags() includes ADVANCED_ANALYTICS', in_array('ADVANCED_ANALYTICS', FeatureFlagService::knownFlags(), true));
checkTrue('feature_enabled(ADVANCED_ANALYTICS) is boolean', is_bool(feature_enabled('ADVANCED_ANALYTICS')));

// ── 8. Live smoke test of the full engine (network-dependent, best-effort) ─
try {
    $snapshot = (new MarketIntelligenceEngine())->build();
    checkTrue('MarketIntelligenceEngine::build() returns pair snapshots for the configured markets', count($snapshot->pairSnapshots) > 0);
    checkTrue('MarketIntelligenceEngine::build() produces a currency strength ranking', count($snapshot->currencyStrength) > 0);
    checkTrue('MarketIntelligenceEngine::build() produces a strength ranking entry per pair', count($snapshot->strengthRanking) === count($snapshot->pairSnapshots));
    $snapshotArray = $snapshot->toArray();
    checkTrue(
        'MarketIntelligenceSnapshot::toArray() contains all Phase 2.7 keys',
        array_diff(
            ['pair_snapshots', 'currency_strength', 'volatility_index', 'session_activity', 'sentiment_index', 'news_impact', 'correlation_matrix', 'strength_ranking', 'generated_at'],
            array_keys($snapshotArray)
        ) === []
    );
} catch (\Throwable $e) {
    echo "SKIP  Live engine smoke test — network/data unavailable: " . $e->getMessage() . "\n";
}

echo "\n";
echo $failures === 0
    ? "ALL CHECKS PASSED\n"
    : "{$failures} CHECK(S) FAILED\n";

exit($failures === 0 ? 0 : 1);
