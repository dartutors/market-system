<?php
declare(strict_types=1);
namespace App\Patterns\Detectors;

use App\Patterns\Contracts\PatternDetectorInterface;
use App\Patterns\Models\PatternMatch;

/**
 * Loose sideways-consolidation check: overall price compression over a
 * trailing window, with no touch-count requirement — deliberately looser
 * than Rectangle, which requires confirmed multiple touches on both a flat
 * top and flat bottom.
 */
final class RangeDetector implements PatternDetectorInterface
{
    private const LOOKBACK_BARS = 20;
    private const MAX_WIDTH_PCT = 0.03; // 3%
    private const MIN_BARS      = 20;

    public function key(): string
    {
        return 'range';
    }

    public function label(): string
    {
        return 'Range';
    }

    public function detect(array $rows): array
    {
        $count = count($rows);
        if ($count < self::MIN_BARS) {
            return [];
        }

        $start = $count - self::LOOKBACK_BARS;
        if ($start < 0) {
            return [];
        }

        $tail   = array_slice($rows, $start);
        $highs  = array_map(static fn($r) => (float) $r['high'], $tail);
        $lows   = array_map(static fn($r) => (float) $r['low'], $tail);
        $closes = array_map(static fn($r) => (float) $r['close'], $tail);

        $rangeHigh = max($highs);
        $rangeLow  = min($lows);
        $avgClose  = array_sum($closes) / count($closes);
        if ($avgClose <= 0.0) {
            return [];
        }

        $widthPct = ($rangeHigh - $rangeLow) / $avgClose;
        if ($widthPct > self::MAX_WIDTH_PCT) {
            return [];
        }

        $confidence = 55.0 + (1.0 - min(1.0, $widthPct / self::MAX_WIDTH_PCT)) * 30.0;

        return [new PatternMatch(
            'range',
            'Range',
            'neutral',
            round(min(90.0, $confidence), 1),
            $start,
            $count - 1,
            (int) $rows[$start]['timestamp'],
            (int) $rows[$count - 1]['timestamp'],
            sprintf(
                'Price has stayed within a %.2f%% band (%.5f - %.5f) over the last %d bars.',
                $widthPct * 100,
                $rangeLow,
                $rangeHigh,
                self::LOOKBACK_BARS
            ),
            [$rangeHigh, $rangeLow]
        )];
    }
}
