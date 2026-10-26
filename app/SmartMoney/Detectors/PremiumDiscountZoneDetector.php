<?php
declare(strict_types=1);
namespace App\SmartMoney\Detectors;

use App\Patterns\Support\SwingPointDetector;
use App\SmartMoney\Contracts\StructureDetectorInterface;
use App\SmartMoney\Models\StructureEvent;

/** Splits the current dealing range (last swing high to last swing low) at its midpoint — SMC convention: buy in discount, sell in premium. */
final class PremiumDiscountZoneDetector implements StructureDetectorInterface
{
    private const MIN_BARS = 10;

    public function key(): string
    {
        return 'premium_discount';
    }

    public function label(): string
    {
        return 'Premium/Discount Zones';
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
        if (count($highs) < 1 || count($lows) < 1) {
            return [];
        }

        $lastHigh = $highs[count($highs) - 1];
        $lastLow  = $lows[count($lows) - 1];

        $top    = max($lastHigh->price, $lastLow->price);
        $bottom = min($lastHigh->price, $lastLow->price);
        if ($top <= $bottom) {
            return [];
        }

        $midpoint    = ($top + $bottom) / 2;
        $latestClose = (float) $rows[$count - 1]['close'];
        $zone        = $latestClose > $midpoint ? 'premium' : 'discount';
        $direction   = $zone === 'premium' ? 'bearish' : 'bullish';
        $startIndex  = min($lastHigh->index, $lastLow->index);

        return [new StructureEvent(
            'premium_discount',
            $zone === 'premium' ? 'Premium Zone' : 'Discount Zone',
            $direction,
            55.0,
            $startIndex,
            $count - 1,
            (int) $rows[$startIndex]['timestamp'],
            (int) $rows[$count - 1]['timestamp'],
            sprintf(
                'Current price %.5f sits in the %s half of the dealing range %.5f - %.5f (midpoint %.5f).',
                $latestClose,
                $zone,
                $bottom,
                $top,
                $midpoint
            ),
            $top,
            $bottom,
            false
        )];
    }
}
