<?php
declare(strict_types=1);

/**
 * Deterministic regression checks for the Phase 2 foundation refactor.
 * Does not depend on Yahoo Finance (that path was verified live, separately,
 * during implementation). The AIManager check below will make one real
 * Claude/OpenAI call if CLAUDE_API_KEY/OPENAI_API_KEY are configured in this
 * environment's .env — that's expected and cheap (a single short call); it
 * returns null cleanly instead if no key is configured. Run with:
 *
 *   php bin/verify-phase2-foundation.php
 *
 * Exits 0 if all checks pass, 1 otherwise.
 */

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\AI\AIManager;
use App\AI\PromptLoader;
use App\Config\AIRouting;
use App\Decision\DecisionResult;
use App\Decision\ScoreEngine;
use App\Explanation\ExplanationEngine;
use App\Indicators\IndicatorRegistry;
use App\Modules\ModuleRegistry;
use App\Services\FeatureFlagService;
use App\Services\IndicatorService;
use App\Services\TradingSessionService;
use App\Strategies\TrendFollowingStrategy;

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

// ── 1. IndicatorService produces the expected legacy keys ──────────────────
mt_srand(7);
$fixture = [];
$price   = 1.2500;
for ($i = 0; $i < 70; $i++) {
    $close    = $price + (mt_rand(-40, 40) / 100000);
    $fixture[] = [
        'timestamp' => 1700000000 + $i * 3600,
        'open'      => $price,
        'high'      => max($price, $close) + 0.0002,
        'low'       => min($price, $close) - 0.0002,
        'close'     => $close,
        'volume'    => 0.0,
    ];
    $price = $close;
}

$enriched = (new IndicatorService())->enrich($fixture);
checkTrue('IndicatorService::enrich() returns rows', count($enriched) > 0);
$expectedKeys = ['sma_20', 'sma_50', 'ema_12', 'ema_26', 'rsi_14', 'macd_line', 'macd_signal', 'macd_hist', 'atr_14'];
$hasAllKeys   = !empty($enriched) && count(array_diff($expectedKeys, array_keys($enriched[0]))) === 0;
checkTrue('IndicatorService output has all 5 legacy indicator key sets', $hasAllKeys);

$defaultRegistryCount = count(IndicatorRegistry::legacyDefaults()->all());
checkTrue('IndicatorRegistry::legacyDefaults() has exactly 7 indicator instances (5 legacy indicators, MACD/ATR counted once each)', $defaultRegistryCount === 7);

// ── 2. TradingSessionService matches the pre-refactor inline formula ───────
$tz = new DateTimeZone('Africa/Dar_es_Salaam');
$sessionService = new TradingSessionService();

$cases = [
    ['2026-07-20 10:30:00', 'london'],
    ['2026-07-20 13:01:00', 'off'],
    ['2026-07-20 15:45:00', 'new_york'],
    ['2026-07-20 20:00:00', 'off'],
];
foreach ($cases as [$time, $expected]) {
    $dt     = new DateTimeImmutable($time, $tz);
    $actual = $sessionService->legacySessionName($dt);
    checkTrue("TradingSessionService::legacySessionName({$time}) === '{$expected}'", $actual === $expected, "got '{$actual}'");
}

$friday   = new DateTimeImmutable('2026-07-24 12:00:00', $tz); // a Friday
$tuesday  = new DateTimeImmutable('2026-07-21 12:00:00', $tz); // a Tuesday
checkTrue('maxSignalsForDay(Friday) uses MAX_SIGNALS_FRIDAY', $sessionService->maxSignalsForDay($friday) === (int) env('MAX_SIGNALS_FRIDAY', 1));
checkTrue('maxSignalsForDay(Tuesday) uses MAX_SIGNALS_PER_DAY', $sessionService->maxSignalsForDay($tuesday) === (int) env('MAX_SIGNALS_PER_DAY', 2));

// ── 3. ScoreEngine weighted math ────────────────────────────────────────────
$scoreResult = (new ScoreEngine())->score([
    'technical' => 100.0, 'momentum' => 100.0, 'news' => 100.0,
    'calendar' => 100.0, 'backtest' => 100.0, 'ai' => 100.0,
]);
checkTrue('ScoreEngine::score() with all factors at 100 yields confidence 100', abs($scoreResult->confidence - 100.0) < 0.01, (string) $scoreResult->confidence);

