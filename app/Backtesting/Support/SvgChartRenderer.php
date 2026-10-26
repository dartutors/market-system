<?php
declare(strict_types=1);
namespace App\Backtesting\Support;

/**
 * Server-side SVG markup generation for Phase 3.1's three chart needs
 * (equity curve, Monte Carlo percentile band, trade-replay candle window).
 * Deliberate architectural choice (confirmed with the user): plain
 * server-rendered inline SVG rather than a JS charting library — this app
 * has zero client-side charting anywhere else, and every other page is
 * server-rendered HTML/CSS only. Colors are set via the existing CSS custom
 * properties (--green/--red/--yellow/--surf-2/...) so the charts stay
 * theme-consistent with the rest of the app for free.
 */
final class SvgChartRenderer
{
    /**
     * @param array<int, array{index:int, timestamp:int, equity:float, drawdown:float}> $points
     */
    public static function equityCurve(array $points, int $width = 760, int $height = 220): string
    {
        if (empty($points)) {
            return '<div class="abt-chart-empty">No trades to plot.</div>';
        }

        $equities = array_column($points, 'equity');
        $min      = min(0.0, min($equities));
        $max      = max(0.0, max($equities));
        if (abs($max - $min) < 1e-9) {
            $max += 1.0;
        }
        $pad = ($max - $min) * 0.08;
        $min -= $pad;
        $max += $pad;

        $n       = \count($points);
        $plotW   = $width - 40;
        $plotH   = $height - 30;
        $toX     = static fn(int $i): float => 20 + ($n <= 1 ? 0.0 : ($i / ($n - 1)) * $plotW);
        $toY     = static fn(float $eq): float => 10 + $plotH - (($eq - $min) / ($max - $min)) * $plotH;

        $linePts = [];
        $areaPts = [];
        foreach (array_values($points) as $i => $p) {
            $x = round($toX($i), 1);
            $y = round($toY((float) $p['equity']), 1);
            $linePts[] = "{$x},{$y}";
            $areaPts[] = "{$x},{$y}";
        }
        $zeroY     = round($toY(0.0), 1);
        $areaPath  = '20,' . $zeroY . ' ' . implode(' ', $areaPts) . ' ' . round($toX($n - 1), 1) . ',' . $zeroY;
        $final     = (float) end($points)['equity'];
        $lineColor = $final >= 0 ? 'var(--green)' : 'var(--red)';

        return '<svg class="abt-chart" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none">'
            . '<line x1="20" y1="' . $zeroY . '" x2="' . ($width - 20) . '" y2="' . $zeroY . '" stroke="var(--surf-2)" stroke-width="1" stroke-dasharray="4,3" />'
            . '<polygon points="' . $areaPath . '" fill="' . $lineColor . '" opacity="0.12" />'
            . '<polyline points="' . implode(' ', $linePts) . '" fill="none" stroke="' . $lineColor . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />'
            . '</svg>';
    }

    /**
     * A horizontal box-and-whisker style bar for one percentile distribution
     * (p5-p95 whisker, p25-p75 box, p50 tick, 0 reference line).
     *
     * @param array{p5:float,p25:float,p50:float,p75:float,p95:float} $percentiles
     */
    public static function percentileBar(array $percentiles, int $width = 760, int $height = 70): string
    {
        $values = array_values($percentiles);
        $min    = min(0.0, min($values));
        $max    = max(0.0, max($values));
        if (abs($max - $min) < 1e-9) {
            $max += 1.0;
        }
        $pad = ($max - $min) * 0.1;
        $min -= $pad;
        $max += $pad;

        $plotW = $width - 40;
        $toX   = static fn(float $v): float => 20 + (($v - $min) / ($max - $min)) * $plotW;
        $midY  = (int) round($height / 2);

        $x5   = round($toX($percentiles['p5']), 1);
        $x25  = round($toX($percentiles['p25']), 1);
        $x50  = round($toX($percentiles['p50']), 1);
        $x75  = round($toX($percentiles['p75']), 1);
        $x95  = round($toX($percentiles['p95']), 1);
        $xZero = round($toX(0.0), 1);
        $boxColor = $percentiles['p50'] >= 0 ? 'var(--green)' : 'var(--red)';

        return '<svg class="abt-chart abt-chart--bar" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none">'
            . '<line x1="' . $xZero . '" y1="6" x2="' . $xZero . '" y2="' . ($height - 6) . '" stroke="var(--surf-2)" stroke-width="1" stroke-dasharray="3,3" />'
            . '<line x1="' . $x5 . '" y1="' . $midY . '" x2="' . $x95 . '" y2="' . $midY . '" stroke="' . $boxColor . '" stroke-width="2" />'
            . '<rect x="' . min($x25, $x75) . '" y="' . ($midY - 12) . '" width="' . abs($x75 - $x25) . '" height="24" fill="' . $boxColor . '" opacity="0.25" rx="3" />'
            . '<line x1="' . $x50 . '" y1="' . ($midY - 16) . '" x2="' . $x50 . '" y2="' . ($midY + 16) . '" stroke="' . $boxColor . '" stroke-width="3" />'
            . '</svg>';
    }

