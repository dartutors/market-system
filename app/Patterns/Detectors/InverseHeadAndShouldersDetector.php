<?php
declare(strict_types=1);
namespace App\Patterns\Detectors;

use App\Patterns\Contracts\PatternDetectorInterface;
use App\Patterns\Models\PatternMatch;
use App\Patterns\Models\SwingPoint;
use App\Patterns\Support\SwingPointDetector;

/** Bullish reversal: mirror of Head & Shoulders — a lower head between two similarly-priced shoulders (lows). */
final class InverseHeadAndShouldersDetector implements PatternDetectorInterface
{
    private const SHOULDER_TOLERANCE_PCT  = 0.015;
    private const HEAD_MIN_PROMINENCE_PCT = 0.003;
    private const NECKLINE_TOLERANCE_PCT  = 0.02;
    private const MIN_BARS                = 30;

    public function key(): string
    {
        return 'inverse_head_and_shoulders';
    }

    public function label(): string
    {
        return 'Inverse Head & Shoulders';
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
        if (count($lows) < 3) {
            return [];
        }

        $s2   = $lows[count($lows) - 1];
        $head = $lows[count($lows) - 2];
        $s1   = $lows[count($lows) - 3];

        if (!($head->price < $s1->price && $head->price < $s2->price)) {
            return [];
        }

        $shoulderDiffPct = abs($s1->price - $s2->price) / $s1->price;
        if ($shoulderDiffPct > self::SHOULDER_TOLERANCE_PCT) {
            return [];
        }

        $headProminence = min(
            ($s1->price - $head->price) / $s1->price,
            ($s2->price - $head->price) / $s2->price
        );
        if ($headProminence < self::HEAD_MIN_PROMINENCE_PCT) {
            return [];
        }

        $t1 = $this->highestBetween($highs, $s1->index, $head->index);
        $t2 = $this->highestBetween($highs, $head->index, $s2->index);
        if ($t1 === null || $t2 === null) {
            return [];
        }

        $necklineDiffPct = abs($t1->price - $t2->price) / $t1->price;
        if ($necklineDiffPct > self::NECKLINE_TOLERANCE_PCT) {
            return [];
        }

        $necklineAvg = ($t1->price + $t2->price) / 2;

        $confirmed = false;
        for ($i = $s2->index + 1; $i < $count; $i++) {
            if ((float) $rows[$i]['close'] > $necklineAvg) {
                $confirmed = true;
                break;
            }
        }

        $confidence = 50.0
            + (max(0.0, self::SHOULDER_TOLERANCE_PCT - $shoulderDiffPct) / self::SHOULDER_TOLERANCE_PCT) * 15.0
            + (max(0.0, self::NECKLINE_TOLERANCE_PCT - $necklineDiffPct) / self::NECKLINE_TOLERANCE_PCT) * 10.0
            + ($confirmed ? 20.0 : 0.0);

        return [new PatternMatch(
            'inverse_head_and_shoulders',
            'Inverse Head & Shoulders',
            'bullish',
            round(min(95.0, $confidence), 1),
            $s1->index,
            $count - 1,
            $s1->timestamp,
            (int) $rows[$count - 1]['timestamp'],
            sprintf(
                'Left shoulder %.5f, head %.5f, right shoulder %.5f, neckline ~%.5f%s.',
                $s1->price,
                $head->price,
                $s2->price,
                $necklineAvg,
                $confirmed ? ' — confirmed by a close above the neckline' : ''
            ),
            [$s1->price, $head->price, $s2->price, $necklineAvg]
        )];
    }

    /** @param array<int, SwingPoint> $highs */
    private function highestBetween(array $highs, int $startIndex, int $endIndex): ?SwingPoint
    {
        $best = null;
        foreach ($highs as $high) {
            if ($high->index > $startIndex && $high->index < $endIndex
                && ($best === null || $high->price > $best->price)) {
                $best = $high;
            }
        }
        return $best;
    }
}
