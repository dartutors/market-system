<?php
declare(strict_types=1);
namespace App\Patterns\Detectors;

use App\Patterns\Contracts\PatternDetectorInterface;
use App\Patterns\Models\PatternMatch;
use App\Patterns\Support\LinearRegression;

/** A sharp directional "pole" move followed by a tight, roughly parallel consolidation channel. */
final class FlagDetector implements PatternDetectorInterface
{
    private const POLE_BARS              = 10;
    private const TAIL_BARS              = 8;
    private const POLE_MIN_MOVE_PCT      = 0.02; // 2%
    private const TAIL_MAX_SLOPE_PCT     = 0.0006;
    private const PARALLEL_TOLERANCE_PCT = 0.0004;
    private const MAX_WIDTH_DRIFT_PCT    = 0.4;
    private const MIN_BARS               = 25;

    public function key(): string
    {
        return 'flag';
    }

    public function label(): string
    {
        return 'Flag';
    }

    public function detect(array $rows): array
    {
        $count = count($rows);
        if ($count < self::MIN_BARS) {
            return [];
        }

        $tailStart = $count - self::TAIL_BARS;
        $poleStart = $tailStart - self::POLE_BARS;
        if ($poleStart < 0) {
            return [];
        }

        $poleOpen  = (float) $rows[$poleStart]['close'];
        $poleClose = (float) $rows[$tailStart - 1]['close'];
        if ($poleOpen <= 0.0) {
            return [];
        }
        $poleChangePct = ($poleClose - $poleOpen) / $poleOpen;
        if (abs($poleChangePct) < self::POLE_MIN_MOVE_PCT) {
            return [];
        }

        $tail = array_slice($rows, $tailStart);
        $xs   = array_map(static fn($x): float => (float) $x, range($tailStart, $count - 1));
        $highsY = array_map(static fn($r) => (float) $r['high'], $tail);
        $lowsY  = array_map(static fn($r) => (float) $r['low'], $tail);

        $fitHigh = LinearRegression::fit($xs, $highsY);
        $fitLow  = LinearRegression::fit($xs, $lowsY);

        $avgPrice = array_sum(array_map(static fn($r) => (float) $r['close'], $tail)) / count($tail);
        if ($avgPrice <= 0.0) {
            return [];
        }

        $normSlopeHigh = $fitHigh['slope'] / $avgPrice;
        $normSlopeLow  = $fitLow['slope'] / $avgPrice;

        if (abs($normSlopeHigh) > self::TAIL_MAX_SLOPE_PCT || abs($normSlopeLow) > self::TAIL_MAX_SLOPE_PCT) {
            return [];
        }
        if (abs($normSlopeHigh - $normSlopeLow) > self::PARALLEL_TOLERANCE_PCT) {
            return [];
        }

        $startX = (float) $tailStart;
        $endX   = (float) ($count - 1);
        $widthStart = LinearRegression::valueAt($fitHigh, $startX) - LinearRegression::valueAt($fitLow, $startX);
        $widthEnd   = LinearRegression::valueAt($fitHigh, $endX) - LinearRegression::valueAt($fitLow, $endX);
        if ($widthStart <= 0.0 || $widthEnd <= 0.0) {
            return [];
        }
        if (abs($widthEnd - $widthStart) / $widthStart > self::MAX_WIDTH_DRIFT_PCT) {
            return [];
        }

        $direction = $poleChangePct > 0 ? 'bullish' : 'bearish';

        $confidence = 55.0
            + min(1.0, abs($poleChangePct) / 0.05) * 20.0
            + (1.0 - min(1.0, abs($normSlopeHigh - $normSlopeLow) / self::PARALLEL_TOLERANCE_PCT)) * 10.0;

        return [new PatternMatch(
            'flag',
            'Flag',
            $direction,
            round(min(90.0, $confidence), 1),
            $poleStart,
            $count - 1,
            (int) $rows[$poleStart]['timestamp'],
            (int) $rows[$count - 1]['timestamp'],
            sprintf(
                '%.2f%% pole over %d bars followed by a tight parallel consolidation (%d bars).',
                $poleChangePct * 100,
                self::POLE_BARS,
                self::TAIL_BARS
            ),
            [LinearRegression::valueAt($fitHigh, $endX), LinearRegression::valueAt($fitLow, $endX)]
        )];
    }
}