    /**
     * A mini candlestick window for the trade-replay stepper: the last bars
     * leading up to (and just past) one simulated trade, with entry/target/
     * stop lines overlaid.
     *
     * @param array<int, array<string, mixed>> $windowRows Chronological OHLC rows.
     */
    public static function candleWindow(
        array $windowRows,
        int $entryLocalIndex,
        string $action,
        float $entryPrice,
        float $targetPrice,
        float $stopPrice,
        int $width = 640,
        int $height = 200
    ): string {
        if (empty($windowRows)) {
            return '<div class="abt-chart-empty">No candle data for this trade.</div>';
        }

        $highs = array_map(static fn(array $r): float => (float) $r['high'], $windowRows);
        $lows  = array_map(static fn(array $r): float => (float) $r['low'],  $windowRows);
        $min   = min(array_merge($lows,  [$stopPrice, $targetPrice]));
        $max   = max(array_merge($highs, [$stopPrice, $targetPrice]));
        if (abs($max - $min) < 1e-9) {
            $max += 0.0001;
        }
        $pad = ($max - $min) * 0.1;
        $min -= $pad;
        $max += $pad;

        $n       = \count($windowRows);
        $plotW   = $width - 20;
        $plotH   = $height - 20;
        $slotW   = $plotW / $n;
        $bodyW   = max(2.0, $slotW * 0.6);
        $toX     = static fn(int $i): float => 10 + $i * $slotW + $slotW / 2;
        $toY     = static fn(float $v): float => 10 + $plotH - (($v - $min) / ($max - $min)) * $plotH;

        $svg = '<svg class="abt-chart abt-chart--candles" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none">';

        $entryY  = round($toY($entryPrice), 1);
        $targetY = round($toY($targetPrice), 1);
        $stopY   = round($toY($stopPrice), 1);
        $svg .= '<line x1="10" y1="' . $targetY . '" x2="' . ($width - 10) . '" y2="' . $targetY . '" stroke="var(--green)" stroke-width="1" stroke-dasharray="4,3" />';
        $svg .= '<line x1="10" y1="' . $stopY   . '" x2="' . ($width - 10) . '" y2="' . $stopY   . '" stroke="var(--red)"   stroke-width="1" stroke-dasharray="4,3" />';
        $svg .= '<line x1="10" y1="' . $entryY  . '" x2="' . ($width - 10) . '" y2="' . $entryY  . '" stroke="var(--yellow)" stroke-width="1" stroke-dasharray="2,2" />';

        foreach (array_values($windowRows) as $i => $row) {
            $open  = (float) $row['open'];
            $close = (float) $row['close'];
            $high  = (float) $row['high'];
            $low   = (float) $row['low'];
            $x     = $toX($i);
            $color = $close >= $open ? 'var(--green)' : 'var(--red)';

            $svg .= '<line x1="' . round($x, 1) . '" y1="' . round($toY($high), 1) . '" x2="' . round($x, 1) . '" y2="' . round($toY($low), 1) . '" stroke="' . $color . '" stroke-width="1.5" />';
            $bodyTop    = round($toY(max($open, $close)), 1);
            $bodyBottom = round($toY(min($open, $close)), 1);
            $bodyHeight = max(1.5, $bodyBottom - $bodyTop);
            $svg .= '<rect x="' . round($x - $bodyW / 2, 1) . '" y="' . $bodyTop . '" width="' . round($bodyW, 1) . '" height="' . round($bodyHeight, 1) . '" fill="' . $color . '" />';

            if ($i === $entryLocalIndex) {
                $svg .= '<circle cx="' . round($x, 1) . '" cy="' . round($toY($entryPrice), 1) . '" r="4" fill="none" stroke="var(--yellow)" stroke-width="2" />';
            }
        }

        $svg .= '</svg>';
        return $svg;
    }
}
