<?php
declare(strict_types=1);
namespace App\SmartMoney\Detectors;

use App\SmartMoney\Contracts\StructureDetectorInterface;
use App\SmartMoney\Models\StructureEvent;
use App\SmartMoney\Support\OrderBlockFinder;

/**
 * A mitigated order block that price didn't just touch but genuinely closed
 * through (invalidating its original bias), and later returned to retest
 * from the opposite side — the zone has "flipped polarity": a broken
 * bullish (support) order block acting as resistance on retest, or vice
 * versa. Deliberately stricter than MitigationBlockDetector (which reports
 * every zone price merely wicked back into): OrderBlockFinder's own
 * `mitigatedAtIndex` only means price returned into the zone, not that it
 * closed through it — a real breaker needs both the close-through AND a
 * confirmed retest, or this would just be relabeling every mitigation as a
 * breaker.
 */
final class BreakerBlockDetector implements StructureDetectorInterface
{
    private const MAX_BARS_TO_CONFIRM_BREAK = 5;
    private const MAX_BARS_TO_RETEST        = 40;

    public function key(): string
    {
        return 'breaker_block';
    }

    public function label(): string
    {
        return 'Breaker Blocks';
    }

    public function detect(array $rows): array
    {
        $events = [];

        foreach (OrderBlockFinder::findAll($rows) as $c) {
            if ($c['mitigatedAtIndex'] === null) {
                continue;
            }

            $breakIndex = $this->findConfirmedBreak($rows, $c);
            if ($breakIndex === null) {
                continue;
            }

            $retestIndex = $this->findRetest($rows, $c, $breakIndex);
            if ($retestIndex === null) {
                continue;
            }

            // Polarity flips: a broken bullish (support) OB becomes bearish resistance, and vice versa.
            $newDirection = $c['direction'] === 'bullish' ? 'bearish' : 'bullish';
            $label = $newDirection === 'bearish' ? 'Bearish Breaker Block' : 'Bullish Breaker Block';

            $events[] = new StructureEvent(
                'breaker_block',
                $label,
                $newDirection,
                65.0,
                $c['index'],
                $retestIndex,
                (int) $rows[$c['index']]['timestamp'],
                (int) $rows[$retestIndex]['timestamp'],
                sprintf(
                    '%s: a %s order block (%.5f - %.5f) that price closed through and later retested from the opposite side — now expected to act as %s.',
                    $label,
                    $c['direction'],
                    $c['low'],
                    $c['high'],
                    $newDirection === 'bearish' ? 'resistance' : 'support'
                ),
                $c['high'],
                $c['low'],
                true,
            );
        }

        return array_slice(array_reverse($events), 0, 10);
    }

    /** Finds the first candle within a few bars of mitigation that CLOSES beyond the zone (a real break, not a wick-touch). */
    private function findConfirmedBreak(array $rows, array $c): ?int
    {
        $count = count($rows);
        $limit = min($count - 1, $c['mitigatedAtIndex'] + self::MAX_BARS_TO_CONFIRM_BREAK);

        for ($j = $c['mitigatedAtIndex']; $j <= $limit; $j++) {
            $close = (float) $rows[$j]['close'];
            $brokeThrough = $c['direction'] === 'bullish'
                ? $close < $c['low']   // bullish OB support broken downward
                : $close > $c['high']; // bearish OB resistance broken upward
            if ($brokeThrough) {
                return $j;
            }
        }

        return null;
    }

    /** After a confirmed break, looks for price returning to retest the same zone from the new side. */
    private function findRetest(array $rows, array $c, int $breakIndex): ?int
    {
        $count = count($rows);
        $limit = min($count - 1, $breakIndex + self::MAX_BARS_TO_RETEST);

        for ($j = $breakIndex + 1; $j <= $limit; $j++) {
            $high = (float) $rows[$j]['high'];
            $low  = (float) $rows[$j]['low'];
            $retested = $c['direction'] === 'bullish'
                ? $high >= $c['low']   // price rallied back up into the now-resistance zone
                : $low <= $c['high'];  // price fell back down into the now-support zone
            if ($retested) {
                return $j;
            }
        }

        return null;
    }
}
