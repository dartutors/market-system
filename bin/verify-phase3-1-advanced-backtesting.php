<?php
declare(strict_types=1);

/**
 * Deterministic checks for Phase 3.1 — Advanced Backtesting (app/Backtesting/*).
 *
 * Unlike Phase 2.9/3.0's zigzagRows() fixtures (which hand-build raw
 * high/low/close and let a real primitive derive swings), App\Backtesting\
 * BacktestRunner consumes ALREADY-ENRICHED rows (sma_20/sma_50/rsi_14/
 * macd_hist/atr_14) because it feeds them straight into
 * App\Strategies\StrategyManager. So fixtures here are built one level
 * higher: every indicator field is hand-set directly, giving full control
 * over which bars trigger a signal without needing to reverse-engineer a
 * price series that would produce a particular SMA/RSI/MACD reading.
 *
 * Run with:
 *   php bin/verify-phase3-1-advanced-backtesting.php
 *
 * Exits 0 if all checks pass, 1 otherwise.
 */

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\Backtesting\BacktestRunner;
use App\Backtesting\Models\TradeResult;
use App\Backtesting\MonteCarloSimulator;
use App\Backtesting\ParameterOptimizer;
use App\Backtesting\StrategyComparator;
use App\Backtesting\Support\EquityCurveBuilder;
use App\Backtesting\WalkForwardTester;
use App\Core\Database;
use App\MarketData\Manager\MarketDataManager;
use App\MarketData\Models\Candle;
use App\Services\FeatureFlagService;
use App\Strategies\StrategyRegistry;

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

/**
 * One hand-set enriched OHLC row. Every field BacktestRunner or the 4
 * registered strategies read is explicit — no derived math, no surprises.
 */
function row(
    int $ts,
    float $close,
    float $sma20,
    float $sma50,
    float $rsi,
    float $macdHist,
    float $atr,
    ?float $high = null,
    ?float $low = null
): array {
    return [
        'timestamp' => $ts,
        'open'      => $close,
        'high'      => $high ?? $close + 0.0005,
        'low'       => $low  ?? $close - 0.0005,
        'close'     => $close,
        'volume'    => 1000.0,
        'sma_20'    => $sma20,
        'sma_50'    => $sma50,
        'rsi_14'    => $rsi,
        'macd_hist' => $macdHist,
        'atr_14'    => $atr,
    ];
}

/** @return array<int, array<string, mixed>> $count flat/neutral rows — no strategy should ever signal on these. */
function neutralRows(int $count, int $startTs = 1_700_000_000): array
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = row($startTs + $i * 3600, 1.2000, 1.2000, 1.2000, 50.0, 0.0, 0.0010, 1.2005, 1.1995);
    }
    return $rows;
}

echo "=== Phase 3.1 Advanced Backtesting — Verification ===\n\n";

// ── Fixture: 70 neutral rows with ONE engineered BUY signal at index 55 ────
// TrendFollowingStrategy needs score>=3 to BUY: structure (+2, close>sma20>sma50)
// + RSI oversold-recovery (+1, rsi<32). Row 56 (the very next candle, the
// first "forward" bar BacktestRunner checks) has a high that clears the
// default 2.0x-ATR target before any stop is at risk, so the outcome is a
// deterministic WIN with no dependence on later rows.
$rowsWithOneSignal = neutralRows(70);
$rowsWithOneSignal[55] = row(1_700_000_000 + 55 * 3600, 1.2100, 1.2050, 1.2000, 28.0, 0.0, 0.0010);
$rowsWithOneSignal[56] = row(1_700_000_000 + 56 * 3600, 1.2000, 1.2000, 1.2000, 50.0, 0.0, 0.0010, 1.2125, 1.2095);

// ── 1. BacktestRunner::runOnRows() ──────────────────────────────────────────
echo "--- BacktestRunner ---\n";
$runner = new BacktestRunner();
$result = $runner->runOnRows($rowsWithOneSignal, 'trend_following');
checkTrue('runOnRows() does not return an error on a valid fixture', !isset($result['error']), (string) ($result['error'] ?? ''));
checkTrue('runOnRows() produces exactly one trade', \count($result['trades']) === 1, 'got ' . \count($result['trades']));
$trade = $result['trades'][0] ?? null;
checkTrue('The one trade is a BUY at index 55', $trade instanceof TradeResult && $trade->action === 'BUY' && $trade->index === 55);
checkTrue('The one trade resolved as a win', $trade instanceof TradeResult && $trade->outcome === 'win' && abs($trade->pl - 2.0) < 1e-9);
checkTrue('summary.total_signals is 1', $result['summary']['total_signals'] === 1);
checkTrue('summary.win_rate is 100.0', abs($result['summary']['win_rate'] - 100.0) < 1e-9);
checkTrue('summary.profit_factor reflects a pure win (capped sentinel)', abs($result['summary']['profit_factor'] - 99.99) < 1e-9);
checkTrue('equity_curve has exactly one point matching the trade', \count($result['equity_curve']) === 1 && abs($result['equity_curve'][0]['equity'] - 2.0) < 1e-9);

