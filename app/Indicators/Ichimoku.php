<?php
declare(strict_types=1);
namespace App\Indicators;

use App\Indicators\Contracts\IndicatorInterface;

/**
 * Simplified Ichimoku Cloud — tenkan/kijun/senkou lines computed without the
 * traditional 26-period forward displacement, since nothing consumes this
 * yet. Add displacement when a real chart-rendering consumer needs it.
 * New indicator, not part of the legacy default registry.
 */
final class Ichimoku implements IndicatorInterface
{
    public function __construct(
        private int $tenkanPeriod = 9,
        private int $kijunPeriod = 26,
        private int $senkouBPeriod = 52
    ) {
    }

    public function key(): string
    {
        return 'ichimoku';
    }

    public function apply(array $rows): array
    {
        $highs = array_column($rows, 'high');
        $lows  = array_column($rows, 'low');
        $count = count($rows);

        for ($i = 0; $i < $count; $i++) {
            $tenkan  = $this->midpoint($highs, $lows, $i, $this->tenkanPeriod);
            $kijun   = $this->midpoint($highs, $lows, $i, $this->kijunPeriod);
            $senkouB = $this->midpoint($highs, $lows, $i, $this->senkouBPeriod);

            $rows[$i]['ichimoku_tenkan']   = $tenkan;
            $rows[$i]['ichimoku_kijun']    = $kijun;
            $rows[$i]['ichimoku_senkou_a'] = ($tenkan !== null && $kijun !== null) ? ($tenkan + $kijun) / 2 : null;
            $rows[$i]['ichimoku_senkou_b'] = $senkouB;
        }

        return $rows;
    }

    private function midpoint(array $highs, array $lows, int $index, int $period): ?float
    {
        if ($index + 1 < $period) {
            return null;
        }
        $highSlice = array_slice($highs, $index - $period + 1, $period);
        $lowSlice  = array_slice($lows, $index - $period + 1, $period);
        return (max($highSlice) + min($lowSlice)) / 2;
    }
}
