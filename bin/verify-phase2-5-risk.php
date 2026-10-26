<?php
declare(strict_types=1);

/**
 * Deterministic regression checks for the Phase 2.5 Risk Management Suite
 * (PipMath, CurrencyPair, StopLossCalculator, TakeProfitCalculator,
 * RiskRewardCalculator, DailyRiskLimitChecker, WeeklyRiskLimitChecker,
 * DrawdownGuard, ExposureTracker, CorrelationAnalyzer, RiskAssessmentEngine),
 * plus LevelPicker (added for the AI Vision + Risk Management integration —
 * see VisionController::buildRiskGuidance()). The limit-checker/drawdown/exposure checks hit the real signal_events
 * table (read-only queries only — no rows are inserted or mutated), so
 * results are compared against the actual current row counts rather than
 * hardcoded numbers. Run with:
 *
 *   php bin/verify-phase2-5-risk.php
 *
 * Exits 0 if all checks pass, 1 otherwise.
 */

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\Models\SignalEvent;
use App\Risk\CorrelationAnalyzer;
use App\Risk\CurrencyPair;
use App\Risk\DailyRiskLimitChecker;
use App\Risk\DrawdownGuard;
use App\Risk\ExposureTracker;
use App\Risk\LevelPicker;
use App\Risk\PipMath;
use App\Risk\RiskAssessmentEngine;
use App\Risk\RiskRewardCalculator;
use App\Risk\StopLossCalculator;
use App\Risk\TakeProfitCalculator;
use App\Risk\WeeklyRiskLimitChecker;
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

// ── 1. PipMath / CurrencyPair ───────────────────────────────────────────────
checkTrue('PipMath::pipSize() is 0.01 for a JPY-quoted pair', abs(PipMath::pipSize('USD/JPY') - 0.01) < 1e-9);
checkTrue('PipMath::pipSize() is 0.0001 for a non-JPY pair', abs(PipMath::pipSize('EUR/USD') - 0.0001) < 1e-9);
checkTrue(
    'PipMath::priceDistanceToPips() round-trips with pipsToPriceDistance()',
    abs(PipMath::priceDistanceToPips(PipMath::pipsToPriceDistance(20.0, 'EUR/USD'), 'EUR/USD') - 20.0) < 1e-6
);

$pair = CurrencyPair::parse('EUR/USD');
checkTrue('CurrencyPair::parse() splits base/quote correctly', $pair->base === 'EUR' && $pair->quote === 'USD');

$buyVector  = CurrencyPair::exposureVector('EUR/USD', 'BUY');
$sellVector = CurrencyPair::exposureVector('EUR/USD', 'SELL');
checkTrue('CurrencyPair::exposureVector() BUY EUR/USD is +1 EUR / -1 USD', $buyVector === ['EUR' => 1, 'USD' => -1]);
checkTrue('CurrencyPair::exposureVector() SELL EUR/USD is -1 EUR / +1 USD', $sellVector === ['EUR' => -1, 'USD' => 1]);

// ── 2. StopLossCalculator ────────────────────────────────────────────────────
$stopBuy = (new StopLossCalculator())->fromAtr(1.2000, 'BUY', 0.0020, 'EUR/USD', 1.5);
checkTrue('StopLossCalculator::fromAtr() places a BUY stop below entry', $stopBuy['stop_price'] < 1.2000, (string) $stopBuy['stop_price']);
checkTrue('StopLossCalculator::fromAtr() BUY stop distance is ATR x multiple', abs($stopBuy['stop_distance'] - 0.0030) < 1e-6);

$stopSell = (new StopLossCalculator())->fromAtr(1.2000, 'SELL', 0.0020, 'EUR/USD', 1.5);
checkTrue('StopLossCalculator::fromAtr() places a SELL stop above entry', $stopSell['stop_price'] > 1.2000, (string) $stopSell['stop_price']);

$stopFromPips = (new StopLossCalculator())->fromPips(1.2000, 'BUY', 20.0, 'EUR/USD');
checkTrue('StopLossCalculator::fromPips() converts 20 pips to a 0.0020 price distance for EUR/USD', abs($stopFromPips['stop_distance'] - 0.0020) < 1e-6);

// ── 3. TakeProfitCalculator ──────────────────────────────────────────────────
$tpLevels = (new TakeProfitCalculator())->levels(1.2000, 1.1970, 'BUY', [1.0, 2.0]);
checkTrue('TakeProfitCalculator::levels() computes 1R above entry for a BUY', abs($tpLevels['1R'] - 1.2030) < 1e-6, (string) $tpLevels['1R']);
checkTrue('TakeProfitCalculator::levels() computes 2R above entry for a BUY', abs($tpLevels['2R'] - 1.2060) < 1e-6, (string) $tpLevels['2R']);

