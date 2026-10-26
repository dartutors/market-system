<?php
declare(strict_types=1);
namespace App\Backtesting;

use App\Strategies\StrategyRegistry;

/**
 * Walk-forward STRATEGY SELECTION test (not a walk-forward parameter fit —
 * this app has discrete strategies, not continuous model weights, so that's
 * the honest scope). Splits history into N contiguous, equal-sized segments;
 * for each adjacent pair, picks whichever registered strategy had the best
 * in-sample profit factor on segment i, then scores that SAME strategy
 * out-of-sample on segment i+1. A strategy that keeps winning across windows
 * is a robustness signal; a different winner every window is a classic
 * overfitting smell — surfaced as the `stability` verdict.
 */
final class WalkForwardTester
{
    private const MIN_WINDOW_ROWS = 60; // BacktestRunner's own MIN_CANDLES(55)+4 guard, restated here as a cheap pre-check

    /**
     * @param array<int, array<string, mixed>> $enrichedRows
     */
    public function testOnRows(
        array $enrichedRows,
        int $windows = 5,
        float $targetAtrMult = 2.0,
        float $stopAtrMult = 1.2
    ): array {
        $total       = \count($enrichedRows);
        $windows     = max(2, $windows);
        $segmentSize = intdiv($total, $windows);

        if ($segmentSize < self::MIN_WINDOW_ROWS) {
            return ['error' => 'Not enough history for walk-forward testing with this many windows.'];
        }

        $runner         = new BacktestRunner();
        $strategyNames  = array_keys((new StrategyRegistry())->all());
        $results        = [];
        $winnerCounts   = [];

        for ($w = 0; $w < $windows - 1; $w++) {
            $inSampleStart = $w * $segmentSize;
            $inSampleRows  = array_slice($enrichedRows, $inSampleStart, $segmentSize);
            $outSampleRows = array_slice($enrichedRows, $inSampleStart + $segmentSize, $segmentSize);

            if (\count($inSampleRows) < self::MIN_WINDOW_ROWS || \count($outSampleRows) < self::MIN_WINDOW_ROWS) {
                continue;
            }

            $bestName = null;
            $bestPf   = -INF;
            foreach ($strategyNames as $name) {
                $inResult = $runner->runOnRows($inSampleRows, $name, $targetAtrMult, $stopAtrMult);
                if (isset($inResult['error'])) {
                    continue;
                }
                $pf = (float) $inResult['summary']['profit_factor'];
                if ($pf > $bestPf) {
                    $bestPf   = $pf;
                    $bestName = $name;
                }
            }
            if ($bestName === null) {
                continue;
            }

            $outResult                 = $runner->runOnRows($outSampleRows, $bestName, $targetAtrMult, $stopAtrMult);
            $winnerCounts[$bestName]   = ($winnerCounts[$bestName] ?? 0) + 1;
            $outSummary                = $outResult['summary'] ?? null;

            $results[] = [
                'window'                      => $w + 1,
                'in_sample_winner'            => $bestName,
                'in_sample_profit_factor'     => round($bestPf, 2),
                'out_of_sample_win_rate'      => $outSummary['win_rate']       ?? 0.0,
                'out_of_sample_profit_factor' => $outSummary['profit_factor'] ?? 0.0,
                'out_of_sample_signals'       => $outSummary['total_signals'] ?? 0,
            ];
        }

        if (empty($results)) {
            return ['error' => 'Walk-forward testing produced no valid windows for this history length.'];
        }

        $totalWindows     = \count($results);
        $maxWinnerCount   = !empty($winnerCounts) ? max($winnerCounts) : 0;
        $dominantStrategy = array_search($maxWinnerCount, $winnerCounts, true);
        $stability        = $maxWinnerCount >= (int) ceil($totalWindows / 2) ? 'stable' : 'unstable';

        return [
            'windows'                        => $results,
            'window_count'                   => $totalWindows,
            'dominant_strategy'              => $dominantStrategy !== false ? $dominantStrategy : null,
            'stability'                      => $stability,
            'avg_out_of_sample_win_rate'     => round(array_sum(array_column($results, 'out_of_sample_win_rate')) / $totalWindows, 1),
            'avg_out_of_sample_profit_factor'=> round(array_sum(array_column($results, 'out_of_sample_profit_factor')) / $totalWindows, 2),
        ];
    }
}
