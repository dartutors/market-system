<?php
declare(strict_types=1);
namespace App\SmartMoney\Detectors;

use App\SmartMoney\Contracts\StructureDetectorInterface;
use App\SmartMoney\Models\StructureEvent;

/**
 * A tight sideways consolidation range immediately followed by an
 * impulsive directional breakout — the same "detect a completed setup with
 * hindsight over the historical window" approach every other detector here
 * uses (not a live prediction of what a CURRENT range will resolve into,
 * which no algorithmic detector can honestly claim). A range that breaks
 * upward is labeled Accumulation (smart money was building long exposure
 * during the quiet phase); breaks downward is Distribution. A range with no
 * clear breakout within the lookahead window is not reported — this
 * detector never guesses which way an unresolved range will go.
 */
final class AccumulationDistributionDetector implements StructureDetectorInterface
{
    private const RANGE_BARS         = 10;
    private const MAX_RANGE_PCT      = 0.012;  // consolidation: 1.2% high-to-low over the range
    private const BREAKOUT_BARS      = 4;
    private const BREAKOUT_MIN_PCT   = 0.015;  // impulsive move: 1.5% over the breakout window
    private const MIN_BARS           = self::RANGE_BARS + self::BREAKOUT_BARS + 1;

    public function key(): string
    {
        return 'accumulation_distribution';
    }

    public function label(): string
    {
        return 'Accumulation / Distribution';
    }

    public function detect(array $rows): array
    {
        $count = count($rows);
        if ($count < self::MIN_BARS) {
            return [];
        }

        $events = [];

        for ($start = 0; $start <= $count - self::MIN_BARS; $start++) {
            $rangeEnd = $start + self::RANGE_BARS - 1;
            $slice = array_slice($rows, $start, self::RANGE_BARS);

            $rangeHigh = max(array_column($slice, 'high'));
            $rangeLow  = min(array_column($slice, 'low'));
            $mid = ($rangeHigh + $rangeLow) / 2;
            if ($mid <= 0.0) {
                continue;
            }
            $rangePct = ($rangeHigh - $rangeLow) / $mid;
            if ($rangePct > self::MAX_RANGE_PCT) {
                continue; // not a tight enough consolidation
            }

            $breakoutStart = $rangeEnd + 1;
            $breakoutEnd = min($count - 1, $breakoutStart + self::BREAKOUT_BARS - 1);
            if ($breakoutEnd - $breakoutStart + 1 < self::BREAKOUT_BARS) {
                continue;
            }

            $preBreakoutClose = (float) $rows[$rangeEnd]['close'];
            $postBreakoutClose = (float) $rows[$breakoutEnd]['close'];
            if ($preBreakoutClose <= 0.0) {
                continue;
            }
            $movePct = ($postBreakoutClose - $preBreakoutClose) / $preBreakoutClose;

            if ($movePct >= self::BREAKOUT_MIN_PCT) {
                $events[] = new StructureEvent(
                    'accumulation_distribution',
                    'Accumulation',
                    'bullish',
                    62.0,
                    $start,
                    $breakoutEnd,
                    (int) $rows[$start]['timestamp'],
                    (int) $rows[$breakoutEnd]['timestamp'],
                    sprintf('Accumulation: a %.3f%%-wide consolidation range (%.5f - %.5f) resolved with a %.2f%% breakout higher.', $rangePct * 100, $rangeLow, $rangeHigh, $movePct * 100),
                    $rangeHigh,
                    $rangeLow,
                    false,
                );
                // Skip past this range+breakout window rather than reporting overlapping sub-ranges of the same move.
                $start = $breakoutEnd;
            } elseif ($movePct <= -self::BREAKOUT_MIN_PCT) {
                $events[] = new StructureEvent(
                    'accumulation_distribution',
                    'Distribution',
                    'bearish',
                    62.0,
                    $start,
                    $breakoutEnd,
                    (int) $rows[$start]['timestamp'],
                    (int) $rows[$breakoutEnd]['timestamp'],
                    sprintf('Distribution: a %.3f%%-wide consolidation range (%.5f - %.5f) resolved with a %.2f%% breakdown lower.', $rangePct * 100, $rangeLow, $rangeHigh, $movePct * 100),
                    $rangeHigh,
                    $rangeLow,
                    false,
                );
                $start = $breakoutEnd;
            }
        }

        return array_slice(array_reverse($events), 0, 10);
    }
}
