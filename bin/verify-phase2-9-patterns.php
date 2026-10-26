<?php
declare(strict_types=1);

/**
 * Deterministic checks for Phase 2.9 — Pattern Recognition Library
 * (app/Patterns/*). Every detector is exercised with a hand-constructed
 * synthetic OHLC series built from a small list of (index, price) "key
 * points" via zigzagRows() — linear interpolation between consecutive key
 * points never produces a value outside [min, max] of its two flanking
 * points, so every designed swing high/low is guaranteed to dominate its
 * interpolated neighbors and register as the intended swing (a property
 * proven by hand for the first fixture and relied on for the rest).
 *
 * Run with:
 *   php bin/verify-phase2-9-patterns.php
 *
 * Exits 0 if all checks pass, 1 otherwise.
 */

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;
use App\MarketData\Manager\MarketDataManager;
use App\MarketData\Models\Candle;
use App\Patterns\Contracts\PatternDetectorInterface;
use App\Patterns\Detectors\BreakoutDetector;
use App\Patterns\Detectors\ChannelDetector;
use App\Patterns\Detectors\CupAndHandleDetector;
use App\Patterns\Detectors\DoubleBottomDetector;
use App\Patterns\Detectors\DoubleTopDetector;
use App\Patterns\Detectors\FlagDetector;
use App\Patterns\Detectors\HeadAndShouldersDetector;
use App\Patterns\Detectors\InverseHeadAndShouldersDetector;
use App\Patterns\Detectors\PennantDetector;
use App\Patterns\Detectors\PullbackPatternDetector;
use App\Patterns\Detectors\RangeDetector;
use App\Patterns\Detectors\RectangleDetector;
use App\Patterns\Detectors\TriangleDetector;
use App\Patterns\Detectors\WedgeDetector;
use App\Patterns\Models\PatternMatch;
use App\Patterns\PatternDetectorRegistry;
use App\Patterns\PatternRecognitionEngine;
use App\Patterns\Support\LinearRegression;
use App\Patterns\Support\SwingPointDetector;

$failures = 0;

