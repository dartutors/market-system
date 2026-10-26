<?php
declare(strict_types=1);
namespace App\Backtesting;

use App\Strategies\StrategyRegistry;

/**
 * Runs BacktestRunner::runOnRows() once per registered strategy over the
 * SAME already-fetched candle history, so "Strategy comparison" costs one
 * network round-trip regardless of how many strategies exist in
 * App\Strategies\StrategyRegistry.
 */
final class StrategyComparator
{
    /**
     * @param array<int, array<string, mixed>> $enrichedRows
     * @return array<int, array{strategy:string, summary:array, best:bool}>
     */
    public function compareOnRows(
        array $enrichedRows,
        float $targetAtrMult = 2.0,
        float $stopAtrMult = 1.2,
        ?int $barsBack = null
    ): array {
        $runner = new BacktestRunner();
        $rows   = [];

        foreach (array_keys((new StrategyRegistry())->all()) as $name) {
            $result = $runner->runOnRows($enrichedRows, $name, $targetAtrMult, $stopAtrMult, $barsBack);
            if (isset($result['error'])) {
                continue;
            }
            $rows[] = ['strategy' => $name, 'summary' => $result['summary']];
        }

        usort($rows, static function (array $a, array $b): int {
            return ($b['summary']['profit_factor'] <=> $a['summary']['profit_factor'])
                ?: ($b['summary']['win_rate'] <=> $a['summary']['win_rate']);
        });

        foreach ($rows as $index => &$row) {
            $row['best'] = ($index === 0);
        }

        return $rows;
    }
}