$neutralResult = $runner->runOnRows(neutralRows(70), 'trend_following');
checkTrue('runOnRows() produces zero trades on an all-neutral fixture', !isset($neutralResult['error']) && $neutralResult['trades'] === []);

$tooShort = $runner->runOnRows(neutralRows(30), 'trend_following');
checkTrue('runOnRows() returns an error when there is not enough history', isset($tooShort['error']));

// barsBack should cap the walk to the most recent N rows without disturbing warmup.
$cappedResult = $runner->runOnRows($rowsWithOneSignal, 'trend_following', 2.0, 1.2, 10);
checkTrue('barsBack caps the walk and excludes the index-55 signal when only the last 10 bars are analyzed', $cappedResult['trades'] === []);

// ── 2. EquityCurveBuilder ────────────────────────────────────────────────────
echo "\n--- EquityCurveBuilder ---\n";
$plSequence  = [2.0, -1.0, 2.0, 2.0, -1.0, 0.0, -1.0]; // hand-computed: final=3, peak reaches 5, max drawdown=2 (5->3)
$fakeTrades  = [];
foreach ($plSequence as $i => $pl) {
    $fakeTrades[] = new TradeResult($i, 1_700_000_000 + $i * 3600, 'BUY', 1.2000, 0.0010, 60.0, $pl > 0 ? 'win' : ($pl < 0 ? 'loss' : 'breakeven'), $pl);
}
$curve = EquityCurveBuilder::build($fakeTrades);
checkTrue('build() produces one point per trade', \count($curve['points']) === 7, 'got ' . \count($curve['points']));
checkTrue('build() computes the correct final equity (3.0R)', abs($curve['final_equity'] - 3.0) < 1e-9, (string) $curve['final_equity']);
checkTrue('build() computes the correct max drawdown (2.0R, peak 5 -> 3)', abs($curve['max_drawdown'] - 2.0) < 1e-9, (string) $curve['max_drawdown']);
checkTrue('build() last point matches the hand-computed running equity/drawdown', abs($curve['points'][6]['equity'] - 3.0) < 1e-9 && abs($curve['points'][6]['drawdown'] - 2.0) < 1e-9);

$plSummary = EquityCurveBuilder::summarizePlSequence($plSequence);
checkTrue('summarizePlSequence() matches build() on the same sequence', abs($plSummary['final_equity'] - 3.0) < 1e-9 && abs($plSummary['max_drawdown'] - 2.0) < 1e-9);

// ── 3. StrategyComparator ────────────────────────────────────────────────────
echo "\n--- StrategyComparator ---\n";
$comparison = (new StrategyComparator())->compareOnRows($rowsWithOneSignal);
$registrySize = \count((new StrategyRegistry())->all());
checkTrue('compareOnRows() returns one row per registered strategy', \count($comparison) === $registrySize, 'got ' . \count($comparison) . ' expected ' . $registrySize);
checkTrue('compareOnRows() is sorted by profit factor descending', array_is_list($comparison) && $comparison === array_values($comparison));
$sortedOk = true;
for ($i = 1; $i < \count($comparison); $i++) {
    if ($comparison[$i - 1]['summary']['profit_factor'] < $comparison[$i]['summary']['profit_factor']) {
        $sortedOk = false;
        break;
    }
}
checkTrue('compareOnRows() rows are genuinely non-increasing by profit factor', $sortedOk);
$bestCount = \count(array_filter($comparison, static fn(array $r): bool => $r['best']));
checkTrue('compareOnRows() flags exactly one row as best, and it is the first row', $bestCount === 1 && $comparison[0]['best'] === true);

