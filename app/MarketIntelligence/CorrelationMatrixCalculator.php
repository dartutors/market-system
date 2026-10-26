<?php
declare(strict_types=1);
namespace App\MarketIntelligence;

use App\Config\MarketIntelligence as MiConfig;

/**
 * Pearson correlation of daily returns between every unique pair of
 * configured markets — genuine historical price-return correlation, distinct
 * from App\Risk\CorrelationAnalyzer (Phase 2.5), which only checks whether
 * two OPEN POSITIONS share directional exposure to a currency, never touching
 * historical price data. No correlation/covariance helper existed anywhere
 * in this codebase before this class (App\Indicators\Support\SeriesMath only
 * has smaSeries()/emaSeries()).
 *
 * Each pair's closeSeries is fetched independently and trimmed to the same
 * trailing length (see PairSnapshotFetcher), not matched by calendar date —
 * an accepted approximation for an educational tool; weekend/holiday gaps
 * between symbols are rare enough at daily resolution not to meaningfully
 * skew the coefficient.
 */
final class CorrelationMatrixCalculator
{
    /**
     * @param array<string, PairSnapshot> $snapshots
     * @return array<int, array{pair_a:string, pair_b:string, coefficient:float, label:string}>
     */
    public static function calculate(array $snapshots): array
    {
        $labels = array_keys($snapshots);
        $results = [];

        for ($i = 0; $i < count($labels); $i++) {
            for ($j = $i + 1; $j < count($labels); $j++) {
                $labelA = $labels[$i];
                $labelB = $labels[$j];

                $returnsA = self::returnsFromCloses($snapshots[$labelA]->closeSeries);
                $returnsB = self::returnsFromCloses($snapshots[$labelB]->closeSeries);

                $length = min(count($returnsA), count($returnsB));
                $returnsA = array_slice($returnsA, -$length);
                $returnsB = array_slice($returnsB, -$length);

                $coefficient = self::pearson($returnsA, $returnsB);

                $results[] = [
                    'pair_a'      => $labelA,
                    'pair_b'      => $labelB,
                    'coefficient' => round($coefficient, 3),
                    'label'       => self::labelFor($coefficient),
                ];
            }
        }

        usort($results, static fn(array $a, array $b): int => abs($b['coefficient']) <=> abs($a['coefficient']));

        return $results;
    }

    /** @return array<float> Close-to-close % returns, one shorter than the input series. */
    private static function returnsFromCloses(array $closes): array
    {
        $returns = [];
        for ($i = 1, $count = count($closes); $i < $count; $i++) {
            $prev = $closes[$i - 1];
            if ($prev == 0.0) {
                continue;
            }
            $returns[] = ($closes[$i] - $prev) / $prev;
        }
        return $returns;
    }

    private static function pearson(array $x, array $y): float
    {
        $n = min(count($x), count($y));
        if ($n < 2) {
            return 0.0;
        }

        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $covariance = 0.0;
        $varianceX  = 0.0;
        $varianceY  = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $covariance += $dx * $dy;
            $varianceX  += $dx * $dx;
            $varianceY  += $dy * $dy;
        }

        $denominator = sqrt($varianceX * $varianceY);
        return $denominator > 0.0 ? $covariance / $denominator : 0.0;
    }

    private static function labelFor(float $coefficient): string
    {
        $abs = abs($coefficient);
        $direction = $coefficient >= 0 ? 'positive' : 'negative';

        return match (true) {
            $abs >= MiConfig::correlationStrongThreshold()   => "strong {$direction}",
            $abs >= MiConfig::correlationModerateThreshold() => "moderate {$direction}",
            default                                           => 'weak',
        };
    }
}
