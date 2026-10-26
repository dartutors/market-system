<?php
declare(strict_types=1);
namespace App\Indicators;

use App\Indicators\Contracts\IndicatorInterface;
use App\Indicators\Support\SeriesMath;

/**
 * Extracted verbatim from IndicatorService::macdSeries(). Recomputes its own
 * EMA12/EMA26 internally rather than reusing the row-level ema_12/ema_26
 * columns — same harmless duplication the pre-refactor code had.
 */
final class MACD implements IndicatorInterface
{
    use SeriesMath;

    public function __construct(
        private int $fastPeriod = 12,
        private int $slowPeriod = 26,
        private int $signalPeriod = 9
    ) {
    }

    public function key(): string
    {
        return 'macd';
    }

    public function apply(array $rows): array
    {
        $closes  = array_column($rows, 'close');
        $emaFast = $this->emaSeries($closes, $this->fastPeriod);
        $emaSlow = $this->emaSeries($closes, $this->slowPeriod);

        $macd = [];
        foreach ($closes as $i => $_) {
            $macd[$i] = ($emaFast[$i] ?? 0.0) - ($emaSlow[$i] ?? 0.0);
        }
        $signal = $this->emaSeries($macd, $this->signalPeriod);

        $hist = [];
        foreach ($macd as $i => $v) {
            $hist[$i] = $v - ($signal[$i] ?? 0.0);
        }

        foreach ($rows as $i => $row) {
            $rows[$i]['macd_line']   = $macd[$i]   ?? 0.0;
            $rows[$i]['macd_signal'] = $signal[$i] ?? 0.0;
            $rows[$i]['macd_hist']   = $hist[$i]   ?? 0.0;
        }

        return $rows;
    }
}
