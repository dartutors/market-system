<?php
declare(strict_types=1);
namespace App\Indicators;

use App\Indicators\Contracts\IndicatorInterface;
use App\Indicators\Support\SeriesMath;

/**
 * New indicator, not part of the legacy default registry — available for
 * future use, does not affect current signal logic until registered.
 */
final class BollingerBands implements IndicatorInterface
{
    use SeriesMath;

    public function __construct(private int $period = 20, private float $stdDevMultiplier = 2.0)
    {
    }

    public function key(): string
    {
        return 'bollinger_bands';
    }

    public function apply(array $rows): array
    {
        $closes = array_column($rows, 'close');
        $middle = $this->smaSeries($closes, $this->period);
        $count  = count($closes);

        for ($i = 0; $i < $count; $i++) {
            if ($middle[$i] === null) {
                $rows[$i]['bb_upper']  = null;
                $rows[$i]['bb_middle'] = null;
                $rows[$i]['bb_lower']  = null;
                continue;
            }

            $slice    = array_slice($closes, $i - $this->period + 1, $this->period);
            $mean     = $middle[$i];
            $variance = array_sum(array_map(static fn($v) => ($v - $mean) ** 2, $slice)) / $this->period;
            $stdDev   = sqrt($variance);

            $rows[$i]['bb_middle'] = $mean;
            $rows[$i]['bb_upper']  = $mean + ($this->stdDevMultiplier * $stdDev);
            $rows[$i]['bb_lower']  = $mean - ($this->stdDevMultiplier * $stdDev);
        }

        return $rows;
    }
}
