<?php
declare(strict_types=1);
namespace App\Indicators;

use App\Indicators\Contracts\IndicatorInterface;
use App\Indicators\Support\SeriesMath;

/** Exponential moving average, extracted verbatim from IndicatorService::emaSeries(). */
final class EMA implements IndicatorInterface
{
    use SeriesMath;

    public function __construct(private int $period, private string $outputKey)
    {
    }

    public function key(): string
    {
        return $this->outputKey;
    }

    public function apply(array $rows): array
    {
        $series = $this->emaSeries(array_column($rows, 'close'), $this->period);

        foreach ($rows as $i => $row) {
            $rows[$i][$this->outputKey] = $series[$i] ?? null;
        }

        return $rows;
    }
}
