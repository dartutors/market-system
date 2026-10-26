<?php
declare(strict_types=1);
namespace App\Patterns\Detectors;

use App\Patterns\Contracts\PatternDetectorInterface;
use App\Patterns\Models\PatternMatch;
use App\Patterns\Support\SwingPointDetector;

/**
 * Flat top and flat bottom with at least two confirmed touches on each side
 * — a tighter, more deliberate version of Range (which only checks overall
 * price compression, no touch-count requirement).
 */
final class RectangleDetector implements PatternDetectorInterface
{
    private const TOUCH_TOLERANCE_PCT = 0.005; // 0.5% — how close touches on the same side must be
    private const MIN_HEIGHT_PCT      = 0.003; // top and bottom must be genuinely apart
    private const MIN_BARS            = 25;

    public function key(): string
    {
        return 'rectangle';
    }

    public function label(): string
    {
        return 'Rectangle';
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
        if (count($highs) < 2 || count($lows) < 2) {
            return [];
        }

        $highsUsed = array_slice($highs, -4);
        $lowsUsed  = array_slice($lows, -4);

        $topPrices    = array_map(static fn($p) => $p->price, $highsUsed);
        $bottomPrices = array_map(static fn($p) => $p->price, $lowsUsed);

        $topAvg    = array_sum($topPrices) / count($topPrices);
        $bottomAvg = array_sum($bottomPrices) / count($bottomPrices);

        $topSpreadPct    = (max($topPrices) - min($topPrices)) / $topAvg;
        $bottomSpreadPct = (max($bottomPrices) - min($bottomPrices)) / $bottomAvg;
        if ($topSpreadPct > self::TOUCH_TOLERANCE_PCT || $bottomSpreadPct > self::TOUCH_TOLERANCE_PCT) {
            return [];
        }

        $heightPct = ($topAvg - $bottomAvg) / $topAvg;
        if ($heightPct < self::MIN_HEIGHT_PCT) {
            return [];
        }

        $startIndex = min($highsUsed[0]->index, $lowsUsed[0]->index);

        $confidence = 60.0
            + (1.0 - min(1.0, $topSpreadPct / self::TOUCH_TOLERANCE_PCT)) * 15.0
            + (1.0 - min(1.0, $bottomSpreadPct / self::TOUCH_TOLERANCE_PCT)) * 15.0;

        return [new PatternMatch(
            'rectangle',
            'Rectangle',
            'neutral',
            round(min(90.0, $confidence), 1),
            $startIndex,
            $count - 1,
            (int) $rows[$startIndex]['timestamp'],
            (int) $rows[$count - 1]['timestamp'],
            sprintf(
                'Resistance ~%.5f (%d touches) and support ~%.5f (%d touches), range height %.2f%%.',
                $topAvg,
                count($highsUsed),
                $bottomAvg,
                count($lowsUsed),
                $heightPct * 100
            ),
            [$topAvg, $bottomAvg]
        )];
    }
}
