<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Backtesting\BacktestRunner;
use App\Backtesting\MonteCarloSimulator;
use App\Backtesting\ParameterOptimizer;
use App\Backtesting\StrategyComparator;
use App\Backtesting\WalkForwardTester;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\RateLimiter;
use App\MarketData\Manager\MarketDataManager;
use App\MarketData\Manager\TimeframeCatalog;
use App\MarketData\Models\Candle;
use App\Models\BacktestSummary;
use App\Services\AiAdvancedBacktestExplanationService;
use App\Services\IndicatorService;
use App\Strategies\StrategyRegistry;

final class AdvancedBacktestController extends Controller
{
    private const VALID_BARS_BACK = [100, 200, 300, 500];

    public function index(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        $this->view('backtest/advanced', [
            'title'      => 'Advanced Backtesting',
            'csrf'       => Csrf::token(),
            'markets'    => configured_markets(),
            'timeframes' => TimeframeCatalog::labels(),
            'strategies' => array_keys((new StrategyRegistry())->all()),
            'barsOptions'=> self::VALID_BARS_BACK,
            'result'     => null,
        ]);
    }

    public function run(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        $validSymbols    = array_column(configured_markets(), 'symbol');
        $validStrategies = array_keys((new StrategyRegistry())->all());

        $symbol    = trim((string) ($_POST['symbol']    ?? 'EURUSD=X'));
        $timeframe = trim((string) ($_POST['timeframe'] ?? 'Daily'));
        $barsBack  = (int) ($_POST['bars_back'] ?? 200);
        $strategy  = trim((string) ($_POST['strategy']  ?? 'compare_all'));

        if (!\in_array($symbol, $validSymbols, true))               $symbol    = 'EURUSD=X';
        if (!TimeframeCatalog::has($timeframe))                     $timeframe = 'Daily';
        if (!\in_array($barsBack, self::VALID_BARS_BACK, true))     $barsBack  = 200;
        if ($strategy !== 'compare_all' && !\in_array($strategy, $validStrategies, true)) {
            $strategy = 'compare_all';
        }

        $label          = market_label_from_symbol($symbol);
        $primaryName    = $strategy === 'compare_all' ? 'trend_following' : $strategy;

        // Runs a 1000-iteration Monte Carlo simulation, a walk-forward test,
        // and a parameter optimizer synchronously, plus one AI explanation
        // call — the most CPU/AI-cost-expensive endpoint in this app.
        if (!RateLimiter::allow('advanced_backtest_run', 6, 300)) {
            $this->view('backtest/advanced', [
                'title'      => 'Advanced Backtesting',
                'csrf'       => Csrf::token(),
                'markets'    => configured_markets(),
                'timeframes' => TimeframeCatalog::labels(),
                'strategies' => $validStrategies,
                'barsOptions'=> self::VALID_BARS_BACK,
                'result'     => ['error' => 'Too many backtest requests — please wait a few minutes and try again.'],
            ]);
            return;
        }

        // ── Fetch + enrich ONCE, every sub-analysis below reuses these rows ──
        try {
            $candles  = (new MarketDataManager())->getCandles($symbol, $timeframe);
            $rows     = array_map(static fn(Candle $c): array => $c->toLegacyRow(), $candles);
            $enriched = (new IndicatorService())->enrich($rows);
        } catch (\Throwable $e) {
            $this->view('backtest/advanced', [
                'title'      => 'Advanced Backtesting',
                'csrf'       => Csrf::token(),
                'markets'    => configured_markets(),
                'timeframes' => TimeframeCatalog::labels(),
                'strategies' => $validStrategies,
                'barsOptions'=> self::VALID_BARS_BACK,
                'result'     => ['error' => 'Data fetch failed: ' . $e->getMessage()],
            ]);
            return;
        }

        $runner = new BacktestRunner();

        $primary      = $runner->runOnRows($enriched, $primaryName, 2.0, 1.2, $barsBack);
        $comparison   = (new StrategyComparator())->compareOnRows($enriched, 2.0, 1.2, $barsBack);
        $walkForward  = (new WalkForwardTester())->testOnRows($enriched, 5, 2.0, 1.2);
        $optimization = (new ParameterOptimizer())->optimizeOnRows($enriched, $primaryName, null, null, $barsBack);
        $monteCarlo   = isset($primary['error'])
            ? ['error' => $primary['error']]
            : (new MonteCarloSimulator())->simulate($primary['trades'], 1000);

        $combined = [
            'symbol'       => $symbol,
            'label'        => $label,
            'timeframe'    => $timeframe,
            'bars_back'    => $barsBack,
            'primary'      => ['strategy' => $primaryName, 'summary' => $primary['summary'] ?? [], 'trades' => $primary['trades'] ?? [], 'equity_curve' => $primary['equity_curve'] ?? []],
            'comparison'   => $comparison,
            'walk_forward' => $walkForward,
            'monte_carlo'  => $monteCarlo,
            'optimization' => $optimization,
        ];

        $aiExplanation = null;
        if (!isset($primary['error'])) {
            try {
                $aiExplanation = (new AiAdvancedBacktestExplanationService())->generate($combined);
            } catch (\Throwable $e) {
                error_log('[AdvancedBacktestController] AI explanation error: ' . $e->getMessage());
            }

            try {
                BacktestSummary::create([
                    'symbol'       => $symbol,
                    'timeframe'    => $timeframe,
                    'period_days'  => $barsBack,
                    'run_type'     => 'advanced',
                    'decision'     => $aiExplanation['decision']   ?? 'WAIT',
                    'confidence'   => $aiExplanation['confidence'] ?? 0,
                    'summary_text' => $aiExplanation['summary']    ?? '',
                    'stats_json'   => json_encode($this->serializable($combined), JSON_UNESCAPED_UNICODE) ?: '{}',
                    'provider'     => $aiExplanation['provider']   ?? 'rule_based',
                ]);
            } catch (\Throwable $e) {
                error_log('[AdvancedBacktestController] Persistence error: ' . $e->getMessage());
            }

            try {
                $pf = $primary['summary']['profit_factor'] ?? 'n/a';
                $wr = $primary['summary']['win_rate'] ?? 'n/a';
                (new \App\Services\TelegramNotifier())->sendToAdmin(
                    "Backtest completed — {$label} ({$primaryName}, {$timeframe})\n"
                    . "Win rate: {$wr}% · Profit factor: {$pf}"
                );
            } catch (\Throwable $e) {
                error_log('[AdvancedBacktestController] Telegram alert failed: ' . $e->getMessage());
            }
        }

        $this->view('backtest/advanced', [
            'title'      => 'Advanced Backtesting',
            'csrf'       => Csrf::token(),
            'markets'    => configured_markets(),
            'timeframes' => TimeframeCatalog::labels(),
            'strategies' => $validStrategies,
            'barsOptions'=> self::VALID_BARS_BACK,
            'result'     => $combined,
            'aiExplanation' => $aiExplanation,
            'selected'   => compact('symbol', 'timeframe', 'barsBack', 'strategy', 'label'),
            // Only used for THIS request's trade-replay candle windows — deliberately
            // not part of $combined/stats_json (too large to persist, and a past run's
            // replay would need live data at view-time anyway, out of scope for now).
            'enrichedRows' => $enriched,
        ]);
    }

    /** TradeResult objects inside $combined aren't JSON-serializable directly — flatten to arrays for storage. */
    private function serializable(array $combined): array
    {
        $combined['primary']['trades'] = array_map(
            static fn($t) => \is_object($t) ? $t->toArray() : $t,
            $combined['primary']['trades'] ?? []
        );
        return $combined;
    }

    private function ensureFeatureEnabled(): bool
    {
        if (feature_enabled('ADVANCED_BACKTESTING')) {
            return true;
        }

        $_SESSION['flash_error'] = 'Advanced Backtesting is not enabled on this installation yet.';
        redirect(url('dashboard'));
    }
}
