<?php
declare(strict_types=1);
namespace App\Learning;

use App\Models\StrategyStatistic;

/**
 * Activates the previously-unused strategy_statistics table (built in an
 * earlier sprint; App\Services\BacktestService::run() computed win_rate/
 * max_drawdown_r etc. but never persisted them structurally — only as one
 * JSON blob per run in backtest_summaries). Called from
 * App\Controllers\BacktestController::run() right after its existing
 * BacktestSummary::create() call — additive, doesn't change any existing
 * backtest behavior.
 */
final class StrategyLearningService
{
    /**
     * @param array $backtestSummary BacktestService::run()'s return shape
     *        (total_signals, wins, losses, win_rate, max_drawdown_r, days, ...)
     */
    public function recordFromBacktest(
        string $marketLabel,
        string $timeframe,
        array $backtestSummary,
        string $strategyTag = 'trend_following'
    ): int {
        $total      = (int) ($backtestSummary['total_signals'] ?? 0);
        $wins       = (int) ($backtestSummary['wins']   ?? 0);
        $losses     = (int) ($backtestSummary['losses'] ?? 0);

        // Same R-multiple convention BacktestService::run() itself uses: win=+2R, loss=-1R, breakeven=0.
        $totalR = ($wins * 2.0) + ($losses * -1.0);
        $avgR   = $total > 0 ? round($totalR / $total, 2) : 0.0;

        $days       = max(1, (int) ($backtestSummary['days'] ?? 60));
        $periodEnd  = now_eat();
        $periodStart = $periodEnd->modify("-{$days} days");

        return StrategyStatistic::create([
            'market_label'   => $marketLabel,
            'timeframe'      => $timeframe,
            'strategy_tag'   => $strategyTag,
            'period_start'   => $periodStart->format('Y-m-d'),
            'period_end'     => $periodEnd->format('Y-m-d'),
            'win_rate'       => (float) ($backtestSummary['win_rate'] ?? 0.0),
            'avg_r'          => $avgR,
            'max_drawdown_r' => (float) ($backtestSummary['max_drawdown_r'] ?? 0.0),
            'sample_size'    => $total,
        ]);
    }

    public function history(string $marketLabel, string $timeframe, int $limit = 10): array
    {
        return StrategyStatistic::latestForMarket($marketLabel, $timeframe, $limit);
    }
}
