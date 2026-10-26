<?php
declare(strict_types=1);
namespace App\Indicators;

use App\Indicators\Contracts\IndicatorInterface;

/** Extracted verbatim from IndicatorService::rsiSeries(). */
final class RSI implements IndicatorInterface
{
    public function __construct(private int $period = 14, private string $outputKey = 'rsi_14')
    {
    }

    public function key(): string
    {
        return $this->outputKey;
    }

    public function apply(array $rows): array
    {
        $series = $this->rsiSeries(array_column($rows, 'close'), $this->period);

        foreach ($rows as $i => $row) {
            $rows[$i][$this->outputKey] = $series[$i] ?? 50.0;
        }

        return $rows;
    }

    private function rsiSeries(array $values, int $period): array
    {
        $out    = [0 => 50.0];
        $gains  = [];
        $losses = [];

        for ($i = 1, $count = count($values); $i < $count; $i++) {
            $delta    = $values[$i] - $values[$i - 1];
            $gains[]  = max($delta, 0);
            $losses[] = max(-$delta, 0);

            if ($i < $period) {
                $out[$i] = 50.0;
                continue;
            }

            $avgGain = array_sum(array_slice($gains, -$period)) / $period;
            $avgLoss = array_sum(array_slice($losses, -$period)) / $period;
            $rs      = $avgLoss <= 0 ? 999 : $avgGain / $avgLoss;
            $out[$i] = 100 - (100 / (1 + $rs));
        }

        ksort($out);
        return $out;
    }
}
