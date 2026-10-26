<?php
declare(strict_types=1);
namespace App\Patterns\Support;

use App\Patterns\Models\SwingPoint;

/**
 * Fractal-style local extrema: bar i is a swing high when its high is the
 * maximum within a +/-$window bar neighborhood (swing low is the mirror,
 * using lows). This is the one primitive every pattern detector in
 * App\Patterns builds on — confirmed via research that no such primitive
 * (pivot/swing/local-extrema) existed anywhere in this codebase before.
 *
 * Ties (two bars sharing the exact same extreme value) both qualify —
 * deliberately permissive, since downstream detectors already apply their
 * own price-tolerance bands and would otherwise miss patterns built on
 * flat-topped consolidation.
 */
final class SwingPointDetector
{
    public function __construct(private readonly int $window = 2)
    {
    }

    /**
     * @param array<int, array{high: float, low: float, timestamp: int}> $rows
     * @return array<int, SwingPoint> In chronological order.
     */
    public function detect(array $rows): array
    {
        $count = count($rows);
        $points = [];

        for ($i = $this->window; $i < $count - $this->window; $i++) {
            $high = (float) $rows[$i]['high'];
            $low  = (float) $rows[$i]['low'];
            $timestamp = (int) $rows[$i]['timestamp'];

            $isHigh = true;
            $isLow  = true;
            for ($j = $i - $this->window; $j <= $i + $this->window; $j++) {
                if ($j === $i) {
                    continue;
                }
                if ((float) $rows[$j]['high'] > $high) {
                    $isHigh = false;
                }
                if ((float) $rows[$j]['low'] < $low) {
                    $isLow = false;
                }
            }

            if ($isHigh) {
                $points[] = new SwingPoint($i, $timestamp, $high, 'high');
            }
            if ($isLow) {
                $points[] = new SwingPoint($i, $timestamp, $low, 'low');
            }
        }

        return $points;
    }

    /** @param array<int, SwingPoint> $points @return array<int, SwingPoint> */
    public static function onlyHighs(array $points): array
    {
        return array_values(array_filter($points, static fn(SwingPoint $p): bool => $p->type === 'high'));
    }

    /** @param array<int, SwingPoint> $points @return array<int, SwingPoint> */
    public static function onlyLows(array $points): array
    {
        return array_values(array_filter($points, static fn(SwingPoint $p): bool => $p->type === 'low'));
    }
}
