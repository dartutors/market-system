<?php
declare(strict_types=1);
namespace App\Patterns\Detectors;

use App\Patterns\Contracts\PatternDetectorInterface;
use App\Patterns\Models\PatternMatch;
use App\Patterns\Models\SwingPoint;
use App\Patterns\Support\SwingPointDetector;

/**
 * A minor retracement within an established trend that hasn't broken the
 * prior structural swing point (a zigzag: structural-low, higher-high,
 * structural-high, retracing now — or the mirror for a downtrend).
 *
 * Deliberately a distinct class from the pre-existing
 * App\MultiTimeframe\PullbackDetector, which assesses "is this timeframe
 * retracing toward a higher-timeframe bias" using SMA/ATR/RSI bands and
 * requires multi-timeframe context — this one is pure chart geometry over
 * a single series, reusable standalone by the Scanner or AI Vision.
 */
final class PullbackPatternDetector implements PatternDetectorInterface
{
    private const MIN_RETRACEMENT_PCT = 0.2;
    private const MAX_RETRACEMENT_PCT = 0.618;
    private const MIN_BARS            = 20;

    public function key(): string
    {
        return 'pullback';
    }

    public function label(): string
    {
        return 'Pullback';
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

        $latestClose = (float) $rows[$count - 1]['close'];

        $uptrend = $this->tryUptrend($rows, $highs, $lows, $latestClose, $count);
        if ($uptrend !== null) {
            return [$uptrend];
        }

        $downtrend = $this->tryDowntrend($rows, $highs, $lows, $latestClose, $count);
        if ($downtrend !== null) {
            return [$downtrend];
        }

        return [];
    }

    /** @param array<int, SwingPoint> $highs @param array<int, SwingPoint> $lows */
    private function tryUptrend(array $rows, array $highs, array $lows, float $latestClose, int $count): ?PatternMatch
    {
        for ($hi = count($highs) - 1; $hi >= 0; $hi--) {
            $h2 = $highs[$hi];
            if ($h2->index >= $count - 1) {
                continue; // need bars after the high for a pullback to exist
            }

            $h1 = null;
            for ($k = $hi - 1; $k >= 0; $k--) {
                if ($highs[$k]->index < $h2->index) {
                    $h1 = $highs[$k];
                    break;
                }
            }
            if ($h1 === null || $h2->price <= $h1->price) {
                continue;
            }

            $l2 = $this->between($lows, $h1->index, $h2->index, wantLowest: true);
            if ($l2 === null) {
                continue;
            }

            $l1 = null;
            foreach ($lows as $low) {
                if ($low->index < $h1->index && ($l1 === null || $low->index > $l1->index)) {
                    $l1 = $low;
                }
            }
            if ($l1 === null || $l2->price <= $l1->price) {
                continue;
            }

            $legRange = $h2->price - $l2->price;
            if ($legRange <= 0.0) {
                continue;
            }

            $retracement = ($h2->price - $latestClose) / $legRange;
            if ($retracement < self::MIN_RETRACEMENT_PCT || $retracement > self::MAX_RETRACEMENT_PCT) {
                continue;
            }
            if ($latestClose < $l2->price) {
                continue; // broke structure — not a healthy pullback
            }

            $confidence = 55.0 + (1.0 - min(1.0, abs($retracement - 0.382) / 0.382)) * 20.0;

            return new PatternMatch(
                'pullback',
                'Pullback',
                'bullish',
                round(min(90.0, max(50.0, $confidence)), 1),
                $l2->index,
                $count - 1,
                $l2->timestamp,
                (int) $rows[$count - 1]['timestamp'],
                sprintf(
                    'Uptrend retracing %.1f%% of the last leg (%.5f -> %.5f), holding above %.5f.',
                    $retracement * 100,
                    $l2->price,
                    $h2->price,
                    $l2->price
                ),
                [$h2->price, $l2->price]
            );
        }
        return null;
    }

    /** @param array<int, SwingPoint> $highs @param array<int, SwingPoint> $lows */
    private function tryDowntrend(array $rows, array $highs, array $lows, float $latestClose, int $count): ?PatternMatch
    {
        for ($li = count($lows) - 1; $li >= 0; $li--) {
            $l2 = $lows[$li];
            if ($l2->index >= $count - 1) {
                continue;
            }

            $l1 = null;
            for ($k = $li - 1; $k >= 0; $k--) {
                if ($lows[$k]->index < $l2->index) {
                    $l1 = $lows[$k];
                    break;
                }
            }
            if ($l1 === null || $l2->price >= $l1->price) {
                continue;
            }

            $h2 = $this->between($highs, $l1->index, $l2->index, wantLowest: false);
            if ($h2 === null) {
                continue;
            }

            $h1 = null;
            foreach ($highs as $high) {
                if ($high->index < $l1->index && ($h1 === null || $high->index > $h1->index)) {
                    $h1 = $high;
                }
            }
            if ($h1 === null || $h2->price >= $h1->price) {
                continue;
            }

            $legRange = $h2->price - $l2->price;
            if ($legRange <= 0.0) {
                continue;
            }

            $retracement = ($latestClose - $l2->price) / $legRange;
            if ($retracement < self::MIN_RETRACEMENT_PCT || $retracement > self::MAX_RETRACEMENT_PCT) {
                continue;
            }
            if ($latestClose > $h2->price) {
                continue;
            }

            $confidence = 55.0 + (1.0 - min(1.0, abs($retracement - 0.382) / 0.382)) * 20.0;

            return new PatternMatch(
                'pullback',
                'Pullback',
                'bearish',
                round(min(90.0, max(50.0, $confidence)), 1),
                $h2->index,
                $count - 1,
                $h2->timestamp,
                (int) $rows[$count - 1]['timestamp'],
                sprintf(
                    'Downtrend retracing %.1f%% of the last leg (%.5f -> %.5f), holding below %.5f.',
                    $retracement * 100,
                    $h2->price,
                    $l2->price,
                    $h2->price
                ),
                [$h2->price, $l2->price]
            );
        }
        return null;
    }

    /** @param array<int, SwingPoint> $points */
    private function between(array $points, int $startIndex, int $endIndex, bool $wantLowest): ?SwingPoint
    {
        $best = null;
        foreach ($points as $p) {
            if ($p->index > $startIndex && $p->index < $endIndex) {
                if ($best === null) {
                    $best = $p;
                } elseif ($wantLowest && $p->price < $best->price) {
                    $best = $p;
                } elseif (!$wantLowest && $p->price > $best->price) {
                    $best = $p;
                }
            }
        }
        return $best;
    }
}