$scoreResultZero = (new ScoreEngine())->score([
    'technical' => 0.0, 'momentum' => 0.0, 'news' => 0.0,
    'calendar' => 0.0, 'backtest' => 0.0, 'ai' => 0.0,
]);
checkTrue('ScoreEngine::score() with all factors at 0 yields confidence 0', abs($scoreResultZero->confidence - 0.0) < 0.01, (string) $scoreResultZero->confidence);

// ── 4. AIManager returns null cleanly when a provider has no key ───────────
$response = (new AIManager())->generateWith('claude', 'system', 'user', ['service' => 'verify_harness']);
checkTrue(
    'AIManager::generateWith() does not throw and returns AIResponse|null',
    $response === null || $response instanceof \App\AI\AIResponse
);

// ── 5. Every prompt template loads without error ───────────────────────────
$templates = ['market_summary', 'trade_decision', 'backtest_summary', 'daily_report', 'vision_analysis', 'trade_coach', 'news_summary'];
foreach ($templates as $name) {
    try {
        $prompt = PromptLoader::load($name);
        checkTrue("PromptLoader::load('{$name}') returns non-empty system+user", $prompt['system'] !== '' && $prompt['user'] !== '');
    } catch (\Throwable $e) {
        checkTrue("PromptLoader::load('{$name}')", false, $e->getMessage());
    }
}

// ── 6. signal_events unique key includes timeframe (Phase 2.1, Phase 1) ────
$pdo       = \App\Core\Database::connection();
$indexRows = $pdo->query("SHOW INDEX FROM signal_events WHERE Key_name = 'uq_signal_daily_unique'")->fetchAll(PDO::FETCH_ASSOC);
usort($indexRows, static fn($a, $b) => $a['Seq_in_index'] <=> $b['Seq_in_index']);
$indexCols = array_column($indexRows, 'Column_name');
checkTrue(
    "signal_events unique key is (signal_date, market_label, timeframe, session_name, action)",
    $indexCols === ['signal_date', 'market_label', 'timeframe', 'session_name', 'action'],
    'got: ' . implode(',', $indexCols)
);

// ── 7. Strategy Engine matches the pre-Phase-2.1 inline scoring formula ────
$strategyLatest = [
    'close' => 1.15, 'sma_20' => 1.12, 'sma_50' => 1.10, 'rsi_14' => 25.0, 'macd_hist' => 0.001, 'atr_14' => 0.002,
];
$strategyPrevious = ['macd_hist' => -0.001];
$strategyResult = (new TrendFollowingStrategy())->evaluate([$strategyPrevious, $strategyLatest], ['score' => 20.0]);
checkTrue(
    'TrendFollowingStrategy: strong bullish structure + oversold RSI + MACD turn + bullish sentiment => BUY',
    $strategyResult['action'] === 'BUY',
    json_encode($strategyResult)
);

// ── 8. Explanation Engine renders the pre-Phase-4 sentences from facts ─────
$calendarFacts = [
    'branch' => 'calendar_blocked', 'event_title' => 'NFP Release', 'trend' => 'neutral', 'momentum' => 'neutral',
    'momentum_aligned' => true, 'news_score' => 0.0, 'ai_bias' => 'mixed', 'ai_confidence' => 50.0,
    'backtest_win_rate' => null, 'calendar_blocked' => true, 'direction' => null,
];
$calendarDecision = new DecisionResult('WAIT', 20.0, $calendarFacts, [], 'high', [], new DateTimeImmutable('now'));
$calendarExplanation = (new ExplanationEngine())->explain($calendarDecision);
checkTrue(
    'ExplanationEngine renders the calendar-block sentence unchanged',
    $calendarExplanation->reasoning === ['Economic calendar blocks new entries: NFP Release.']
);
checkTrue(
    'ExplanationEngine produces all 5 new explanation fields',
    $calendarExplanation->tradeExplanation !== '' && $calendarExplanation->confidenceExplanation !== ''
    && $calendarExplanation->riskExplanation !== '' && $calendarExplanation->conflictingSignalExplanation !== ''
    && $calendarExplanation->educationalExplanation !== ''
);

