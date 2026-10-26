<?php
declare(strict_types=1);
namespace App\Patterns\Detectors;

use App\Patterns\Contracts\PatternDetectorInterface;
use App\Patterns\Models\PatternMatch;
use App\Patterns\Support\SwingPointDetector;

/** Bullish continuation: a rounded bottom (cup) between two similarly-priced rims, followed by a shallower pullback (handle). */
final class CupAndHandleDetector implements PatternDetectorInterface
{
    private const RIM_TOLERANCE_PCT      = 0.02; // 2%
    private const MIN_CUP_DEPTH_PCT      = 0.06; // 6%
    private const MAX_HANDLE_DEPTH_RATIO = 0.5;  // handle depth must be shallower than the cup
    private const MIN_BARS               = 40;

    public function key(): string
    {
        return 'cup_and_handle';
    }

    public function label(): string
    {
        return 'Cup & Handle';
    }

    public function detect(array $rows): array
    {
        $count = count($rows);
        if ($count < self::MIN_BARS) {
            return [];
        }

        $swings = (new SwingPointDetector())->detect($rows);
        $lows   = SwingPointDetector::onlyLows($swings);
        $highs  = SwingPointDetector::onlyHighs($swings);
        if (count($lows) < 1 || count($highs) < 2) {
            return [];
        }

        // Cup bottom: the lowest swing low, leaving room after it for a handle.
        $cupSearchEnd = (int) floor($count * 0.8);
        $cupBottom = null;
        foreach ($lows as $low) {
            if ($low->index <= $cupSearchEnd && ($cupBottom === null || $low->price < $cupBottom->price)) {
                $cupBottom = $low;
            }
        }
        if ($cupBottom === null) {
            return [];
        }

        $leftRim = null;
        foreach ($highs as $high) {
            if ($high->index < $cupBottom->index) {
                $leftRim = $high; // highs are chronological — last one found is nearest to the cup
            }
        }
        $rightRim = null;
        foreach ($highs as $high) {
            if ($high->index > $cupBottom->index && $rightRim === null) {
                $rightRim = $high; // first one found after the cup
            }
        }
        if ($leftRim === null || $rightRim === null) {
            return [];
        }

        $rimDiffPct = abs($leftRim->price - $rightRim->price) / $leftRim->price;
        if ($rimDiffPct > self::RIM_TOLERANCE_PCT) {
            return [];
        }

        $rimAvg      = ($leftRim->price + $rightRim->price) / 2;
        $cupDepthPct = ($rimAvg - $cupBottom->price) / $rimAvg;
        if ($cupDepthPct < self::MIN_CUP_DEPTH_PCT) {
            return [];
        }

        $handleLow = null;
        foreach ($lows as $low) {
            if ($low->index > $rightRim->index && ($handleLow === null || $low->price < $handleLow->price)) {
                $handleLow = $low;
            }
        }
        if ($handleLow === null) {
            return [];
        }

        $handleDepthPct = ($rightRim->price - $handleLow->price) / $rightRim->price;
        if ($handleDepthPct <= 0.0 || $handleDepthPct > $cupDepthPct * self::MAX_HANDLE_DEPTH_RATIO) {
            return [];
        }

        $latestClose = (float) $rows[$count - 1]['close'];
        if ($latestClose < $handleLow->price) {
            return []; // handle broke down — no longer a live setup
        }

        $confidence = 55.0
            + (1.0 - min(1.0, $rimDiffPct / self::RIM_TOLERANCE_PCT)) * 15.0
            + min(1.0, $cupDepthPct / 0.15) * 15.0;

        return [new PatternMatch(
            'cup_and_handle',
            'Cup & Handle',
            'bullish',
            round(min(90.0, $confidence), 1),
            $leftRim->index,
            $count - 1,
            $leftRim->timestamp,
            (int) $rows[$count - 1]['timestamp'],
            sprintf(
                'Rounded bottom at %.5f between rims %.5f/%.5f (%.1f%% deep), handle pulling back to %.5f.',
                $cupBottom->price,
                $leftRim->price,
                $rightRim->price,
                $cupDepthPct * 100,
                $handleLow->price
            ),
            [$leftRim->price, $cupBottom->price, $rightRim->price, $handleLow->price]
        )];
    }
}
