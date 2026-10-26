<?php
declare(strict_types=1);
namespace App\SmartMoney\Detectors;

use App\SmartMoney\Contracts\StructureDetectorInterface;
use App\SmartMoney\Models\StructureEvent;
use App\Patterns\Support\SwingPointDetector;

/** The last two swing highs (or lows) sitting at nearly the same price — resting liquidity. */
final class EqualHighsLowsDetector implements StructureDetectorInterface
{
    private const TOLERANCE_PCT = 0.0015; // 0.15%
    private const MIN_BARS      = 10;

    public function key(): string
    {
        return 'equal_highs_lows';
    }

    public function label(): string
    {
        return 'Equal Highs/Lows';
    }

    public function detect(array $rows): array
    {
        if (count($rows) < self::MIN_BARS) {
            return [];
        }

        $swings = (new SwingPointDetector())->detect($rows);
        $highs  = SwingPointDetector::onlyHighs($swings);
        $lows   = SwingPointDetector::onlyLows($swings);

        $events = [];

        if (count($highs) >= 2) {
            $h2 = $highs[count($highs) - 1];
            $h1 = $highs[count($highs) - 2];
            $diffPct = abs($h1->price - $h2->price) / $h1->price;
            if ($diffPct <= self::TOLERANCE_PCT) {
                $events[] = new StructureEvent(
                    'equal_highs_lows', 'Equal Highs', 'neutral', 60.0,
                    $h1->index, $h2->index, $h1->timestamp, $h2->timestamp,
                    sprintf('Two swing highs near %.5f and %.5f (%.3f%% apart) — resting buy-side liquidity.', $h1->price, $h2->price, $diffPct * 100),
                    max($h1->price, $h2->price), min($h1->price, $h2->price), false
                );
            }
        }

        if (count($lows) >= 2) {
            $l2 = $lows[count($lows) - 1];
            $l1 = $lows[count($lows) - 2];
            $diffPct = abs($l1->price - $l2->price) / $l1->price;
            if ($diffPct <= self::TOLERANCE_PCT) {
                $events[] = new StructureEvent(
                    'equal_highs_lows', 'Equal Lows', 'neutral', 60.0,
                    $l1->index, $l2->index, $l1->timestamp, $l2->timestamp,
                    sprintf('Two swing lows near %.5f and %.5f (%.3f%% apart) — resting sell-side liquidity.', $l1->price, $l2->price, $diffPct * 100),
                    max($l1->price, $l2->price), min($l1->price, $l2->price), false
                );
            }
        }

        return $events;
    }
}
