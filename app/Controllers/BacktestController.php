<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Learning\StrategyLearningService;
use App\Models\BacktestSummary;
use App\Services\AiBacktestSummaryService;
use App\Services\BacktestService;

final class BacktestController extends Controller
{
    public function index(): void
    {
        require_login();
        $this->view('backtest/index', [
            'title'      => 'Backtest',
            'csrf'       => Csrf::token(),
            'markets'    => configured_markets(),
            'result'     => null,
            'aiSummary'  => null,
        ]);
    }

    public function run(): void
    {
        require_login();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        $symbol    = trim((string) ($_POST['symbol']    ?? 'EURUSD=X'));
        $timeframe = trim((string) ($_POST['timeframe'] ?? '60m'));
        $days      = (int) ($_POST['days'] ?? 60);

        $validSymbols    = array_column(configured_markets(), 'symbol');
        $validTimeframes = ['60m', '4h'];
        $validDays       = [30, 60, 90];

        if (!\in_array($symbol, $validSymbols, true))       $symbol    = 'EURUSD=X';
        if (!\in_array($timeframe, $validTimeframes, true)) $timeframe = '60m';
        if (!\in_array($days, $validDays, true))            $days      = 60;

        // ── Run backtest ───────────────────────────────────────────────────────
        $result = (new BacktestService())->run($symbol, $timeframe, $days);
        $label  = market_label_from_symbol($symbol);

        // ── Generate AI summary (one call per run, fallback to rule-based) ────
        $aiSummary = null;
        if (!isset($result['error'])) {
            try {
                $aiSummary = (new AiBacktestSummaryService())->generate($result);

                // Persist to database
                BacktestSummary::create([
                    'symbol'       => $symbol,
                    'timeframe'    => $timeframe,
                    'period_days'  => $days,
                    'decision'     => $aiSummary['decision'],
                    'confidence'   => $aiSummary['confidence'],
                    'summary_text' => $aiSummary['summary'],
                    'stats_json'   => json_encode($result, JSON_UNESCAPED_UNICODE) ?: '{}',
                    'provider'     => $aiSummary['provider'],
                ]);
            } catch (\Throwable $e) {
                error_log('[BacktestController] AI summary error: ' . $e->getMessage());
                // Non-fatal: backtest result still shows without AI summary
            }

            // ── Phase 2.8: activate strategy_statistics (previously unused) ────
            // Isolated from the AI-summary try/catch above — a failure here
            // must never affect the AI summary or the backtest result shown.
            try {
                (new StrategyLearningService())->recordFromBacktest($label, $timeframe, $result);
            } catch (\Throwable $e) {
                error_log('[BacktestController] Strategy statistic recording error: ' . $e->getMessage());
            }
        }

        $this->view('backtest/index', [
            'title'     => 'Backtest',
            'csrf'      => Csrf::token(),
            'markets'   => configured_markets(),
            'result'    => $result,
            'aiSummary' => $aiSummary,
            'selected'  => compact('symbol', 'timeframe', 'days', 'label'),
        ]);
    }
}
