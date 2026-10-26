<?php
declare(strict_types=1);
namespace App\Patterns\Detectors;

use App\Patterns\Contracts\PatternDetectorInterface;
use App\Patterns\Models\PatternMatch;
use App\Patterns\Support\LinearRegression;
use App\Patterns\Support\SwingPointDetector;

/**
 * Converging trendlines fitted through the most recent swing highs/lows.
 * Ascending (flat top, rising bottom) and descending (falling top, flat
 * bottom) triangles carry a directional bias; symmetrical (both converging)
 * is reported neutral since the breakout direction isn't implied by the
 * shape alone.
 */
final class TriangleDetector implements PatternDetectorInterface
{
    private const MIN_SWINGS_PER_SIDE  = 3;
    private const SWINGS_USED          = 4;
    private const FLAT_SLOPE_PCT       = 0.00015; // per-bar slope below this counts as "flat"
    private const MIN_CONVERGENCE_PCT  = 0.25;    // lines must narrow by at least this much
    private const MIN_BARS             = 25;

    public function key(): string
    {
        return 'triangle';
    }

    public function label(): string
    {
        return 'Triangle';
    }

    public function detect(array $rows): array
    {
        $count = count($rows);
        if ($count < self::MIN_BARS) {
            return [];
        }

        $swings = (new SwingPointDetector())->detect($rows);
        $highs  = SwingPointDetector::onlyHighs($swings);
        $lows   = SwingPointDetector::onlyLows($swings);
        if (count($highs) < self::MIN_SWINGS_PER_SIDE || count($lows) < self::MIN_SWINGS_PER_SIDE) {
            return [];
        }

        $highsUsed = array_slice($highs, -self::SWINGS_USED);
        $lowsUsed  = array_slice($lows, -self::SWINGS_USED);

        $fitHigh = LinearRegression::fit(
            array_map(static fn($p) => (float) $p->index, $highsUsed),
            array_map(static fn($p) => $p->price, $highsUsed)
        );
        $fitLow = LinearRegression::fit(
            array_map(static fn($p) => (float) $p->index, $lowsUsed),
            array_map(static fn($p) => $p->price, $lowsUsed)
        );

        $avgPrice = $this->averageClose($rows);
        if ($avgPrice <= 0.0) {
            return [];
        }
        $normSlopeHigh = $fitHigh['slope'] / $avgPrice;
        $normSlopeLow  = $fitLow['slope'] / $avgPrice;

        $startX = (float) min($highsUsed[0]->index, $lowsUsed[0]->index);
        $endX   = (float) ($count - 1);

        $widthStart = LinearRegression::valueAt($fitHigh, $startX) - LinearRegression::valueAt($fitLow, $startX);
        $widthEnd   = LinearRegression::valueAt($fitHigh, $endX) - LinearRegression::valueAt($fitLow, $endX);
        if ($widthStart <= 0.0 || $widthEnd <= 0.0) {
            return [];
        }

        $convergencePct = ($widthStart - $widthEnd) / $widthStart;
        if ($convergencePct < self::MIN_CONVERGENCE_PCT) {
            return [];
        }

        $flatHigh = abs($normSlopeHigh) <= self::FLAT_SLOPE_PCT;
        $flatLow  = abs($normSlopeLow) <= self::FLAT_SLOPE_PCT;
        $descHigh = $normSlopeHigh < -self::FLAT_SLOPE_PCT;
        $ascLow   = $normSlopeLow > self::FLAT_SLOPE_PCT;

        if ($flatHigh && $ascLow) {
            $variant   = 'Ascending Triangle';
            $direction = 'bullish';
        } elseif ($descHigh && $flatLow) {
            $variant   = 'Descending Triangle';
            $direction = 'bearish';
        } elseif ($descHigh && $ascLow) {
            $variant   = 'Symmetrical Triangle';
            $direction = 'neutral';
        } else {
            return [];
        }

        $confidence = 55.0 + min(1.0, $convergencePct) * 30.0;

        return [new PatternMatch(
            'triangle',
            $variant,
            $direction,
            round(min(95.0, $confidence), 1),
            (int) $startX,
            $count - 1,
            (int) $rows[(int) $startX]['timestamp'],
            (int) $rows[$count - 1]['timestamp'],
            sprintf(
                '%s: upper trendline slope %.6f%%/bar, lower trendline slope %.6f%%/bar, converging %.1f%%.',
                $variant,
                $normSlopeHigh * 100,
                $normSlopeLow * 100,
                $convergencePct * 100
            ),
            [
                LinearRegression::valueAt($fitHigh, $endX),
                LinearRegression::valueAt($fitLow, $endX),
            ]
        )];
    }

    private function averageClose(array $rows): float
    {
        $tail = array_slice($rows, -20);
        $sum  = 0.0;
        foreach ($tail as $row) {
            $sum += (float) $row['close'];
        }
        return count($tail) > 0 ? $sum / count($tail) : 0.0;
    }
}
