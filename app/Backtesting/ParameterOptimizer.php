<?php
declare(strict_types=1);
namespace App\Backtesting;

/**
 * Grid search over ATR target/stop multipliers for one strategy, against
 * the same already-fetched candle history BacktestRunner uses everywhere
 * else in Phase 3.1 — 16 combinations by default (4 targets x 4 stops),
 * ranked by profit factor, with the codebase's existing default (2.0R
 * target / 1.2R stop, see BacktestRunner's own defaults and the original
 * BacktestService) called out as the comparison baseline.
 */
final class ParameterOptimizer
{
    private const DEFAULT_TARGET_MULTS = [1.5, 2.0, 2.5, 3.0];
    private const DEFAULT_STOP_MULTS   = [0.8, 1.0, 1.2, 1.5];
    private const BASELINE_TARGET_MULT = 2.0;
    private const BASELINE_STOP_MULT   = 1.2;

    /**
     * @param array<int, array<string, mixed>> $enrichedRows
     * @param array<int, float>|null $targetMults
     * @param array<int, float>|null $stopMults
     */
    public function optimizeOnRows(
        array $enrichedRows,
        string $strategyName,
        ?array $targetMults = null,
        ?array $stopMults = null,
        ?int $barsBack = null
    ): array {
        $targetMults ??= self::DEFAULT_TARGET_MULTS;
        $stopMults   ??= self::DEFAULT_STOP_MULTS;

        $runner = new BacktestRunner();
        $grid   = [];

        foreach ($targetMults as $target) {
            foreach ($stopMults as $stop) {
                $result = $runner->runOnRows($enrichedRows, $strategyName, $target, $stop, $barsBack);
                if (isset($result['error'])) {
                    continue;
                }
                $grid[] = [
                    'target_atr_mult' => $target,
                    'stop_atr_mult'   => $stop,
                    'summary'         => $result['summary'],
                ];
            }
        }

        if (empty($grid)) {
            return ['error' => 'Parameter optimization produced no valid combinations for this history length.'];
        }

        usort($grid, static function (array $a, array $b): int {
            return ($b['summary']['profit_factor'] <=> $a['summary']['profit_factor'])
                ?: ($b['summary']['win_rate'] <=> $a['summary']['win_rate']);
        });

        $baseline = null;
        foreach ($grid as $row) {
            if (abs($row['target_atr_mult'] - self::BASELINE_TARGET_MULT) < 1e-9
                && abs($row['stop_atr_mult'] - self::BASELINE_STOP_MULT) < 1e-9) {
                $baseline = $row;
                break;
            }
        }

        foreach ($grid as $index => &$row) {
            $row['best'] = ($index === 0);
        }

        return [
            'grid'     => $grid,
            'best'     => $grid[0],
            'baseline' => $baseline,
        ];
    }
}
