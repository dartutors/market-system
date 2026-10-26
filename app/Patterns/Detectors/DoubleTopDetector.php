<?php
declare(strict_types=1);
namespace App\Patterns\Detectors;

use App\Patterns\Contracts\PatternDetectorInterface;
use App\Patterns\Models\PatternMatch;
use App\Patterns\Support\SwingPointDetector;

/** Bearish reversal: two similarly-priced swing highs separated by a neckline trough. */
final class DoubleTopDetector implements PatternDetectorInterface
{
    private const PEAK_TOLERANCE_PCT = 0.006; // 0.6% — how "equal" the two peaks must be
    private const MIN_DEPTH_PCT      = 0.004; // 0.4% — neckline must be a real dip, not noise
    private const MIN_BARS           = 20;

    public function key(): string
    {
        return 'double_top';
    }

    public function label(): string
    {
        return 'Double Top';
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
        if (count($highs) < 2) {
            return [];
        }

        $h2 = $highs[count($highs) - 1];
        $h1 = $highs[count($highs) - 2];

        $neckline = null;
        foreach ($lows as $low) {
            if ($low->index > $h1->index && $low->index < $h2->index
                && ($neckline === null || $low->price < $neckline->price)) {
                $neckline = $low;
            }
        }
        if ($neckline === null) {
            return [];
        }

        $peakDiffPct = abs($h1->price - $h2->price) / $h1->price;
        if ($peakDiffPct > self::PEAK_TOLERANCE_PCT) {
            return [];
        }

        $avgPeak  = ($h1->price + $h2->price) / 2;
        $depthPct = ($avgPeak - $neckline->price) / $avgPeak;
        if ($depthPct < self::MIN_DEPTH_PCT) {
            return [];
        }

        $confirmed = false;
        for ($i = $h2->index + 1; $i < $count; $i++) {
            if ((float) $rows[$i]['close'] < $neckline->price) {
                $confirmed = true;
                break;
            }
        }

        $confidence = 55.0
            + (max(0.0, self::PEAK_TOLERANCE_PCT - $peakDiffPct) / self::PEAK_TOLERANCE_PCT) * 20.0
            + ($confirmed ? 15.0 : 0.0);

        return [new PatternMatch(
            'double_top',
            'Double Top',
            'bearish',
            round(min(95.0, $confidence), 1),
            $h1->index,
            $count - 1,
            $h1->timestamp,
            (int) $rows[$count - 1]['timestamp'],
            sprintf(
                'Two peaks near %.5f and %.5f (%.2f%% apart) with a neckline at %.5f%s.',
                $h1->price,
                $h2->price,
                $peakDiffPct * 100,
                $neckline->price,
                $confirmed ? ' — confirmed by a close below the neckline' : ''
            ),
            [$h1->price, $h2->price, $neckline->price]
        )];
    }
}
