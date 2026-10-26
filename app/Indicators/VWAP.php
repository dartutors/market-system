<?php
declare(strict_types=1);
namespace App\Indicators;

use App\Indicators\Contracts\IndicatorInterface;

/**
 * Cumulative volume-weighted average price over the given row series.
 * New indicator, not part of the legacy default registry. Callers wanting a
 * session-reset VWAP should slice rows to one session before calling apply().
 */
final class VWAP implements IndicatorInterface
{
    public function key(): string
    {
        return 'vwap';
    }

    public function apply(array $rows): array
    {
        $cumulativePv     = 0.0;
        $cumulativeVolume = 0.0;

        foreach ($rows as $i => $row) {
            $typicalPrice = ((float) $row['high'] + (float) $row['low'] + (float) $row['close']) / 3;
            $volume       = (float) ($row['volume'] ?? 0.0);

            $cumulativePv     += $typicalPrice * $volume;
            $cumulativeVolume += $volume;

            $rows[$i]['vwap'] = $cumulativeVolume > 0 ? $cumulativePv / $cumulativeVolume : $typicalPrice;
        }

        return $rows;
    }
}
