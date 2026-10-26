<?php
declare(strict_types=1);
namespace App\Indicators\Support;

/**
 * Shared math extracted verbatim from the pre-refactor IndicatorService —
 * same formulas, same edge-case handling, used by SMA/EMA/MACD/ATR so each
 * indicator class stays self-contained without copy-pasting these two series.
 */
trait SeriesMath
{
    protected function smaSeries(array $values, int $period): array
    {
        $out   = [];
        $count = count($values);
        for ($i = 0; $i < $count; $i++) {
            if ($i + 1 < $period) {
                $out[$i] = null;
                continue;
            }
            $slice   = array_slice($values, $i - $period + 1, $period);
            $out[$i] = array_sum($slice) / $period;
        }
        return $out;
    }

    protected function emaSeries(array $values, int $period): array
    {
        $out        = [];
        $multiplier = 2 / ($period + 1);
        $ema        = null;
        foreach ($values as $i => $value) {
            $ema     = $ema === null ? (float) $value : (($value - $ema) * $multiplier) + $ema;
            $out[$i] = $ema;
        }
        return $out;
    }
}
