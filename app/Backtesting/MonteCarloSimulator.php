<?php
declare(strict_types=1);
namespace App\Backtesting;

use App\Backtesting\Models\TradeResult;
use App\Backtesting\Support\EquityCurveBuilder;

/**
 * Bootstrap-resamples a completed run's R-multiple sequence (win=+2R,
 * loss=-1R, breakeven=0R) to show how much the reported win rate could
 * plausibly have varied by luck/trade ordering alone. Deliberately not
 * seeded — the point is to show real variability across runs, not to be
 * reproducible bit-for-bit.
 */
final class MonteCarloSimulator
{
    /**
     * @param array<int, TradeResult> $trades
     */
    public function simulate(array $trades, int $iterations = 1000): array
    {
        $plValues = array_map(static fn(TradeResult $t): float => $t->pl, $trades);
        $n        = \count($plValues);

        if ($n === 0) {
            return ['error' => 'No trades to simulate.'];
        }

        $finalEquities = [];
        $maxDrawdowns  = [];

        for ($iter = 0; $iter < $iterations; $iter++) {
            $resampled = [];
            for ($k = 0; $k < $n; $k++) {
                $resampled[] = $plValues[random_int(0, $n - 1)];
            }
            $stats           = EquityCurveBuilder::summarizePlSequence($resampled);
            $finalEquities[] = $stats['final_equity'];
            $maxDrawdowns[]  = $stats['max_drawdown'];
        }

        sort($finalEquities);
        sort($maxDrawdowns);

        $lossCount = \count(array_filter($finalEquities, static fn(float $e): bool => $e < 0.0));

        return [
            'iterations'               => $iterations,
            'trade_count'              => $n,
            'final_equity_percentiles' => $this->percentiles($finalEquities),
            'max_drawdown_percentiles' => $this->percentiles($maxDrawdowns),
            'probability_of_loss'      => round($lossCount / $iterations * 100, 1),
        ];
    }

    /** @param array<int, float> $sorted */
    private function percentiles(array $sorted): array
    {
        return [
            'p5'  => $this->percentile($sorted, 5),
            'p25' => $this->percentile($sorted, 25),
            'p50' => $this->percentile($sorted, 50),
            'p75' => $this->percentile($sorted, 75),
            'p95' => $this->percentile($sorted, 95),
        ];
    }

    /** @param array<int, float> $sorted Ascending. */
    private function percentile(array $sorted, int $pct): float
    {
        $n = \count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        $index = (int) floor(($pct / 100) * ($n - 1));
        $index = max(0, min($n - 1, $index));
        return round($sorted[$index], 4);
    }
}
