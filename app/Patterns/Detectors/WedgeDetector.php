<?php
declare(strict_types=1);
namespace App\Patterns\Detectors;

use App\Patterns\Contracts\PatternDetectorInterface;
use App\Patterns\Models\PatternMatch;
use App\Patterns\Support\LinearRegression;
use App\Patterns\Support\SwingPointDetector;

/**
 * Both trendlines slope the SAME direction but converge — a rising wedge
 * (both up, lower line rising faster) is typically bearish despite the
 * uptrend; a falling wedge (both down, upper line falling faster) is
 * typically bullish. This is what separates a Wedge from a Triangle (opposite
 * slopes) and a Channel (same slope, not converging).
 */
final class WedgeDetector implements PatternDetectorInterface
{
    private const MIN_SWINGS_PER_SIDE = 3;
    private const SWINGS_USED         = 4;
    private const FLAT_SLOPE_PCT      = 0.00015;
    private const MIN_CONVERGENCE_PCT = 0.2;
    private const MIN_BARS            = 25;

    public function key(): string
    {
        return 'wedge';
    }

    public function label(): string
    {
        return 'Wedge';
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

        $bothRising  = $normSlopeHigh > self::FLAT_SLOPE_PCT && $normSlopeLow > self::FLAT_SLOPE_PCT;
        $bothFalling = $normSlopeHigh < -self::FLAT_SLOPE_PCT && $normSlopeLow < -self::FLAT_SLOPE_PCT;

        if ($bothRising && $normSlopeLow > $normSlopeHigh) {
            $variant   = 'Rising Wedge';
            $direction = 'bearish';
        } elseif ($bothFalling && $normSlopeHigh < $normSlopeLow) {
            $variant   = 'Falling Wedge';
            $direction = 'bullish';
        } else {
            return [];
        }

        $confidence = 55.0 + min(1.0, $convergencePct) * 30.0;

        return [new PatternMatch(
            'wedge',
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
