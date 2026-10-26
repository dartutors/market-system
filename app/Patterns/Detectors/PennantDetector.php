<?php
declare(strict_types=1);
namespace App\Patterns\Detectors;

use App\Patterns\Contracts\PatternDetectorInterface;
use App\Patterns\Models\PatternMatch;
use App\Patterns\Support\LinearRegression;

/** A sharp directional "pole" move followed by a small converging (triangle-like) consolidation. */
final class PennantDetector implements PatternDetectorInterface
{
    private const POLE_BARS           = 10;
    private const TAIL_BARS           = 8;
    private const POLE_MIN_MOVE_PCT   = 0.02;
    private const MIN_CONVERGENCE_PCT = 0.3;
    private const MIN_BARS            = 25;

    public function key(): string
    {
        return 'pennant';
    }

    public function label(): string
    {
        return 'Pennant';
    }

    public function detect(array $rows): array
    {
        $count = count($rows);
        if ($count < self::MIN_BARS) {
            return [];
        }

        $tailStart = $count - self::TAIL_BARS;
        $poleStart = $tailStart - self::POLE_BARS;
        if ($poleStart < 0) {
            return [];
        }

        $poleOpen  = (float) $rows[$poleStart]['close'];
        $poleClose = (float) $rows[$tailStart - 1]['close'];
        if ($poleOpen <= 0.0) {
            return [];
        }
        $poleChangePct = ($poleClose - $poleOpen) / $poleOpen;
        if (abs($poleChangePct) < self::POLE_MIN_MOVE_PCT) {
            return [];
        }

        $tail = array_slice($rows, $tailStart);
        $xs   = array_map(static fn($x): float => (float) $x, range($tailStart, $count - 1));
        $highsY = array_map(static fn($r) => (float) $r['high'], $tail);
        $lowsY  = array_map(static fn($r) => (float) $r['low'], $tail);

        $fitHigh = LinearRegression::fit($xs, $highsY);
        $fitLow  = LinearRegression::fit($xs, $lowsY);

        $startX = (float) $tailStart;
        $endX   = (float) ($count - 1);
        $widthStart = LinearRegression::valueAt($fitHigh, $startX) - LinearRegression::valueAt($fitLow, $startX);
        $widthEnd   = LinearRegression::valueAt($fitHigh, $endX) - LinearRegression::valueAt($fitLow, $endX);
        if ($widthStart <= 0.0 || $widthEnd <= 0.0) {
            return [];
        }

        $convergencePct = ($widthStart - $widthEnd) / $widthStart;
        if ($convergencePct < self::MIN_CONVERGENCE_PCT) {
            return [];
        }

        $direction  = $poleChangePct > 0 ? 'bullish' : 'bearish';
        $confidence = 55.0
            + min(1.0, abs($poleChangePct) / 0.05) * 15.0
            + min(1.0, $convergencePct) * 15.0;

        return [new PatternMatch(
            'pennant',
            'Pennant',
            $direction,
            round(min(90.0, $confidence), 1),
            $poleStart,
            $count - 1,
            (int) $rows[$poleStart]['timestamp'],
            (int) $rows[$count - 1]['timestamp'],
            sprintf(
                '%.2f%% pole over %d bars followed by a converging consolidation (narrowed %.1f%%).',
                $poleChangePct * 100,
                self::POLE_BARS,
                $convergencePct * 100
            ),
            [LinearRegression::valueAt($fitHigh, $endX), LinearRegression::valueAt($fitLow, $endX)]
        )];
    }
}