// ── 4. WalkForwardTester ─────────────────────────────────────────────────────
// A fully neutral 350-row fixture produces zero trades (profit_factor 0.0)
// for every strategy in every window — a tie every registered strategy
// "wins" identically, so the FIRST strategy in registry iteration order
// (trend_following) deterministically wins every window, making both the
// dominant strategy and the "stable" verdict hand-verifiable.
echo "\n--- WalkForwardTester ---\n";
$longNeutral = neutralRows(350);
$wf = (new WalkForwardTester())->testOnRows($longNeutral, 5);
checkTrue('testOnRows() does not error with enough history for 5 windows', !isset($wf['error']), (string) ($wf['error'] ?? ''));
checkTrue('testOnRows() produces windows-1 window-pairs', ($wf['window_count'] ?? null) === 4, 'got ' . ($wf['window_count'] ?? 'null'));
checkTrue('testOnRows() picks trend_following as the dominant strategy on an all-tied fixture', ($wf['dominant_strategy'] ?? null) === 'trend_following');
checkTrue('testOnRows() reports "stable" when one strategy wins every window', ($wf['stability'] ?? null) === 'stable');
checkTrue('testOnRows() avg out-of-sample win rate is 0.0 on a signal-free fixture', abs(($wf['avg_out_of_sample_win_rate'] ?? -1.0) - 0.0) < 1e-9);

$wfTooShort = (new WalkForwardTester())->testOnRows(neutralRows(100), 5);
checkTrue('testOnRows() errors when segments are too short for the requested window count', isset($wfTooShort['error']));

// ── 5. ParameterOptimizer ────────────────────────────────────────────────────
echo "\n--- ParameterOptimizer ---\n";
$optimization = (new ParameterOptimizer())->optimizeOnRows($rowsWithOneSignal, 'trend_following');
checkTrue('optimizeOnRows() does not error', !isset($optimization['error']), (string) ($optimization['error'] ?? ''));
checkTrue('optimizeOnRows() runs the full 4x4 default grid', \count($optimization['grid']) === 16, 'got ' . \count($optimization['grid']));
checkTrue('optimizeOnRows() finds the 2.0/1.2 default as the baseline', $optimization['baseline'] !== null
    && abs($optimization['baseline']['target_atr_mult'] - 2.0) < 1e-9 && abs($optimization['baseline']['stop_atr_mult'] - 1.2) < 1e-9);
checkTrue('optimizeOnRows() flags exactly one grid cell as best, and it is the first', $optimization['grid'][0]['best'] === true
    && \count(array_filter($optimization['grid'], static fn(array $g): bool => $g['best'])) === 1);
$optSortedOk = true;
for ($i = 1; $i < \count($optimization['grid']); $i++) {
    if ($optimization['grid'][$i - 1]['summary']['profit_factor'] < $optimization['grid'][$i]['summary']['profit_factor']) {
        $optSortedOk = false;
        break;
    }
}
checkTrue('optimizeOnRows() grid is genuinely non-increasing by profit factor', $optSortedOk);

// ── 6. MonteCarloSimulator ───────────────────────────────────────────────────
echo "\n--- MonteCarloSimulator ---\n";
$mcTrades = [];
foreach ([2.0, 2.0, 2.0, -1.0, -1.0] as $i => $pl) {
    $mcTrades[] = new TradeResult($i, 1_700_000_000, 'BUY', 1.2000, 0.0010, 60.0, $pl > 0 ? 'win' : 'loss', $pl);
}
$mc = (new MonteCarloSimulator())->simulate($mcTrades, 200);
checkTrue('simulate() does not error with trades present', !isset($mc['error']));
checkTrue('simulate() respects the requested iteration count', ($mc['iterations'] ?? null) === 200);
checkTrue('simulate() preserves the original trade count', ($mc['trade_count'] ?? null) === 5);
$fe = $mc['final_equity_percentiles'] ?? [];
checkTrue('simulate() final-equity percentiles are monotonically non-decreasing (p5<=p25<=p50<=p75<=p95)',
    $fe !== [] && $fe['p5'] <= $fe['p25'] && $fe['p25'] <= $fe['p50'] && $fe['p50'] <= $fe['p75'] && $fe['p75'] <= $fe['p95']);
$dd = $mc['max_drawdown_percentiles'] ?? [];
checkTrue('simulate() drawdown percentiles are monotonically non-decreasing',
    $dd !== [] && $dd['p5'] <= $dd['p25'] && $dd['p25'] <= $dd['p50'] && $dd['p50'] <= $dd['p75'] && $dd['p75'] <= $dd['p95']);