$achieved = (new TakeProfitCalculator())->achievedRMultiple(1.2000, 1.1970, 1.2060);
checkTrue('TakeProfitCalculator::achievedRMultiple() reports 2.0R for a target twice the risk unit away', abs($achieved - 2.0) < 1e-6, (string) $achieved);

// ── 4. RiskRewardCalculator ──────────────────────────────────────────────────
$rrGood = (new RiskRewardCalculator())->evaluate(1.2000, 1.1970, 1.2060, 1.5);
checkTrue('RiskRewardCalculator::evaluate() flags a 2:1 ratio as meeting a 1.5 minimum', $rrGood['meets_minimum'] === true, (string) $rrGood['ratio']);

$rrBad = (new RiskRewardCalculator())->evaluate(1.2000, 1.1970, 1.2010, 1.5);
checkTrue('RiskRewardCalculator::evaluate() flags a ~0.33:1 ratio as below a 1.5 minimum', $rrBad['meets_minimum'] === false, (string) $rrBad['ratio']);

// ── 5. Daily / weekly limit checkers (read-only against the real table) ────
$today = now_eat()->format('Y-m-d');
$expectedTodayCount = SignalEvent::countByDate($today);
$dailyCheck = (new DailyRiskLimitChecker())->check($today, 1.0);
checkTrue(
    'DailyRiskLimitChecker::check() trades_today matches SignalEvent::countByDate()',
    $dailyCheck['trades_today'] === $expectedTodayCount
);
checkTrue(
    'DailyRiskLimitChecker::check() projects (trades_today + 1) x risk%',
    abs($dailyCheck['projected_daily_risk_percent'] - (($expectedTodayCount + 1) * 1.0)) < 1e-6
);

$weekStart = (new DateTimeImmutable($today))->modify('monday this week')->format('Y-m-d');
$weekEnd   = (new DateTimeImmutable($today))->modify('sunday this week')->format('Y-m-d');
$expectedWeekCount = SignalEvent::countByDateRange($weekStart, $weekEnd);
$weeklyCheck = (new WeeklyRiskLimitChecker())->check($today, 1.0);
checkTrue(
    'WeeklyRiskLimitChecker::check() trades_this_week matches SignalEvent::countByDateRange()',
    $weeklyCheck['trades_this_week'] === $expectedWeekCount
);
checkTrue('WeeklyRiskLimitChecker::check() week_start is a Monday', (new DateTimeImmutable($weeklyCheck['week_start']))->format('D') === 'Mon');
checkTrue('WeeklyRiskLimitChecker::check() week_end is a Sunday', (new DateTimeImmutable($weeklyCheck['week_end']))->format('D') === 'Sun');

// A deliberately huge proposed risk% must trip the exceeded flag regardless of today's real count.
$forcedExceeded = (new DailyRiskLimitChecker())->check($today, 500.0);
checkTrue('DailyRiskLimitChecker::check() flags exceeded=true for an absurdly large risk%', $forcedExceeded['exceeded'] === true);

// ── 6. DrawdownGuard (read-only against the real table) ─────────────────────
$expectedOutcomes = SignalEvent::recentOutcomes(10);
$expectedStreak = 0;
foreach ($expectedOutcomes as $o) {
    if ($o !== 'loss') { break; }
    $expectedStreak++;
}
$drawdown = (new DrawdownGuard())->assess(10);
checkTrue('DrawdownGuard::assess() consecutive_losses matches a manual recount of SignalEvent::recentOutcomes()', $drawdown['consecutive_losses'] === $expectedStreak, (string) $drawdown['consecutive_losses']);
checkTrue('DrawdownGuard::assess() message is non-empty', trim($drawdown['message']) !== '');

// ── 7. ExposureTracker / CorrelationAnalyzer (read-only against the real table) ─
$openPositions = SignalEvent::openPositions();
$expectedNet = [];
foreach ($openPositions as $p) {
    foreach (CurrencyPair::exposureVector((string) $p['market_label'], (string) $p['action']) as $ccy => $delta) {
        $expectedNet[$ccy] = ($expectedNet[$ccy] ?? 0) + $delta;
    }
}
$exposure = (new ExposureTracker())->assess();
checkTrue('ExposureTracker::assess() open_position_count matches SignalEvent::openPositions()', $exposure['open_position_count'] === count($openPositions));
checkTrue('ExposureTracker::assess() net_exposure matches a manual recount', $exposure['net_exposure'] == $expectedNet);

// CorrelationAnalyzer must not throw and must return an array of strings regardless of live state.
$correlationWarnings = (new CorrelationAnalyzer())->warningsFor('EUR/USD', 'BUY');
checkTrue('CorrelationAnalyzer::warningsFor() returns an array', is_array($correlationWarnings));

