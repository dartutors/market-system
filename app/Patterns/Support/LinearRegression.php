<?php
declare(strict_types=1);
namespace App\Patterns\Support;

/**
 * Ordinary least-squares fit over (index, price) pairs — used to
 * approximate a trendline through a run of swing highs or swing lows
 * (Triangle/Wedge/Channel/Flag/Pennant all reduce to comparing two of
 * these slopes). Deliberately not added to App\Indicators\Support\SeriesMath
 * (that trait is indicator-specific — smaSeries/emaSeries only — and this
 * is a pattern-geometry concern, not an indicator).
 */
final class LinearRegression
{
    /**
     * @param array<int, float> $xs
     * @param array<int, float> $ys
     * @return array{slope: float, intercept: float}
     */
    public static function fit(array $xs, array $ys): array
    {
        $n = count($xs);
        if ($n < 2) {
            return ['slope' => 0.0, 'intercept' => $ys[0] ?? 0.0];
        }

        $sumX  = array_sum($xs);
        $sumY  = array_sum($ys);
        $sumXY = 0.0;
        $sumXX = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sumXY += $xs[$i] * $ys[$i];
            $sumXX += $xs[$i] * $xs[$i];
        }

        $denominator = ($n * $sumXX) - ($sumX * $sumX);
        if (abs($denominator) < 1e-9) {
            return ['slope' => 0.0, 'intercept' => $sumY / $n];
        }

        $slope     = (($n * $sumXY) - ($sumX * $sumY)) / $denominator;
        $intercept = ($sumY - ($slope * $sumX)) / $n;

        return ['slope' => $slope, 'intercept' => $intercept];
    }

    /** Value of the fitted line at $x. */
    public static function valueAt(array $fit, float $x): float
    {
        return ($fit['slope'] * $x) + $fit['intercept'];
    }
}
