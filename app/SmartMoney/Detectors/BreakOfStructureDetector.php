<?php
declare(strict_types=1);
namespace App\SmartMoney\Detectors;

use App\Patterns\Support\SwingPointDetector;
use App\SmartMoney\Contracts\StructureDetectorInterface;
use App\SmartMoney\Models\StructureEvent;
use App\SmartMoney\Support\StructureDirectionResolver;

/** Price closes beyond the prior swing high/low IN THE DIRECTION of the already-established structure — trend continuation. */
final class BreakOfStructureDetector implements StructureDetectorInterface
{
    private const MIN_BARS = 10;

    public function key(): string
    {
        return 'bos';
    }

    public function label(): string
    {
        return 'Break of Structure';
    }

    public function detect(array $rows): array
    {
        $count = count($rows);
        if ($count < self::MIN_BARS) {
            return [];
        }

        $swings = (new SwingPointDetector())->detect($rows);
        $highs  = SwingPointDetector::onlyHighs($swings);
        $lows   = SwingPointDetector::onlyLows($swings);

        $direction = StructureDirectionResolver::resolve($highs, $lows);
        if ($direction === null) {
            return [];
        }

        $level = $direction === 'bullish' ? $highs[count($highs) - 1] : $lows[count($lows) - 1];

        for ($i = $level->index + 1; $i < $count; $i++) {
            $close = (float) $rows[$i]['close'];
            $broke = $direction === 'bullish' ? $close > $level->price : $close < $level->price;
            if (!$broke) {
                continue;
            }

            $label = $direction === 'bullish' ? 'Bullish Break of Structure' : 'Bearish Break of Structure';
            $verb  = $direction === 'bullish' ? 'above the prior swing high' : 'below the prior swing low';

            return [new StructureEvent(
                'bos',
                $label,
                $direction,
                65.0,
                $level->index,
                $i,
                $level->timestamp,
                (int) $rows[$i]['timestamp'],
                sprintf('Price closed %s %.5f, continuing the %s structure.', $verb, $level->price, $direction),
                null,
                null,
                false
            )];
        }

        return [];
    }
}