// ── 8. RiskAssessmentEngine composition ─────────────────────────────────────
$assessment = (new RiskAssessmentEngine())->assess(
    marketLabel: 'EUR/USD',
    direction: 'BUY',
    balance: 1000.0,
    riskPercent: 1.0,
    entryPrice: 1.2000,
    stopPrice: 1.1970,
    targetPrice: 1.2060,
);
checkTrue('RiskAssessmentEngine::assess() carries through market/direction', $assessment->marketLabel === 'EUR/USD' && $assessment->direction === 'BUY');
checkTrue('RiskAssessmentEngine::assess() computes a positive risk amount', $assessment->positionSizing['risk_amount'] > 0.0);
checkTrue('RiskAssessmentEngine::assess() risk/reward is present when a target is given', $assessment->riskReward !== null && $assessment->riskReward['meets_minimum'] === true);
checkTrue('RiskAssessmentEngine::assess() overall_guidance is non-empty', trim($assessment->overallGuidance) !== '');

$assessmentNoTarget = (new RiskAssessmentEngine())->assess(
    marketLabel: 'EUR/USD',
    direction: 'BUY',
    balance: 1000.0,
    riskPercent: 1.0,
    entryPrice: 1.2000,
    stopPips: 20.0,
);
checkTrue('RiskAssessmentEngine::assess() risk/reward is null when no target is given', $assessmentNoTarget->riskReward === null);
checkTrue('RiskAssessmentEngine::assess() still produces take-profit levels without a target', count($assessmentNoTarget->takeProfitLevels) === 3);

$threwOnMissingStop = false;
try {
    (new RiskAssessmentEngine())->assess('EUR/USD', 'BUY', 1000.0, 1.0, 1.2000);
} catch (\InvalidArgumentException $e) {
    $threwOnMissingStop = true;
}
checkTrue('RiskAssessmentEngine::assess() requires a stop price, stop pips, or ATR value', $threwOnMissingStop);

$assessmentArray = $assessment->toArray();
checkTrue(
    'RiskAssessment::toArray() contains all Risk Management keys',
    array_diff(
        ['market_label', 'direction', 'entry_price', 'position_sizing', 'stop_loss', 'take_profit_levels',
         'risk_reward', 'daily_limit', 'weekly_limit', 'drawdown', 'exposure', 'correlation_warnings', 'overall_guidance', 'generated_at'],
        array_keys($assessmentArray)
    ) === []
);

// ── 9. LevelPicker (AI Vision + Risk Management integration) ───────────────
checkTrue(
    'LevelPicker::numericLevels() filters non-numeric and non-positive entries',
    LevelPicker::numericLevels([1.2000, '1.1950', 0, -1, 'n/a', null, 1.2100]) === [1.2, 1.195, 1.21]
);
checkTrue('LevelPicker::numericLevels() returns [] for a non-array input', LevelPicker::numericLevels(null) === []);

[$buyStop, $buyTarget] = LevelPicker::nearestStopAndTarget('BUY', 1.2000, [1.1950, 1.1900], [1.2050, 1.2100]);
checkTrue('LevelPicker::nearestStopAndTarget() BUY picks the nearest support below entry as stop', abs($buyStop - 1.1950) < 1e-9, (string) $buyStop);
checkTrue('LevelPicker::nearestStopAndTarget() BUY picks the nearest resistance above entry as target', abs($buyTarget - 1.2050) < 1e-9, (string) $buyTarget);

[$sellStop, $sellTarget] = LevelPicker::nearestStopAndTarget('SELL', 1.2000, [1.1950, 1.1900], [1.2050, 1.2100]);
checkTrue('LevelPicker::nearestStopAndTarget() SELL picks the nearest resistance above entry as stop', abs($sellStop - 1.2050) < 1e-9, (string) $sellStop);
checkTrue('LevelPicker::nearestStopAndTarget() SELL picks the nearest support below entry as target', abs($sellTarget - 1.1950) < 1e-9, (string) $sellTarget);

[$noStop, $noTarget] = LevelPicker::nearestStopAndTarget('BUY', 1.2000, [1.2050], [1.1950]);
checkTrue(
    'LevelPicker::nearestStopAndTarget() returns null when no level sits on the correct side of entry',
    $noStop === null && $noTarget === null
);

// ── 10. Feature flag defaults ───────────────────────────────────────────────
checkTrue('feature_enabled(RISK_MANAGEMENT) is boolean', is_bool(feature_enabled('RISK_MANAGEMENT')));
checkTrue('FeatureFlagService::knownFlags() includes RISK_MANAGEMENT', in_array('RISK_MANAGEMENT', FeatureFlagService::knownFlags(), true));

echo "\n";
echo $failures === 0
    ? "ALL CHECKS PASSED\n"
    : "{$failures} CHECK(S) FAILED\n";

exit($failures === 0 ? 0 : 1);
