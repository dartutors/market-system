<?php
declare(strict_types=1);
namespace App\Indicators;

use App\Indicators\Contracts\IndicatorInterface;
use App\Indicators\Support\SeriesMath;

/** Extracted verbatim from IndicatorService::atrSeries() (SMA of true range, not Wilder-smoothed). */
final class ATR implements IndicatorInterface
{
    use SeriesMath;

    public function __construct(private int $period = 14, private string $outputKey = 'atr_14')
    {
    }

    public function key(): string
    {
        return $this->outputKey;
    }

    public function apply(array $rows): array
    {
        $highs  = array_column($rows, 'high');
        $lows   = array_column($rows, 'low');
        $closes = array_column($rows, 'close');

        $tr = [];
        foreach ($highs as $i => $high) {
            if ($i === 0) {
                $tr[$i] = $high - $lows[$i];
                continue;
            }
            $hl     = $high - $lows[$i];
            $hc     = abs($high - $closes[$i - 1]);
            $lc     = abs($lows[$i] - $closes[$i - 1]);
            $tr[$i] = max($hl, $hc, $lc);
        }

        $series = $this->smaSeries($tr, $this->period);

        foreach ($rows as $i => $row) {
            $rows[$i][$this->outputKey] = $series[$i] ?? 0.0;
        }

        return $rows;
    }
}
