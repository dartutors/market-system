<?php
declare(strict_types=1);
namespace App\Backtesting\Support;

use App\Backtesting\Models\TradeResult;

/**
 * Turns a chronological list of TradeResult R-multiples into a running
 * equity curve + drawdown series. Pure function, no I/O — used by
 * App\Backtesting\BacktestRunner (to build the curve for the run that just
 * executed) and directly by App\Backtesting\MonteCarloSimulator (to score
 * each bootstrap-resampled trade order the same way).
 */
final class EquityCurveBuilder
{
    /**
     * @param array<int, TradeResult> $trades Chronological order.
     * @return array{
     *   points: array<int, array{index:int, timestamp:int, equity:float, drawdown:float}>,
     *   final_equity: float,
     *   max_drawdown: float
     * }
     */
    public static function build(array $trades): array
    {
        $points      = [];
        $equity      = 0.0;
        $peak        = 0.0;
        $maxDrawdown = 0.0;

        foreach ($trades as $trade) {
            $equity += $trade->pl;
            if ($equity > $peak) {
                $peak = $equity;
            }
            $drawdown = $peak - $equity;
            if ($drawdown > $maxDrawdown) {
                $maxDrawdown = $drawdown;
            }

            $points[] = [
                'index'     => $trade->index,
                'timestamp' => $trade->timestamp,
                'equity'    => round($equity, 4),
                'drawdown'  => round($drawdown, 4),
            ];
        }

        return [
            'points'       => $points,
            'final_equity' => round($equity, 4),
            'max_drawdown' => round($maxDrawdown, 4),
        ];
    }

    /**
     * Same math as build(), but over a plain R-multiple sequence rather than
     * TradeResult objects — used by MonteCarloSimulator's resampled arrays,
     * which have no index/timestamp to preserve.
     *
     * @param array<int, float> $plSequence
     * @return array{final_equity: float, max_drawdown: float}
     */
    public static function summarizePlSequence(array $plSequence): array
    {
        $equity      = 0.0;
        $peak        = 0.0;
        $maxDrawdown = 0.0;

        foreach ($plSequence as $pl) {
            $equity += $pl;
            if ($equity > $peak) {
                $peak = $equity;
            }
            $drawdown = $peak - $equity;
            if ($drawdown > $maxDrawdown) {
                $maxDrawdown = $drawdown;
            }
        }

        return ['final_equity' => round($equity, 4), 'max_drawdown' => round($maxDrawdown, 4)];
    }
}