// ── 9. Feature flags default disabled, override via $_ENV works ───────────
// This installation's config/.env sets FEATURE_AI_VISION=true (the feature is
// actually enabled here) via Dotenv::createImmutable(), which populates BOTH
// $_ENV and $_SERVER — so the "defaults to false" checks must temporarily
// clear both to test FeatureFlagService's coded default rather than this
// environment's chosen value — restored immediately after.
$originalAiVisionEnv    = $_ENV['FEATURE_AI_VISION']    ?? null;
$originalAiVisionServer = $_SERVER['FEATURE_AI_VISION'] ?? null;
unset($_ENV['FEATURE_AI_VISION'], $_SERVER['FEATURE_AI_VISION']);
checkTrue('feature_enabled(AI_VISION) defaults to false', feature_enabled('AI_VISION') === false);
checkTrue('feature_enabled() is case-insensitive', feature_enabled('ai_vision') === false);
checkTrue('feature_enabled() defaults unknown flags to true', feature_enabled('SOME_UNKNOWN_FLAG_XYZ') === true);
$_ENV['FEATURE_AI_VISION'] = 'true';
checkTrue('feature_enabled(AI_VISION) reads FEATURE_AI_VISION override', feature_enabled('AI_VISION') === true);
unset($_ENV['FEATURE_AI_VISION']);
if ($originalAiVisionEnv !== null)    { $_ENV['FEATURE_AI_VISION']    = $originalAiVisionEnv; }
if ($originalAiVisionServer !== null) { $_SERVER['FEATURE_AI_VISION'] = $originalAiVisionServer; }
// 10 as of Phase 3.1 — RISK_MANAGEMENT (2.5), SMART_MONEY (3.0), and
// ADVANCED_BACKTESTING (3.1) were added after this check was originally
// written for 8; see bin/verify-phase2-5-risk.php /
// bin/verify-phase3-0-smartmoney.php / bin/verify-phase3-1-advanced-backtesting.php for their own checks.
checkTrue('FeatureFlagService::all() returns exactly the 10 named flags', count(FeatureFlagService::knownFlags()) === 10);

// ── 10. AI routing resolves task-specific and default providers ───────────
checkTrue(
    'AIRouting::providerForTask() falls back to AI_PRIMARY_PROVIDER when no rule is set',
    AIRouting::providerForTask('unrouted_task_xyz') === \App\Config\AI::primaryProvider()
);
$_ENV['AI_ROUTE_VISION_TEST'] = 'claude';
checkTrue('AIRouting::providerForTask() honors an explicit AI_ROUTE_* rule', AIRouting::providerForTask('vision_test') === 'claude');
unset($_ENV['AI_ROUTE_VISION_TEST']);

// ── 11. Module Registry populates on bootstrap ─────────────────────────────
$modules = ModuleRegistry::all();
// 16 as of Phase 3.1 — MultiTimeframeModule (Phase 2.3), RiskManagementModule
// (Phase 2.5), TradeJournalModule (Phase 2.6), MarketIntelligenceModule
// (Phase 2.7), SmartMoneyModule (Phase 3.0), and AdvancedBacktestingModule
// (Phase 3.1) were added after this check was originally written for 10;
// see bin/verify-phase2-3-mtf.php / bin/verify-phase2-5-risk.php /
// bin/verify-phase2-6-journal.php / bin/verify-phase2-7-intelligence.php /
// bin/verify-phase3-0-smartmoney.php / bin/verify-phase3-1-advanced-backtesting.php for their own checks.
checkTrue('ModuleRegistry has exactly 16 registered modules', count($modules) === 16);
checkTrue(
    'ModuleRegistry contains all 16 named modules',
    count(array_diff(
        ['core', 'scanner', 'calendar', 'news', 'backtesting', 'reports', 'ai', 'vision', 'trade_coach', 'learning', 'multi_timeframe', 'risk_management', 'trade_journal', 'market_intelligence', 'smart_money', 'advanced_backtesting'],
        array_keys($modules)
    )) === 0
);

echo "\n";
echo $failures === 0
    ? "ALL CHECKS PASSED\n"
    : "{$failures} CHECK(S) FAILED\n";

exit($failures === 0 ? 0 : 1);
