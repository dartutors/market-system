<?php
declare(strict_types=1);
namespace App\Patterns\Detectors;

use App\Patterns\Contracts\PatternDetectorInterface;
use App\Patterns\Models\PatternMatch;
use App\Patterns\Support\SwingPointDetector;

/** Bullish reversal: mirror of Double Top — two similarly-priced swing lows separated by a neckline peak. */
final class DoubleBottomDetector implements PatternDetectorInterface
{
    private const TROUGH_TOLERANCE_PCT = 0.006;
    private const MIN_DEPTH_PCT        = 0.004;
    private const MIN_BARS             = 20;

    public function key(): string
    {
        return 'double_bottom';
    }

    public function label(): string
    {
        return 'Double Bottom';
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
        if (count($lows) < 2) {
            return [];
        }

        $l2 = $lows[count($lows) - 1];
        $l1 = $lows[count($lows) - 2];

        $neckline = null;
        foreach ($highs as $high) {
            if ($high->index > $l1->index && $high->index < $l2->index
                && ($neckline === null || $high->price > $neckline->price)) {
                $neckline = $high;
            }
        }
        if ($neckline === null) {
            return [];
        }

        $troughDiffPct = abs($l1->price - $l2->price) / $l1->price;
        if ($troughDiffPct > self::TROUGH_TOLERANCE_PCT) {
            return [];
        }

        $avgTrough = ($l1->price + $l2->price) / 2;
        $depthPct  = ($neckline->price - $avgTrough) / $neckline->price;
        if ($depthPct < self::MIN_DEPTH_PCT) {
            return [];
        }

        $confirmed = false;
        for ($i = $l2->index + 1; $i < $count; $i++) {
            if ((float) $rows[$i]['close'] > $neckline->price) {
                $confirmed = true;
                break;
            }
        }

        $confidence = 55.0
            + (max(0.0, self::TROUGH_TOLERANCE_PCT - $troughDiffPct) / self::TROUGH_TOLERANCE_PCT) * 20.0
            + ($confirmed ? 15.0 : 0.0);

        return [new PatternMatch(
            'double_bottom',
            'Double Bottom',
            'bullish',
            round(min(95.0, $confidence), 1),
            $l1->index,
            $count - 1,
            $l1->timestamp,
            (int) $rows[$count - 1]['timestamp'],
            sprintf(
                'Two troughs near %.5f and %.5f (%.2f%% apart) with a neckline at %.5f%s.',
                $l1->price,
                $l2->price,
                $troughDiffPct * 100,
                $neckline->price,
                $confirmed ? ' — confirmed by a close above the neckline' : ''
            ),
            [$l1->price, $l2->price, $neckline->price]
        )];
    }
}