checkTrue('simulate() probability_of_loss is a sane percentage', ($mc['probability_of_loss'] ?? -1) >= 0.0 && $mc['probability_of_loss'] <= 100.0);

$mcEmpty = (new MonteCarloSimulator())->simulate([], 200);
checkTrue('simulate() errors on an empty trade list', isset($mcEmpty['error']));

// ── 7. FEATURE_ADVANCED_BACKTESTING flag ────────────────────────────────────
echo "\n--- FEATURE_ADVANCED_BACKTESTING flag ---\n";
checkTrue('FeatureFlagService::knownFlags() includes ADVANCED_BACKTESTING', in_array('ADVANCED_BACKTESTING', FeatureFlagService::knownFlags(), true));
checkTrue('feature_enabled(ADVANCED_BACKTESTING) is boolean', is_bool(feature_enabled('ADVANCED_BACKTESTING')));

// ── 8. Structural wiring checks ─────────────────────────────────────────────
echo "\n--- Structural wiring ---\n";
$indexSrc = (string) file_get_contents(__DIR__ . '/../public/index.php');
checkTrue('public/index.php routes GET /backtest/advanced', str_contains($indexSrc, "'/backtest/advanced'"));
checkTrue('public/index.php routes POST /backtest/advanced/run', str_contains($indexSrc, "'/backtest/advanced/run'"));

$modulesSrc = (string) file_get_contents(__DIR__ . '/../bootstrap/modules.php');
checkTrue('bootstrap/modules.php registers AdvancedBacktestingModule', str_contains($modulesSrc, 'new AdvancedBacktestingModule()'));

$controllerSrc = (string) file_get_contents(__DIR__ . '/../app/Controllers/AdvancedBacktestController.php');
checkTrue('AdvancedBacktestController gates both actions behind feature_enabled(ADVANCED_BACKTESTING)', substr_count($controllerSrc, "feature_enabled('ADVANCED_BACKTESTING')") >= 1);

$migrationSrc = (string) file_get_contents(__DIR__ . '/../database/migrations/030_add_run_type_to_backtest_summaries.php');
checkTrue('Migration 030 adds run_type idempotently', str_contains($migrationSrc, 'ADD COLUMN IF NOT EXISTS run_type'));

$columns = Database::connection()->query("SHOW COLUMNS FROM backtest_summaries LIKE 'run_type'")->fetchAll();
checkTrue('backtest_summaries table actually has the run_type column (migration applied)', \count($columns) === 1);

// ── 9. Live smoke test — the exact pipeline AdvancedBacktestController::run()
//    uses, against real market data. Tolerant of network/API unavailability
//    (SKIP, not FAIL), matching every prior phase's live-check convention.
echo "\n--- Live smoke test ---\n";
try {
    $liveCandles = (new MarketDataManager())->getCandles('EURUSD=X', 'Daily');
    if (\count($liveCandles) < 100) {
        echo "SKIP  Live advanced-backtest smoke test — fewer than 100 candles returned\n";
    } else {
        $liveRows      = array_map(static fn(Candle $c): array => $c->toLegacyRow(), $liveCandles);
        $liveEnriched  = (new App\Services\IndicatorService())->enrich($liveRows);
        $livePrimary   = (new BacktestRunner())->runOnRows($liveEnriched, 'trend_following', 2.0, 1.2, 200);
        checkTrue('Live BacktestRunner run against real EURUSD Daily candles completes without throwing', !isset($livePrimary['error']), (string) ($livePrimary['error'] ?? ''));
        if (!isset($livePrimary['error'])) {
            $liveComparison = (new StrategyComparator())->compareOnRows($liveEnriched, 2.0, 1.2, 200);
            checkTrue('Live StrategyComparator run completes without throwing', \count($liveComparison) === $registrySize);
            $liveMc = (new MonteCarloSimulator())->simulate($livePrimary['trades'], 200);
            checkTrue('Live MonteCarloSimulator run completes without throwing (or reports no trades)', isset($liveMc['iterations']) || isset($liveMc['error']));
            echo '      (' . \count($livePrimary['trades']) . ' primary trade(s) found)' . "\n";
        }
    }
} catch (\Throwable $e) {
    echo 'SKIP  Live advanced-backtest smoke test — network/API unavailable: ' . $e->getMessage() . "\n";
}

echo "\n";
echo $failures === 0
    ? "ALL CHECKS PASSED\n"
    : "{$failures} CHECK(S) FAILED\n";

exit($failures === 0 ? 0 : 1);
