<?php
declare(strict_types=1);
namespace App\SmartMoney\Detectors;

use App\Patterns\Support\SwingPointDetector;
use App\SmartMoney\Contracts\StructureDetectorInterface;
use App\SmartMoney\Models\StructureEvent;
use App\SmartMoney\Support\StructureDirectionResolver;

/** The FIRST break AGAINST the established structure — price closes past the swing point that defined the current trend, the earliest sign of a possible reversal. */
final class ChangeOfCharacterDetector implements StructureDetectorInterface
{
    private const MIN_BARS = 10;

    public function key(): string
    {
        return 'choch';
    }

    public function label(): string
    {
        return 'Change of Character';
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

        // Bullish structure -> CHoCH breaks the defining higher-low. Bearish structure -> breaks the defining lower-high.
        $level = $direction === 'bullish' ? $lows[count($lows) - 1] : $highs[count($highs) - 1];
        $reversalDirection = $direction === 'bullish' ? 'bearish' : 'bullish';

        for ($i = $level->index + 1; $i < $count; $i++) {
            $close = (float) $rows[$i]['close'];
            $broke = $reversalDirection === 'bearish' ? $close < $level->price : $close > $level->price;
            if (!$broke) {
                continue;
            }

            $label      = $reversalDirection === 'bearish' ? 'Bearish Change of Character' : 'Bullish Change of Character';
            $structure  = $direction === 'bullish' ? 'higher-low that defined the uptrend' : 'lower-high that defined the downtrend';
            $verb       = $reversalDirection === 'bearish' ? 'below' : 'above';

            return [new StructureEvent(
                'choch',
                $label,
                $reversalDirection,
                60.0,
                $level->index,
                $i,
                $level->timestamp,
                (int) $rows[$i]['timestamp'],
                sprintf('Price closed %s the %s (%.5f) — first sign of a possible reversal.', $verb, $structure, $level->price),
                null,
                null,
                false
            )];
        }

        return [];
    }
}
