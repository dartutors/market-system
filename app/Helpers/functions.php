<?php
declare(strict_types=1);

function fmt_datetime(string $dt): string
{
    if ($dt === '') {
        return '—';
    }

    try {
        return (new DateTimeImmutable($dt))->format('M j, Y  H:i');
    } catch (Throwable) {
        return $dt;
    }
}

/**
 * Heatmap tile background color for the Market Intelligence view — green
 * for positive, red for negative, intensity scaled by magnitude relative to
 * $scale. Purely presentational, no domain logic.
 */
function mi_heat_color(float $value, float $scale = 1.0): string
{
    $intensity = max(0.12, min(0.85, abs($value) / $scale));
    return $value >= 0
        ? "rgba(34,197,94,{$intensity})"
        : "rgba(239,68,68,{$intensity})";
}

/**
 * Delegates to App\Services\FeatureFlagService::isEnabled() — the intended
 * call site for feature-gated code going forward.
 */
function feature_enabled(string $flag): bool
{
    return App\Services\FeatureFlagService::isEnabled($flag);
}

/**
 * Presentation-layer safeguard for the dashboard's Section 6 word limit —
 * truncates display text to at most $limit words, never the source data
 * itself. AI-generated summaries in this app already run short, but nothing
 * upstream enforces a hard cap, so this guards the UI against a future
 * longer output rather than assuming today's length holds forever.
 */
function truncate_words(string $text, int $limit): string
{
    $words = preg_split('/\s+/', trim($text)) ?: [];
    if (count($words) <= $limit) {
        return $text;
    }
    return implode(' ', array_slice($words, 0, $limit)) . '…';
}

/**
 * Delegates to App\News\NewsCategorizer::categorize() — the intended call
 * site from views (mirrors feature_enabled()'s delegation pattern).
 */
function categorize_news(string $title, string $source): string
{
    return App\News\NewsCategorizer::categorize($title, $source);
}

/**
 * Minimal inline-SVG bar chart for the Reports Center — no chart library,
 * matches this app's existing Advanced Backtesting module precedent of
 * hand-rolled inline SVG over a JS charting dependency.
 *
 * @param array<int, array<string, mixed>> $rows
 */
function rpt_bars(array $rows, string $labelKey, string $valueKey, string $color = '#3b82f6'): string
{
    if (empty($rows)) {
        return '<p class="empty">No data in this range.</p>';
    }
    $max = max(array_map(static fn(array $r): float => (float) $r[$valueKey], $rows)) ?: 1.0;
    $w = 100 / max(1, count($rows));
    $bars = '';
    foreach ($rows as $i => $r) {
        $h = max(2, (float) $r[$valueKey] / $max * 100);
        $x = $i * $w;
        $bars .= '<rect x="' . round($x, 2) . '%" y="' . (100 - $h) . '%" width="' . round($w * 0.7, 2) . '%" height="' . $h . '%" fill="' . e($color) . '"><title>' . e((string) $r[$labelKey]) . ': ' . e((string) $r[$valueKey]) . '</title></rect>';
    }
    return '<svg viewBox="0 0 100 100" preserveAspectRatio="none" class="rpt-chart">' . $bars . '</svg>';
}

/**
 * Mini sparkline for the redesigned currency heatmap tiles — built from
 * real currency_strength_snapshots history (see CurrencyStrengthSnapshot),
 * never synthetic. Honestly reports "not enough history yet" instead of
 * drawing a flat/fake line when fewer than 2 points exist.
 *
 * @param array<int, array{score:mixed, captured_at:mixed}> $history chronological order
 */
function currency_sparkline(array $history, string $color): string
{
    if (count($history) < 2) {
        return '<span class="css-sparkline-empty">Collecting history…</span>';
    }
    $scores = array_map(static fn(array $h): float => (float) $h['score'], $history);
    $min = min($scores);
    $max = max($scores);
    $range = ($max - $min) ?: 1.0;
    $n = count($scores);
    $points = [];
    foreach ($scores as $i => $s) {
        $x = $n > 1 ? ($i / ($n - 1)) * 100 : 0;
        $y = 100 - (($s - $min) / $range * 100);
        $points[] = round($x, 2) . ',' . round($y, 2);
    }
    return '<svg viewBox="0 0 100 100" preserveAspectRatio="none" class="css-sparkline">'
        . '<polyline points="' . e(implode(' ', $points)) . '" fill="none" stroke="' . e($color) . '" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg>';
}
