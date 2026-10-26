<?php
declare(strict_types=1);
namespace App\Patterns\Detectors;

use App\Patterns\Contracts\PatternDetectorInterface;
use App\Patterns\Models\PatternMatch;
use App\Patterns\Support\LinearRegression;
use App\Patterns\Support\SwingPointDetector;

/** Two roughly parallel, sloped trendlines of near-constant width — an ascending or descending channel. */
final class ChannelDetector implements PatternDetectorInterface
{
    private const MIN_SWINGS_PER_SIDE = 3;
    private const SWINGS_USED         = 4;
    private const FLAT_SLOPE_PCT      = 0.00015;
    private const PARALLEL_TOLERANCE_PCT = 0.00012; // max slope-difference between the two lines
    private const MAX_WIDTH_DRIFT_PCT    = 0.25;    // width must stay roughly constant
    private const MIN_BARS            = 25;

    public function key(): string
    {
        return 'channel';
    }

    public function label(): string
    {
        return 'Channel';
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

        if (abs($normSlopeHigh - $normSlopeLow) > self::PARALLEL_TOLERANCE_PCT) {
            return [];
        }

        $bothRising  = $normSlopeHigh > self::FLAT_SLOPE_PCT && $normSlopeLow > self::FLAT_SLOPE_PCT;
        $bothFalling = $normSlopeHigh < -self::FLAT_SLOPE_PCT && $normSlopeLow < -self::FLAT_SLOPE_PCT;
        if (!$bothRising && !$bothFalling) {
            return []; // flat-flat is Rectangle's territory, not Channel
        }

        $startX = (float) min($highsUsed[0]->index, $lowsUsed[0]->index);
        $endX   = (float) ($count - 1);

        $widthStart = LinearRegression::valueAt($fitHigh, $startX) - LinearRegression::valueAt($fitLow, $startX);
        $widthEnd   = LinearRegression::valueAt($fitHigh, $endX) - LinearRegression::valueAt($fitLow, $endX);
        if ($widthStart <= 0.0 || $widthEnd <= 0.0) {
            return [];
        }

        $widthDriftPct = abs($widthEnd - $widthStart) / $widthStart;
        if ($widthDriftPct > self::MAX_WIDTH_DRIFT_PCT) {
            return []; // drifting too much to be "parallel" — that's a Wedge/Triangle instead
        }

        $direction = $bothRising ? 'bullish' : 'bearish';
        $variant   = $bothRising ? 'Ascending Channel' : 'Descending Channel';

        $confidence = 55.0 + (1.0 - min(1.0, $widthDriftPct / self::MAX_WIDTH_DRIFT_PCT)) * 25.0;

        return [new PatternMatch(
            'channel',
            $variant,
            $direction,
            round(min(90.0, $confidence), 1),
            (int) $startX,
            $count - 1,
            (int) $rows[(int) $startX]['timestamp'],
            (int) $rows[$count - 1]['timestamp'],
            sprintf(
                '%s: parallel trendlines both sloping at ~%.6f%%/bar, width drift %.1f%%.',
                $variant,
                (($normSlopeHigh + $normSlopeLow) / 2) * 100,
                $widthDriftPct * 100
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
