<?php
declare(strict_types=1);
namespace App\SmartMoney\Support;

use App\Patterns\Models\SwingPoint;

/**
 * Determines the prevailing market structure direction from the last two
 * swing highs and last two swing lows — higher-high + higher-low = bullish
 * structure, lower-high + lower-low = bearish structure. Shared by
 * BreakOfStructureDetector and ChangeOfCharacterDetector, which both need to
 * know "what trend is currently established" before classifying a break as
 * continuation (BOS) or reversal (CHoCH).
 */
final class StructureDirectionResolver
{
    /**
     * @param array<int, SwingPoint> $highs
     * @param array<int, SwingPoint> $lows
     */
    public static function resolve(array $highs, array $lows): ?string
    {
        if (count($highs) < 2 || count($lows) < 2) {
            return null;
        }

        $h2 = $highs[count($highs) - 1];
        $h1 = $highs[count($highs) - 2];
        $l2 = $lows[count($lows) - 1];
        $l1 = $lows[count($lows) - 2];

        if ($h2->price > $h1->price && $l2->price > $l1->price) {
            return 'bullish';
        }
        if ($h2->price < $h1->price && $l2->price < $l1->price) {
            return 'bearish';
        }
        return null;
    }
}