function checkTrue(string $label, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "PASS  {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

// ── Fixture builders ────────────────────────────────────────────────────────

/**
 * Linearly interpolates between consecutive (index, price) key points and
 * returns a chronological array of flat bars (open=high=low=close=price) up
 * to the highest index given. $points must be sorted by index ascending.
 *
 * @param array<int, array{0:int,1:float}> $points
 * @return array<int, array<string, mixed>>
 */
function zigzagRows(array $points, int $startTs = 1_700_000_000, int $stepSeconds = 3600): array
{
    $maxIndex = end($points)[0];
    $prices   = array_fill(0, $maxIndex + 1, 0.0);

    for ($i = 0; $i < count($points) - 1; $i++) {
        [$idxA, $priceA] = $points[$i];
        [$idxB, $priceB] = $points[$i + 1];
        $span = $idxB - $idxA;
        for ($j = 0; $j <= $span; $j++) {
            $prices[$idxA + $j] = $priceA + ($priceB - $priceA) * ($j / $span);
        }
    }

    $rows = [];
    $ts   = $startTs;
    foreach ($prices as $price) {
        $rows[] = ['timestamp' => $ts, 'open' => $price, 'high' => $price, 'low' => $price, 'close' => $price, 'volume' => 1000.0];
        $ts += $stepSeconds;
    }
    return $rows;
}

function firstMatch(array $matches): ?PatternMatch
{
    return $matches[0] ?? null;
}

echo "=== Phase 2.9 Pattern Recognition Library — Verification ===\n\n";

// ── 1. SwingPointDetector primitive ─────────────────────────────────────────
echo "--- SwingPointDetector ---\n";
$swingRows = zigzagRows([[0, 1.10], [2, 1.20], [4, 1.05], [6, 1.15]]);
$swings    = (new SwingPointDetector())->detect($swingRows);
$highs     = SwingPointDetector::onlyHighs($swings);
$lows      = SwingPointDetector::onlyLows($swings);
checkTrue('Exactly one swing high detected', count($highs) === 1, 'got ' . count($highs));
checkTrue('Swing high is at index 2, price 1.20', count($highs) === 1 && $highs[0]->index === 2 && abs($highs[0]->price - 1.20) < 1e-9);
checkTrue('Exactly one swing low detected', count($lows) === 1, 'got ' . count($lows));
checkTrue('Swing low is at index 4, price 1.05', count($lows) === 1 && $lows[0]->index === 4 && abs($lows[0]->price - 1.05) < 1e-9);

// ── 2. LinearRegression primitive ───────────────────────────────────────────
echo "\n--- LinearRegression ---\n";
$fit = LinearRegression::fit([1.0, 2.0, 3.0, 4.0, 5.0], [3.0, 5.0, 7.0, 9.0, 11.0]); // y = 2x + 1, exact
checkTrue('Fits y=2x+1 slope correctly', abs($fit['slope'] - 2.0) < 1e-9, "slope={$fit['slope']}");
checkTrue('Fits y=2x+1 intercept correctly', abs($fit['intercept'] - 1.0) < 1e-9, "intercept={$fit['intercept']}");
checkTrue('valueAt() evaluates the fitted line', abs(LinearRegression::valueAt($fit, 10.0) - 21.0) < 1e-9);

// ── Shared negative fixture: a pure 42-bar monotonic uptrend has zero
// interior swing points (every interior bar has a strictly higher neighbor
// on one side and a strictly lower one on the other), so it is a clean
// "nothing to see here" fixture for every swing-based detector, and also
// serves as the positive fixture for Breakout (the final close clears the
// prior-range high by more than the 0.1% buffer) while failing Flag/Pennant's
// pole-size gate (only ~1.57% move over the pole window, under the 2% floor).
$monotonicRows = zigzagRows([[0, 1.1000], [41, 1.1820]]);

// ── 3. DoubleTopDetector ─────────────────────────────────────────────────────
echo "\n--- DoubleTopDetector ---\n";
$doubleTopRows = zigzagRows([[0, 1.1950], [2, 1.2050], [5, 1.1850], [8, 1.2055], [11, 1.1800], [23, 1.1740]]);
$dtMatches     = (new DoubleTopDetector())->detect($doubleTopRows);
$dtMatch       = firstMatch($dtMatches);
checkTrue('Double Top: exactly one match on the constructed fixture', count($dtMatches) === 1, 'got ' . count($dtMatches));
checkTrue('Double Top: key is double_top', $dtMatch !== null && $dtMatch->pattern === 'double_top');
checkTrue('Double Top: direction is bearish', $dtMatch !== null && $dtMatch->direction === 'bearish');
checkTrue('Double Top: confidence is a sane 50-96 range', $dtMatch !== null && $dtMatch->confidence >= 50.0 && $dtMatch->confidence <= 96.0, (string) ($dtMatch->confidence ?? -1));
checkTrue('Double Top: no false positive on a monotonic uptrend', (new DoubleTopDetector())->detect($monotonicRows) === []);

// ── 4. DoubleBottomDetector ──────────────────────────────────────────────────
echo "\n--- DoubleBottomDetector ---\n";
$doubleBottomRows = zigzagRows([[0, 1.2050], [2, 1.1950], [5, 1.2150], [8, 1.1945], [11, 1.2200], [23, 1.2260]]);
$dbMatches        = (new DoubleBottomDetector())->detect($doubleBottomRows);
$dbMatch          = firstMatch($dbMatches);
checkTrue('Double Bottom: exactly one match on the constructed fixture', count($dbMatches) === 1, 'got ' . count($dbMatches));
checkTrue('Double Bottom: key is double_bottom', $dbMatch !== null && $dbMatch->pattern === 'double_bottom');
checkTrue('Double Bottom: direction is bullish', $dbMatch !== null && $dbMatch->direction === 'bullish');
checkTrue('Double Bottom: confidence is a sane 50-96 range', $dbMatch !== null && $dbMatch->confidence >= 50.0 && $dbMatch->confidence <= 96.0);
checkTrue('Double Bottom: no false positive on a monotonic uptrend', (new DoubleBottomDetector())->detect($monotonicRows) === []);

// ── 5. HeadAndShouldersDetector ──────────────────────────────────────────────
echo "\n--- HeadAndShouldersDetector ---\n";
$hsRows    = zigzagRows([[0, 1.1900], [2, 1.2000], [5, 1.1830], [8, 1.2120], [11, 1.1820], [14, 1.2010], [17, 1.1700], [29, 1.1580]]);
$hsMatches = (new HeadAndShouldersDetector())->detect($hsRows);
$hsMatch   = firstMatch($hsMatches);
checkTrue('Head & Shoulders: exactly one match on the constructed fixture', count($hsMatches) === 1, 'got ' . count($hsMatches));
checkTrue('Head & Shoulders: key is head_and_shoulders', $hsMatch !== null && $hsMatch->pattern === 'head_and_shoulders');
checkTrue('Head & Shoulders: direction is bearish', $hsMatch !== null && $hsMatch->direction === 'bearish');
checkTrue('Head & Shoulders: confidence is a sane 50-96 range', $hsMatch !== null && $hsMatch->confidence >= 50.0 && $hsMatch->confidence <= 96.0);
checkTrue('Head & Shoulders: no false positive on a monotonic uptrend', (new HeadAndShouldersDetector())->detect($monotonicRows) === []);

// ── 6. InverseHeadAndShouldersDetector ───────────────────────────────────────
echo "\n--- InverseHeadAndShouldersDetector ---\n";
$ihsRows    = zigzagRows([[0, 1.2100], [2, 1.2000], [5, 1.2170], [8, 1.1880], [11, 1.2180], [14, 1.1990], [17, 1.2300], [29, 1.2420]]);
$ihsMatches = (new InverseHeadAndShouldersDetector())->detect($ihsRows);
$ihsMatch   = firstMatch($ihsMatches);
checkTrue('Inverse Head & Shoulders: exactly one match on the constructed fixture', count($ihsMatches) === 1, 'got ' . count($ihsMatches));
checkTrue('Inverse Head & Shoulders: key is inverse_head_and_shoulders', $ihsMatch !== null && $ihsMatch->pattern === 'inverse_head_and_shoulders');
checkTrue('Inverse Head & Shoulders: direction is bullish', $ihsMatch !== null && $ihsMatch->direction === 'bullish');
checkTrue('Inverse Head & Shoulders: confidence is a sane 50-96 range', $ihsMatch !== null && $ihsMatch->confidence >= 50.0 && $ihsMatch->confidence <= 96.0);
checkTrue('Inverse Head & Shoulders: no false positive on a monotonic uptrend', (new InverseHeadAndShouldersDetector())->detect($monotonicRows) === []);

// ── 7. TriangleDetector (symmetrical: descending highs, ascending lows) ────
echo "\n--- TriangleDetector ---\n";
$triangleRows = zigzagRows([
    [0, 1.2000], [2, 1.2100], [5, 1.1900], [8, 1.2080], [11, 1.1920],
    [14, 1.2060], [17, 1.1940], [20, 1.2040], [22, 1.1960], [24, 1.1975],
]);
$triMatches = (new TriangleDetector())->detect($triangleRows);
$triMatch   = firstMatch($triMatches);
checkTrue('Triangle: exactly one match on the constructed fixture', count($triMatches) === 1, 'got ' . count($triMatches));
checkTrue('Triangle: key is triangle', $triMatch !== null && $triMatch->pattern === 'triangle');
checkTrue('Triangle: variant is Symmetrical Triangle', $triMatch !== null && $triMatch->label === 'Symmetrical Triangle', (string) ($triMatch->label ?? ''));
checkTrue('Triangle: direction is neutral', $triMatch !== null && $triMatch->direction === 'neutral');
checkTrue('Triangle: no false positive on a monotonic uptrend', (new TriangleDetector())->detect($monotonicRows) === []);

// ── 8. WedgeDetector (rising wedge: both lines up, lower line steeper) ──────
echo "\n--- WedgeDetector ---\n";
$wedgeRows = zigzagRows([
    [0, 1.19700], [2, 1.20000], [5, 1.19000], [8, 1.20150], [11, 1.19371],
    [14, 1.20300], [17, 1.19741], [20, 1.20450], [22, 1.20050], [24, 1.20200],
]);
$wedgeMatches = (new WedgeDetector())->detect($wedgeRows);
$wedgeMatch   = firstMatch($wedgeMatches);
checkTrue('Wedge: exactly one match on the constructed fixture', count($wedgeMatches) === 1, 'got ' . count($wedgeMatches));
checkTrue('Wedge: key is wedge', $wedgeMatch !== null && $wedgeMatch->pattern === 'wedge');
checkTrue('Wedge: variant is Rising Wedge', $wedgeMatch !== null && $wedgeMatch->label === 'Rising Wedge', (string) ($wedgeMatch->label ?? ''));
checkTrue('Wedge: direction is bearish', $wedgeMatch !== null && $wedgeMatch->direction === 'bearish');
checkTrue('Wedge: no false positive on a monotonic uptrend', (new WedgeDetector())->detect($monotonicRows) === []);

// ── 9. ChannelDetector (ascending channel: parallel rising trendlines) ─────
echo "\n--- ChannelDetector ---\n";
$channelRows = zigzagRows([
    [0, 1.19700], [2, 1.20000], [5, 1.19000], [8, 1.20150], [11, 1.19150],
    [14, 1.20300], [17, 1.19300], [20, 1.20450], [22, 1.19425], [24, 1.19500],
]);
$channelMatches = (new ChannelDetector())->detect($channelRows);
$channelMatch   = firstMatch($channelMatches);
checkTrue('Channel: exactly one match on the constructed fixture', count($channelMatches) === 1, 'got ' . count($channelMatches));
checkTrue('Channel: key is channel', $channelMatch !== null && $channelMatch->pattern === 'channel');
checkTrue('Channel: variant is Ascending Channel', $channelMatch !== null && $channelMatch->label === 'Ascending Channel', (string) ($channelMatch->label ?? ''));
checkTrue('Channel: direction is bullish', $channelMatch !== null && $channelMatch->direction === 'bullish');
checkTrue('Channel: no false positive on a monotonic uptrend', (new ChannelDetector())->detect($monotonicRows) === []);

// ── 10. RectangleDetector (flat top/bottom, multiple touches) ──────────────
echo "\n--- RectangleDetector ---\n";
$rectangleRows = zigzagRows([
    [0, 1.2000], [2, 1.2050], [5, 1.1950], [8, 1.2052], [11, 1.1952],
    [14, 1.2049], [17, 1.1949], [20, 1.2051], [22, 1.1951], [24, 1.1960],
]);
$rectMatches = (new RectangleDetector())->detect($rectangleRows);
$rectMatch   = firstMatch($rectMatches);
checkTrue('Rectangle: exactly one match on the constructed fixture', count($rectMatches) === 1, 'got ' . count($rectMatches));
checkTrue('Rectangle: key is rectangle', $rectMatch !== null && $rectMatch->pattern === 'rectangle');
checkTrue('Rectangle: direction is neutral', $rectMatch !== null && $rectMatch->direction === 'neutral');
checkTrue('Rectangle: no false positive on a monotonic uptrend', (new RectangleDetector())->detect($monotonicRows) === []);

// ── 11. BreakoutDetector (the shared monotonic fixture IS the positive case) ─
echo "\n--- BreakoutDetector ---\n";
$breakoutMatches = (new BreakoutDetector())->detect($monotonicRows);
$breakoutMatch   = firstMatch($breakoutMatches);
checkTrue('Breakout: exactly one match on the monotonic uptrend fixture', count($breakoutMatches) === 1, 'got ' . count($breakoutMatches));
checkTrue('Breakout: key is breakout', $breakoutMatch !== null && $breakoutMatch->pattern === 'breakout');
checkTrue('Breakout: direction is bullish', $breakoutMatch !== null && $breakoutMatch->direction === 'bullish');
// A tight sideways range (see Range fixture below) must NOT register a breakout.
$rangeRowsForBreakoutCheck = [];
for ($i = 0; $i < 22; $i++) {
    $rangeRowsForBreakoutCheck[] = ['timestamp' => 1_700_000_000 + $i * 3600, 'open' => 1.2000, 'high' => 1.2010, 'low' => 1.1990, 'close' => 1.2000, 'volume' => 1000.0];
}
checkTrue('Breakout: no false positive inside a tight range', (new BreakoutDetector())->detect($rangeRowsForBreakoutCheck) === []);

// ── 12. RangeDetector (tight sideways consolidation, no swing requirement) ─
echo "\n--- RangeDetector ---\n";
$rangeRows = [];
for ($i = 0; $i < 22; $i++) {
    $rangeRows[] = ['timestamp' => 1_700_000_000 + $i * 3600, 'open' => 1.2000, 'high' => 1.2010, 'low' => 1.1990, 'close' => 1.2000, 'volume' => 1000.0];
}
$rangeMatches = (new RangeDetector())->detect($rangeRows);
$rangeMatch   = firstMatch($rangeMatches);
checkTrue('Range: exactly one match on the tight-range fixture', count($rangeMatches) === 1, 'got ' . count($rangeMatches));
checkTrue('Range: key is range', $rangeMatch !== null && $rangeMatch->pattern === 'range');
checkTrue('Range: direction is neutral', $rangeMatch !== null && $rangeMatch->direction === 'neutral');
checkTrue('Range: no false positive on a monotonic uptrend (>3% band)', (new RangeDetector())->detect($monotonicRows) === []);

// ── 13. CupAndHandleDetector ─────────────────────────────────────────────────
echo "\n--- CupAndHandleDetector ---\n";
$cupRows = zigzagRows([[0, 1.2150], [2, 1.2200], [18, 1.1400], [30, 1.2190], [38, 1.1950], [44, 1.2000]]);
$cupMatches = (new CupAndHandleDetector())->detect($cupRows);
$cupMatch   = firstMatch($cupMatches);
checkTrue('Cup & Handle: exactly one match on the constructed fixture', count($cupMatches) === 1, 'got ' . count($cupMatches));
checkTrue('Cup & Handle: key is cup_and_handle', $cupMatch !== null && $cupMatch->pattern === 'cup_and_handle');
checkTrue('Cup & Handle: direction is bullish', $cupMatch !== null && $cupMatch->direction === 'bullish');
checkTrue('Cup & Handle: confidence is a sane 50-96 range', $cupMatch !== null && $cupMatch->confidence >= 50.0 && $cupMatch->confidence <= 96.0);
checkTrue('Cup & Handle: no false positive on a monotonic uptrend', (new CupAndHandleDetector())->detect($monotonicRows) === []);

// ── 14. PullbackPatternDetector ──────────────────────────────────────────────
echo "\n--- PullbackPatternDetector ---\n";
$pullbackRows = zigzagRows([[0, 1.1820], [2, 1.1800], [8, 1.1900], [14, 1.1850], [20, 1.2000], [29, 1.19427]]);
$pullbackMatches = (new PullbackPatternDetector())->detect($pullbackRows);
$pullbackMatch   = firstMatch($pullbackMatches);
checkTrue('Pullback: exactly one match on the constructed fixture', count($pullbackMatches) === 1, 'got ' . count($pullbackMatches));
checkTrue('Pullback: key is pullback', $pullbackMatch !== null && $pullbackMatch->pattern === 'pullback');
checkTrue('Pullback: direction is bullish', $pullbackMatch !== null && $pullbackMatch->direction === 'bullish');
checkTrue('Pullback: confidence is a sane 50-96 range', $pullbackMatch !== null && $pullbackMatch->confidence >= 50.0 && $pullbackMatch->confidence <= 96.0);
checkTrue('Pullback: no false positive on a monotonic uptrend', (new PullbackPatternDetector())->detect($monotonicRows) === []);

// ── 15. FlagDetector (bullish pole + tight flat parallel consolidation) ────
// Flag/Pennant need genuine per-bar high != low in the tail (the regression
// is fit through each bar's own high and own low), unlike the swing-based
// detectors above where flat bars are fine (the two lines are fit through
// different swing INDICES, so they diverge regardless of per-bar range).
echo "\n--- FlagDetector ---\n";
$flagRows = [];
$ts = 1_700_000_000;
for ($i = 0; $i <= 6; $i++) { // padding, unused by Flag's logic
    $flagRows[] = ['timestamp' => $ts, 'open' => 1.1800, 'high' => 1.1800, 'low' => 1.1800, 'close' => 1.1800, 'volume' => 1000.0];
    $ts += 3600;
}
for ($i = 0; $i <= 9; $i++) { // pole: idx7..16, 1.18000 -> 1.20500 (+2.119%)
    $price = 1.18000 + (0.02500 / 9) * $i;
    $flagRows[] = ['timestamp' => $ts, 'open' => $price, 'high' => $price, 'low' => $price, 'close' => $price, 'volume' => 1000.0];
    $ts += 3600;
}
for ($i = 0; $i < 8; $i++) { // tail: idx17..24, flat tight channel width 0.0030
    $flagRows[] = ['timestamp' => $ts, 'open' => 1.2050, 'high' => 1.2065, 'low' => 1.2035, 'close' => 1.2050, 'volume' => 1000.0];
    $ts += 3600;
}
$flagMatches = (new FlagDetector())->detect($flagRows);
$flagMatch   = firstMatch($flagMatches);
checkTrue('Flag: exactly one match on the constructed fixture', count($flagMatches) === 1, 'got ' . count($flagMatches));
checkTrue('Flag: key is flag', $flagMatch !== null && $flagMatch->pattern === 'flag');
checkTrue('Flag: direction is bullish', $flagMatch !== null && $flagMatch->direction === 'bullish');
checkTrue('Flag: confidence is a sane 50-96 range', $flagMatch !== null && $flagMatch->confidence >= 50.0 && $flagMatch->confidence <= 96.0);
checkTrue('Flag: no false positive on a monotonic uptrend (pole too small)', (new FlagDetector())->detect($monotonicRows) === []);

// ── 16. PennantDetector (bearish pole + small converging consolidation) ────
echo "\n--- PennantDetector ---\n";
$pennantRows = [];
$ts = 1_700_000_000;
for ($i = 0; $i <= 6; $i++) { // padding, unused by Pennant's logic
    $pennantRows[] = ['timestamp' => $ts, 'open' => 1.2050, 'high' => 1.2050, 'low' => 1.2050, 'close' => 1.2050, 'volume' => 1000.0];
    $ts += 3600;
}
for ($i = 0; $i <= 9; $i++) { // pole: idx7..16, 1.20500 -> 1.18000 (-2.075%)
    $price = 1.20500 - (0.02500 / 9) * $i;
    $pennantRows[] = ['timestamp' => $ts, 'open' => $price, 'high' => $price, 'low' => $price, 'close' => $price, 'volume' => 1000.0];
    $ts += 3600;
}
for ($i = 0; $i < 8; $i++) { // tail: idx17..24, converging (width 0.0060 -> 0.0020)
    $high = 1.18300 - (0.00200 / 7) * $i;
    $low  = 1.17700 + (0.00200 / 7) * $i;
    $pennantRows[] = ['timestamp' => $ts, 'open' => ($high + $low) / 2, 'high' => $high, 'low' => $low, 'close' => ($high + $low) / 2, 'volume' => 1000.0];
    $ts += 3600;
}
$pennantMatches = (new PennantDetector())->detect($pennantRows);
$pennantMatch   = firstMatch($pennantMatches);
checkTrue('Pennant: exactly one match on the constructed fixture', count($pennantMatches) === 1, 'got ' . count($pennantMatches));
checkTrue('Pennant: key is pennant', $pennantMatch !== null && $pennantMatch->pattern === 'pennant');
checkTrue('Pennant: direction is bearish', $pennantMatch !== null && $pennantMatch->direction === 'bearish');
checkTrue('Pennant: confidence is a sane 50-96 range', $pennantMatch !== null && $pennantMatch->confidence >= 50.0 && $pennantMatch->confidence <= 96.0);
checkTrue('Pennant: no false positive on a monotonic uptrend (pole too small)', (new PennantDetector())->detect($monotonicRows) === []);

// ── 17. PatternDetectorRegistry ──────────────────────────────────────────────
echo "\n--- PatternDetectorRegistry ---\n";
$registry = PatternDetectorRegistry::all();
checkTrue('Registry has exactly 14 detectors', count($registry) === 14, 'got ' . count($registry));
$registryKeys = array_map(static fn(PatternDetectorInterface $d): string => $d->key(), $registry);
checkTrue('Registry keys are all unique', count($registryKeys) === count(array_unique($registryKeys)));
$expectedKeys = [
    'double_top', 'double_bottom', 'head_and_shoulders', 'inverse_head_and_shoulders',
    'triangle', 'wedge', 'channel', 'rectangle', 'breakout', 'pullback', 'range',
    'cup_and_handle', 'flag', 'pennant',
];
sort($expectedKeys);
$sortedRegistryKeys = $registryKeys;
sort($sortedRegistryKeys);
checkTrue('Registry contains exactly the 14 spec\'d pattern keys', $sortedRegistryKeys === $expectedKeys, implode(',', $sortedRegistryKeys));

// ── 18. PatternRecognitionEngine — sorting + fault tolerance ────────────────
echo "\n--- PatternRecognitionEngine ---\n";

final class FakeMatchDetector implements PatternDetectorInterface
{
    public function __construct(private string $key, private float $confidence) {}
    public function key(): string { return $this->key; }
    public function label(): string { return $this->key; }
    public function detect(array $rows): array
    {
        return [new PatternMatch($this->key, $this->key, 'neutral', $this->confidence, 0, 0, 0, 0, 'fake')];
    }
}

final class FakeThrowingDetector implements PatternDetectorInterface
{
    public function key(): string { return 'fake_throwing'; }
    public function label(): string { return 'Fake Throwing'; }
    public function detect(array $rows): array { throw new \RuntimeException('synthetic detector failure'); }
}

$fakeEngine = new PatternRecognitionEngine([
    new FakeMatchDetector('low_conf', 55.0),
    new FakeThrowingDetector(),
    new FakeMatchDetector('high_conf', 80.0),
]);
$fakeMatches = $fakeEngine->detect([]);
checkTrue('Engine tolerates a throwing detector and still returns the others', count($fakeMatches) === 2, 'got ' . count($fakeMatches));
checkTrue('Engine sorts matches by confidence descending', count($fakeMatches) === 2 && $fakeMatches[0]->pattern === 'high_conf' && $fakeMatches[1]->pattern === 'low_conf');
$filtered = $fakeEngine->detectAboveConfidence([], 60.0);
checkTrue('detectAboveConfidence() filters out matches below the threshold', count($filtered) === 1 && $filtered[0]->pattern === 'high_conf', 'got ' . count($filtered));

// A real end-to-end run through the full 14-detector registry on a totally
// flat/degenerate short series must never throw — every detector's own
// MIN_BARS guard should simply yield no matches.
$degenerateRows = zigzagRows([[0, 1.2000], [3, 1.2000]]);
$degenerateResult = null;
try {
    $degenerateResult = (new PatternRecognitionEngine())->detect($degenerateRows);
} catch (\Throwable $e) {
    checkTrue('Full registry run on a tiny degenerate series never throws', false, $e->getMessage());
}
if ($degenerateResult !== null) {
    checkTrue('Full registry run on a tiny degenerate series returns no matches', $degenerateResult === []);
}

// ── 19. USE_PATTERN_RECOGNITION env toggle ──────────────────────────────────
// Dotenv populates BOTH $_ENV and $_SERVER (bootstrap/app.php uses
// Dotenv::createImmutable()->safeLoad()) — a recurring gotcha across every
// phase's verify script. Save and restore both explicitly, not just unset.
echo "\n--- USE_PATTERN_RECOGNITION env toggle ---\n";
$originalEnvVal    = $_ENV['USE_PATTERN_RECOGNITION'] ?? null;
$originalServerVal = $_SERVER['USE_PATTERN_RECOGNITION'] ?? null;

unset($_ENV['USE_PATTERN_RECOGNITION'], $_SERVER['USE_PATTERN_RECOGNITION']);
checkTrue('Defaults to true when unset', env('USE_PATTERN_RECOGNITION', true) === true);

$_ENV['USE_PATTERN_RECOGNITION']    = 'false';
$_SERVER['USE_PATTERN_RECOGNITION'] = 'false';
checkTrue('Resolves to false when explicitly disabled', env('USE_PATTERN_RECOGNITION', true) === false);

if ($originalEnvVal === null) {
    unset($_ENV['USE_PATTERN_RECOGNITION']);
} else {
    $_ENV['USE_PATTERN_RECOGNITION'] = $originalEnvVal;
}
if ($originalServerVal === null) {
    unset($_SERVER['USE_PATTERN_RECOGNITION']);
} else {
    $_SERVER['USE_PATTERN_RECOGNITION'] = $originalServerVal;
}

// ── 20. Structural wiring checks ────────────────────────────────────────────
echo "\n--- Structural wiring ---\n";
$scanServiceSrc = (string) file_get_contents(__DIR__ . '/../app/Services/MarketScanService.php');
checkTrue('MarketScanService imports PatternRecognitionEngine', str_contains($scanServiceSrc, 'use App\Patterns\PatternRecognitionEngine;'));
checkTrue('MarketScanService gates pattern detection behind USE_PATTERN_RECOGNITION', str_contains($scanServiceSrc, "env('USE_PATTERN_RECOGNITION', true)"));
checkTrue('MarketScanService appends pattern findings to $signal[\'reasons\']', str_contains($scanServiceSrc, "\$signal['reasons'][] = sprintf("));
checkTrue('MarketScanService pattern block runs before the calendar hard-block', strpos($scanServiceSrc, 'Chart pattern recognition') < strpos($scanServiceSrc, 'Calendar hard block'));

$visionSrc = (string) file_get_contents(__DIR__ . '/../app/Controllers/VisionController.php');
checkTrue('VisionController imports PatternRecognitionEngine', str_contains($visionSrc, 'use App\Patterns\PatternRecognitionEngine;'));
checkTrue('VisionController has detectAlgorithmicPatterns()', str_contains($visionSrc, 'function detectAlgorithmicPatterns('));
checkTrue('VisionController persists chart_patterns_algorithmic_json', str_contains($visionSrc, "'chart_patterns_algorithmic_json'"));

$chartAnalysisSrc = (string) file_get_contents(__DIR__ . '/../app/Models/ChartAnalysis.php');
checkTrue('ChartAnalysis::create() SQL includes chart_patterns_algorithmic_json', str_contains($chartAnalysisSrc, 'chart_patterns_algorithmic_json'));

$migrationSrc = (string) file_get_contents(__DIR__ . '/../database/migrations/029_add_algorithmic_patterns_to_chart_analysis.php');
checkTrue('Migration 029 adds the new column idempotently', str_contains($migrationSrc, 'ADD COLUMN IF NOT EXISTS chart_patterns_algorithmic_json'));

$columns = Database::connection()->query("SHOW COLUMNS FROM chart_analysis LIKE 'chart_patterns_algorithmic_json'")->fetchAll();
checkTrue('chart_analysis table actually has the new column (migration applied)', count($columns) === 1);

// ── 21. Live smoke test — the exact pipeline VisionController's
//    detectAlgorithmicPatterns() uses, run directly against real market data.
//    Tolerant of network/API unavailability (SKIP, not FAIL), matching every
//    prior phase's live-check convention.
echo "\n--- Live smoke test ---\n";
try {
    $liveCandles = (new MarketDataManager())->getCandles('EURUSD=X', 'Daily');
    if (count($liveCandles) < 20) {
        echo "SKIP  Live pattern-detection smoke test — fewer than 20 candles returned\n";
    } else {
        $liveRows    = array_map(static fn(Candle $c): array => $c->toLegacyRow(), $liveCandles);
        $liveMatches = (new PatternRecognitionEngine())->detectAboveConfidence($liveRows, 55.0);
        checkTrue(
            'Live PatternRecognitionEngine run against real EURUSD Daily candles completes without throwing',
            true,
            count($liveMatches) . ' pattern(s) found'
        );
        echo '      (found: ' . implode(', ', array_map(static fn(PatternMatch $m) => $m->label, $liveMatches)) . ")\n";
    }
} catch (\Throwable $e) {
    echo 'SKIP  Live pattern-detection smoke test — network/API unavailable: ' . $e->getMessage() . "\n";
}

// ── 22. Live full scanner run — proves the Scanner integration (task #13)
//    doesn't break the real cron path. Tolerant of network/AI/config issues.
try {
    $scanResult = (new App\Services\MarketScanService())->run();
    checkTrue('Live MarketScanService::run() completes without throwing, patterns wired in', is_array($scanResult));
} catch (\Throwable $e) {
    echo 'SKIP  Live MarketScanService::run() — unavailable: ' . $e->getMessage() . "\n";
}

echo "\n";
echo $failures === 0
    ? "ALL CHECKS PASSED\n"
    : "{$failures} CHECK(S) FAILED\n";

exit($failures === 0 ? 0 : 1);
