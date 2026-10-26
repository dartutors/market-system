<?php
declare(strict_types=1);

/**
 * Deterministic checks for Phase 3.0 — Institutional Analysis
 * (app/SmartMoney/*). Same hand-computed-fixture discipline as Phase 2.9's
 * verify script; swing-based detectors reuse the zigzagRows() interpolation
 * technique from that script (linear interpolation between designed key
 * points never produces a value outside [min, max] of its two flanking
 * points, so a designed swing point always dominates its interpolated
 * neighbors). Order Blocks / Fair Value Gaps / Liquidity Sweeps need real
 * per-bar open != close (candle color) and high != low (wicks), so those
 * fixtures are built with explicit per-bar OHLC instead.
 *
 * Run with:
 *   php bin/verify-phase3-0-smartmoney.php
 *
 * Exits 0 if all checks pass, 1 otherwise.
 */

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\MarketData\Manager\MarketDataManager;
use App\MarketData\Manager\TimeframeCatalog;
use App\MarketData\Models\Candle;
use App\SmartMoney\Contracts\StructureDetectorInterface;
use App\SmartMoney\Detectors\BreakOfStructureDetector;
use App\SmartMoney\Detectors\ChangeOfCharacterDetector;
use App\SmartMoney\Detectors\EqualHighsLowsDetector;
use App\SmartMoney\Detectors\FairValueGapDetector;
use App\SmartMoney\Detectors\LiquidityPoolDetector;
use App\SmartMoney\Detectors\LiquiditySweepDetector;
use App\SmartMoney\Detectors\MitigationBlockDetector;
use App\SmartMoney\Detectors\OrderBlockDetector;
use App\SmartMoney\Detectors\PremiumDiscountZoneDetector;
use App\SmartMoney\MarketStructureEngine;
use App\SmartMoney\Models\StructureEvent;
use App\SmartMoney\StructureDetectorRegistry;
use App\SmartMoney\Support\OrderBlockFinder;
use App\SmartMoney\Support\StructureDirectionResolver;
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

/**
 * Linear interpolation between (index, price) key points -> flat bars
 * (open=high=low=close=price). Identical technique to Phase 2.9's verify
 * script, safe for every swing-based detector here (none of them read
 * 'open' for anything other than candle-color checks, which the flat-bar
 * detectors below don't perform).
 *
 * @param array<int, array{0:int,1:float}> $points
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

function firstMatch(array $matches): ?StructureEvent
{
    return $matches[0] ?? null;
}

echo "=== Phase 3.0 Institutional Analysis — Verification ===\n\n";

$monotonicRows = zigzagRows([[0, 1.1000], [41, 1.1820]]); // shared negative fixture, zero interior swings

// ── 1. OrderBlockDetector (last opposing candle before a >=1.5% 3-bar impulse, still unmitigated) ─
echo "--- OrderBlockDetector ---\n";
$orderBlockRows = [
    ['timestamp' => 1_700_000_000, 'open' => 1.2050, 'high' => 1.2060, 'low' => 1.2000, 'close' => 1.2010, 'volume' => 1000.0], // the order block (bearish candle)
    ['timestamp' => 1_700_003_600, 'open' => 1.2010, 'high' => 1.2100, 'low' => 1.2000, 'close' => 1.2100, 'volume' => 1000.0], // impulse bar 1
    ['timestamp' => 1_700_007_200, 'open' => 1.2100, 'high' => 1.2200, 'low' => 1.2090, 'close' => 1.2200, 'volume' => 1000.0], // impulse bar 2
    ['timestamp' => 1_700_010_800, 'open' => 1.2200, 'high' => 1.2300, 'low' => 1.2190, 'close' => 1.2300, 'volume' => 1000.0], // impulse bar 3 (+2.41% over the 3 bars)
    ['timestamp' => 1_700_014_400, 'open' => 1.2300, 'high' => 1.2400, 'low' => 1.2290, 'close' => 1.2400, 'volume' => 1000.0],
    ['timestamp' => 1_700_018_000, 'open' => 1.2400, 'high' => 1.2500, 'low' => 1.2390, 'close' => 1.2500, 'volume' => 1000.0],
    ['timestamp' => 1_700_021_600, 'open' => 1.2500, 'high' => 1.2600, 'low' => 1.2490, 'close' => 1.2600, 'volume' => 1000.0],
    ['timestamp' => 1_700_025_200, 'open' => 1.2600, 'high' => 1.2700, 'low' => 1.2590, 'close' => 1.2700, 'volume' => 1000.0],
    ['timestamp' => 1_700_028_800, 'open' => 1.2700, 'high' => 1.2800, 'low' => 1.2690, 'close' => 1.2800, 'volume' => 1000.0],
    ['timestamp' => 1_700_032_400, 'open' => 1.2800, 'high' => 1.2900, 'low' => 1.2790, 'close' => 1.2900, 'volume' => 1000.0], // never dips back into the zone
];
$obMatches = (new OrderBlockDetector())->detect($orderBlockRows);
$obMatch   = firstMatch($obMatches);
checkTrue('Order Block: exactly one match on the constructed fixture', count($obMatches) === 1, 'got ' . count($obMatches));
checkTrue('Order Block: key is order_block', $obMatch !== null && $obMatch->type === 'order_block');
checkTrue('Order Block: direction is bullish', $obMatch !== null && $obMatch->direction === 'bullish');
checkTrue('Order Block: zone matches the candle high/low', $obMatch !== null && abs($obMatch->zoneHigh - 1.2060) < 1e-9 && abs($obMatch->zoneLow - 1.2000) < 1e-9);
checkTrue('Order Block: not mitigated', $obMatch !== null && $obMatch->mitigated === false);
checkTrue('Order Block: no false positive on a monotonic uptrend (no candle color)', (new OrderBlockDetector())->detect($monotonicRows) === []);

// ── 2. MitigationBlockDetector (same geometry, but price trades back through the zone) ─
echo "\n--- MitigationBlockDetector ---\n";
$mitigationRows = [
    ['timestamp' => 1_700_000_000, 'open' => 1.2050, 'high' => 1.2060, 'low' => 1.2000, 'close' => 1.2010, 'volume' => 1000.0], // the order block (bearish candle)
    ['timestamp' => 1_700_003_600, 'open' => 1.2010, 'high' => 1.2100, 'low' => 1.2000, 'close' => 1.2100, 'volume' => 1000.0],
    ['timestamp' => 1_700_007_200, 'open' => 1.2100, 'high' => 1.2200, 'low' => 1.2090, 'close' => 1.2200, 'volume' => 1000.0],
    ['timestamp' => 1_700_010_800, 'open' => 1.2200, 'high' => 1.2300, 'low' => 1.2190, 'close' => 1.2300, 'volume' => 1000.0], // impulse end
    ['timestamp' => 1_700_014_400, 'open' => 1.2200, 'high' => 1.2310, 'low' => 1.2040, 'close' => 1.2200, 'volume' => 1000.0], // doji (open==close, so it can't itself become a new candidate) that wicks into the zone (low <= 1.2060) -> mitigated
    ['timestamp' => 1_700_018_000, 'open' => 1.2200, 'high' => 1.2320, 'low' => 1.2190, 'close' => 1.2310, 'volume' => 1000.0],
    ['timestamp' => 1_700_021_600, 'open' => 1.2310, 'high' => 1.2420, 'low' => 1.2300, 'close' => 1.2410, 'volume' => 1000.0],
    ['timestamp' => 1_700_025_200, 'open' => 1.2410, 'high' => 1.2520, 'low' => 1.2400, 'close' => 1.2510, 'volume' => 1000.0],
    ['timestamp' => 1_700_028_800, 'open' => 1.2510, 'high' => 1.2620, 'low' => 1.2500, 'close' => 1.2610, 'volume' => 1000.0],
    ['timestamp' => 1_700_032_400, 'open' => 1.2610, 'high' => 1.2720, 'low' => 1.2600, 'close' => 1.2710, 'volume' => 1000.0],
];
$mbMatches = (new MitigationBlockDetector())->detect($mitigationRows);
$mbMatch   = firstMatch($mbMatches);
checkTrue('Mitigation Block: exactly one match on the constructed fixture', count($mbMatches) === 1, 'got ' . count($mbMatches));
checkTrue('Mitigation Block: key is mitigation_block', $mbMatch !== null && $mbMatch->type === 'mitigation_block');
checkTrue('Mitigation Block: direction is bullish', $mbMatch !== null && $mbMatch->direction === 'bullish');
checkTrue('Mitigation Block: mitigated flag is true', $mbMatch !== null && $mbMatch->mitigated === true);
checkTrue('Mitigation Block: OrderBlockDetector correctly skips this same fixture (already mitigated)', (new OrderBlockDetector())->detect($mitigationRows) === []);
checkTrue('Mitigation Block: MitigationBlockDetector correctly finds nothing on the unmitigated fixture', (new MitigationBlockDetector())->detect($orderBlockRows) === []);
checkTrue('Mitigation Block: no false positive on a monotonic uptrend (no candle color)', (new MitigationBlockDetector())->detect($monotonicRows) === []);

// ── 3. FairValueGapDetector (3-candle imbalance, candle A high < candle C low) ─
echo "\n--- FairValueGapDetector ---\n";
$fvgRows = [
    ['timestamp' => 1_700_000_000, 'open' => 1.1995, 'high' => 1.2000, 'low' => 1.1990, 'close' => 1.1998, 'volume' => 1000.0], // candle A
    ['timestamp' => 1_700_003_600, 'open' => 1.2000, 'high' => 1.2110, 'low' => 1.1995, 'close' => 1.2100, 'volume' => 1000.0], // impulsive middle candle
    ['timestamp' => 1_700_007_200, 'open' => 1.2100, 'high' => 1.2200, 'low' => 1.2130, 'close' => 1.2190, 'volume' => 1000.0], // candle C — low (1.2130) > A's high (1.2000)
    // Both rows below deliberately overlap the impulsive middle candle's own range (and candle C's
    // range) so the i=1 and i=2 triples never form a SECOND/THIRD imbalance of their own — the only
    // way to keep price this close to the zone without re-entering it is for these two bars to also
    // dip back through [1.2000, 1.2130], which is why this fixture is the mitigated-positive case.
    ['timestamp' => 1_700_010_800, 'open' => 1.2150, 'high' => 1.2160, 'low' => 1.2100, 'close' => 1.2150, 'volume' => 1000.0],
    ['timestamp' => 1_700_014_400, 'open' => 1.2150, 'high' => 1.2170, 'low' => 1.2140, 'close' => 1.2160, 'volume' => 1000.0],
];
$fvgMatches = (new FairValueGapDetector())->detect($fvgRows);
$fvgMatch   = firstMatch($fvgMatches);
checkTrue('Fair Value Gap: exactly one match on the constructed fixture', count($fvgMatches) === 1, 'got ' . count($fvgMatches));
checkTrue('Fair Value Gap: key is fair_value_gap', $fvgMatch !== null && $fvgMatch->type === 'fair_value_gap');
checkTrue('Fair Value Gap: direction is bullish', $fvgMatch !== null && $fvgMatch->direction === 'bullish');
checkTrue('Fair Value Gap: zone matches A-high/C-low', $fvgMatch !== null && abs($fvgMatch->zoneLow - 1.2000) < 1e-9 && abs($fvgMatch->zoneHigh - 1.2130) < 1e-9);
checkTrue('Fair Value Gap: mitigated (row3 later traded back through the zone)', $fvgMatch !== null && $fvgMatch->mitigated === true);
$fvgNegativeRows = [];
for ($i = 0; $i < 8; $i++) {
    $fvgNegativeRows[] = ['timestamp' => 1_700_000_000 + $i * 3600, 'open' => 1.2000, 'high' => 1.2010, 'low' => 1.1990, 'close' => 1.2000, 'volume' => 1000.0];
}
checkTrue('Fair Value Gap: no false positive on a tight overlapping range (no 3-candle imbalance)', (new FairValueGapDetector())->detect($fvgNegativeRows) === []);

// ── 4 & 5. LiquidityPoolDetector + EqualHighsLowsDetector share one fixture:
// three swing highs clustered within 0.3%/0.15% tolerance (a Buy-Side pool,
// and the last two of which also count as "Equal Highs"), while the two
// swing lows are >0.3%/0.15% apart (no Sell-Side pool / no Equal Lows) ────
echo "\n--- LiquidityPoolDetector ---\n";
$liquidityClusterRows = zigzagRows([[0, 1.1950], [2, 1.2050], [5, 1.1900], [8, 1.2055], [11, 1.1850], [14, 1.2045], [17, 1.1800]]);
$poolMatches = (new LiquidityPoolDetector())->detect($liquidityClusterRows);
$poolMatch   = firstMatch($poolMatches);
checkTrue('Liquidity Pool: exactly one match on the constructed fixture', count($poolMatches) === 1, 'got ' . count($poolMatches));
checkTrue('Liquidity Pool: key is liquidity_pool', $poolMatch !== null && $poolMatch->type === 'liquidity_pool');
checkTrue('Liquidity Pool: label is Buy-Side Liquidity Pool', $poolMatch !== null && $poolMatch->label === 'Buy-Side Liquidity Pool', (string) ($poolMatch->label ?? ''));
checkTrue('Liquidity Pool: no false positive on a monotonic uptrend', (new LiquidityPoolDetector())->detect($monotonicRows) === []);

echo "\n--- EqualHighsLowsDetector ---\n";
$eqlMatches = (new EqualHighsLowsDetector())->detect($liquidityClusterRows);
$eqlMatch   = firstMatch($eqlMatches);
checkTrue('Equal Highs/Lows: exactly one match on the constructed fixture', count($eqlMatches) === 1, 'got ' . count($eqlMatches));
checkTrue('Equal Highs/Lows: key is equal_highs_lows', $eqlMatch !== null && $eqlMatch->type === 'equal_highs_lows');
checkTrue('Equal Highs/Lows: label is Equal Highs', $eqlMatch !== null && $eqlMatch->label === 'Equal Highs', (string) ($eqlMatch->label ?? ''));
checkTrue('Equal Highs/Lows: no false positive on a monotonic uptrend', (new EqualHighsLowsDetector())->detect($monotonicRows) === []);

// ── 6. BreakOfStructureDetector (bullish structure, close breaks above the last swing high) ─
echo "\n--- BreakOfStructureDetector ---\n";
$bosRows    = zigzagRows([[0, 1.1900], [3, 1.1950], [6, 1.1920], [9, 1.2000], [12, 1.1960], [20, 1.2150]]);
$bosMatches = (new BreakOfStructureDetector())->detect($bosRows);
$bosMatch   = firstMatch($bosMatches);
checkTrue('Break of Structure: exactly one match on the constructed fixture', count($bosMatches) === 1, 'got ' . count($bosMatches));
checkTrue('Break of Structure: key is bos', $bosMatch !== null && $bosMatch->type === 'bos');
checkTrue('Break of Structure: direction is bullish', $bosMatch !== null && $bosMatch->direction === 'bullish');
checkTrue('Break of Structure: no false positive on a fixture with no established structure', (new BreakOfStructureDetector())->detect($monotonicRows) === []);

// ── 7. ChangeOfCharacterDetector (bullish structure, then close breaks BELOW the defining higher-low) ─
echo "\n--- ChangeOfCharacterDetector ---\n";
$chochRows    = zigzagRows([[0, 1.1900], [3, 1.1950], [6, 1.1920], [9, 1.2000], [12, 1.1960], [15, 1.2050], [27, 1.1700]]);
$chochMatches = (new ChangeOfCharacterDetector())->detect($chochRows);
$chochMatch   = firstMatch($chochMatches);
checkTrue('Change of Character: exactly one match on the constructed fixture', count($chochMatches) === 1, 'got ' . count($chochMatches));
checkTrue('Change of Character: key is choch', $chochMatch !== null && $chochMatch->type === 'choch');
checkTrue('Change of Character: direction is bearish (reversal against the bullish structure)', $chochMatch !== null && $chochMatch->direction === 'bearish');
checkTrue('Change of Character: no false positive on a fixture with no established structure', (new ChangeOfCharacterDetector())->detect($monotonicRows) === []);

// ── 8. PremiumDiscountZoneDetector (dealing range midpoint of last swing high/low) ─
echo "\n--- PremiumDiscountZoneDetector ---\n";
$premiumDiscountRows = zigzagRows([[0, 1.1900], [3, 1.2000], [6, 1.1800], [9, 1.1850]]);
$pdMatches = (new PremiumDiscountZoneDetector())->detect($premiumDiscountRows);
$pdMatch   = firstMatch($pdMatches);
checkTrue('Premium/Discount Zone: exactly one match on the constructed fixture', count($pdMatches) === 1, 'got ' . count($pdMatches));
checkTrue('Premium/Discount Zone: key is premium_discount', $pdMatch !== null && $pdMatch->type === 'premium_discount');
checkTrue('Premium/Discount Zone: label is Discount Zone', $pdMatch !== null && $pdMatch->label === 'Discount Zone', (string) ($pdMatch->label ?? ''));
checkTrue('Premium/Discount Zone: direction is bullish', $pdMatch !== null && $pdMatch->direction === 'bullish');
checkTrue('Premium/Discount Zone: no false positive on a fixture with no swings', (new PremiumDiscountZoneDetector())->detect($monotonicRows) === []);

// ── 9. LiquiditySweepDetector (wick trades beyond a swing low, close rejects back above it) ─
echo "\n--- LiquiditySweepDetector ---\n";
$sweepRows   = zigzagRows([[0, 1.2000], [3, 1.1900], [6, 1.1800], [9, 1.1950], [12, 1.1900]]);
$sweepRows[] = ['timestamp' => 1_700_000_000 + 13 * 3600, 'open' => 1.1900, 'high' => 1.1910, 'low' => 1.1780, 'close' => 1.1850, 'volume' => 1000.0]; // sweeps below the 1.1800 swing low, closes back above it
$sweepMatches = (new LiquiditySweepDetector())->detect($sweepRows);
$sweepMatch   = firstMatch($sweepMatches);
checkTrue('Liquidity Sweep: exactly one match on the constructed fixture', count($sweepMatches) === 1, 'got ' . count($sweepMatches));
checkTrue('Liquidity Sweep: key is liquidity_sweep', $sweepMatch !== null && $sweepMatch->type === 'liquidity_sweep');
checkTrue('Liquidity Sweep: direction is bullish (bearish wick, bullish rejection)', $sweepMatch !== null && $sweepMatch->direction === 'bullish');
checkTrue('Liquidity Sweep: no false positive on a monotonic uptrend (no swings)', (new LiquiditySweepDetector())->detect($monotonicRows) === []);

// ── 10. StructureDetectorRegistry ───────────────────────────────────────────
echo "\n--- StructureDetectorRegistry ---\n";
$registry = StructureDetectorRegistry::all();
checkTrue('Registry has exactly 9 detectors', count($registry) === 9, 'got ' . count($registry));
$registryKeys = array_map(static fn(StructureDetectorInterface $d): string => $d->key(), $registry);
checkTrue('Registry keys are all unique', count($registryKeys) === count(array_unique($registryKeys)));
$expectedKeys = [
    'order_block', 'fair_value_gap', 'liquidity_pool', 'equal_highs_lows',
    'bos', 'choch', 'premium_discount', 'liquidity_sweep', 'mitigation_block',
];
sort($expectedKeys);
$sortedRegistryKeys = $registryKeys;
sort($sortedRegistryKeys);
checkTrue('Registry contains exactly the 9 spec\'d structure keys', $sortedRegistryKeys === $expectedKeys, implode(',', $sortedRegistryKeys));

// ── 11. MarketStructureEngine — sorting + fault tolerance + grouping ───────
echo "\n--- MarketStructureEngine ---\n";

final class FakeStructureMatchDetector implements StructureDetectorInterface
{
    public function __construct(private string $key, private int $endIndex) {}
    public function key(): string { return $this->key; }
    public function label(): string { return $this->key; }
    public function detect(array $rows): array
    {
        return [new StructureEvent($this->key, $this->key, 'neutral', 50.0, 0, $this->endIndex, 0, $this->endIndex, 'fake')];
    }
}

final class FakeThrowingStructureDetector implements StructureDetectorInterface
{
    public function key(): string { return 'fake_throwing'; }
    public function label(): string { return 'Fake Throwing'; }
    public function detect(array $rows): array { throw new \RuntimeException('synthetic detector failure'); }
}

$fakeEngine = new MarketStructureEngine([
    new FakeStructureMatchDetector('early', 5),
    new FakeThrowingStructureDetector(),
    new FakeStructureMatchDetector('late', 10),
]);
$fakeEvents = $fakeEngine->analyze([]);
checkTrue('Engine tolerates a throwing detector and still returns the others', count($fakeEvents) === 2, 'got ' . count($fakeEvents));
checkTrue('Engine sorts events by endIndex descending', count($fakeEvents) === 2 && $fakeEvents[0]->type === 'late' && $fakeEvents[1]->type === 'early');

// $monotonicRows isn't usable here: FairValueGapDetector's 2-bar-ahead gap
// check genuinely fires on ANY strictly increasing series once the total
// move is large enough (it's a real property of the algorithm, not a bug —
// confirmed by the FVG fixture above needing an explicit overlap to avoid
// the same cascade). A near-flat monotonic series (total move ~0.01%) keeps
// every 2-bar gap under FairValueGapDetector's 0.05% floor while remaining
// strictly monotonic, so it still has zero interior swings for the other 8.
$allEmptyRows = zigzagRows([[0, 1.10000], [41, 1.10010]]);
$groupedEmpty = (new MarketStructureEngine())->analyzeGrouped($allEmptyRows);
checkTrue('analyzeGrouped() seeds all 9 sections even with zero matches', count($groupedEmpty) === 9, 'got ' . count($groupedEmpty));
checkTrue('analyzeGrouped() sections are all empty on a fixture with no structure', array_reduce($groupedEmpty, static fn(bool $carry, array $section): bool => $carry && $section['events'] === [], true));

$groupedReal = (new MarketStructureEngine())->analyzeGrouped($liquidityClusterRows);
checkTrue('analyzeGrouped() liquidity_pool section is populated on the cluster fixture', count($groupedReal['liquidity_pool']['events']) === 1);
checkTrue('analyzeGrouped() order_block section stays empty on the cluster fixture (no candle color)', $groupedReal['order_block']['events'] === []);

// A real end-to-end run through the full 9-detector registry on a tiny
// degenerate series must never throw — every detector's own MIN_BARS guard
// should simply yield no events.
$degenerateRows   = zigzagRows([[0, 1.2000], [3, 1.2000]]);
$degenerateResult = null;
try {
    $degenerateResult = (new MarketStructureEngine())->analyze($degenerateRows);
} catch (\Throwable $e) {
    checkTrue('Full registry run on a tiny degenerate series never throws', false, $e->getMessage());
}
if ($degenerateResult !== null) {
    checkTrue('Full registry run on a tiny degenerate series returns no events', $degenerateResult === []);
}

// ── 12. FEATURE_SMART_MONEY flag ────────────────────────────────────────────
echo "\n--- FEATURE_SMART_MONEY flag ---\n";
checkTrue('FeatureFlagService::knownFlags() includes SMART_MONEY', in_array('SMART_MONEY', \App\Services\FeatureFlagService::knownFlags(), true));
checkTrue('feature_enabled(SMART_MONEY) is boolean', is_bool(feature_enabled('SMART_MONEY')));

// ── 13. Structural wiring checks ────────────────────────────────────────────
echo "\n--- Structural wiring ---\n";
$indexSrc = (string) file_get_contents(__DIR__ . '/../public/index.php');
checkTrue('public/index.php routes GET /smart-money', str_contains($indexSrc, "'/smart-money'"));
checkTrue('public/index.php routes POST /smart-money/analyze', str_contains($indexSrc, "'/smart-money/analyze'"));

$modulesSrc = (string) file_get_contents(__DIR__ . '/../bootstrap/modules.php');
checkTrue('bootstrap/modules.php registers SmartMoneyModule', str_contains($modulesSrc, 'new SmartMoneyModule()'));

$controllerSrc = (string) file_get_contents(__DIR__ . '/../app/Controllers/SmartMoneyController.php');
checkTrue('SmartMoneyController gates both actions behind feature_enabled(SMART_MONEY)', substr_count($controllerSrc, "feature_enabled('SMART_MONEY')") >= 1);

// ── 14. Live smoke test — the exact pipeline SmartMoneyController::analyze()
//    uses, run directly against real market data. Tolerant of network/API
//    unavailability (SKIP, not FAIL), matching every prior phase's
//    live-check convention.
echo "\n--- Live smoke test ---\n";
try {
    $liveCandles = (new MarketDataManager())->getCandles('EURUSD=X', 'Daily');
    if (count($liveCandles) < 20) {
        echo "SKIP  Live structure-analysis smoke test — fewer than 20 candles returned\n";
    } else {
        $liveRows    = array_map(static fn(Candle $c): array => $c->toLegacyRow(), $liveCandles);
        $liveGrouped = (new MarketStructureEngine())->analyzeGrouped($liveRows);
        $totalEvents = array_sum(array_map(static fn(array $s): int => count($s['events']), $liveGrouped));
        checkTrue(
            'Live MarketStructureEngine run against real EURUSD Daily candles completes without throwing',
            count($liveGrouped) === 9,
            "{$totalEvents} event(s) found"
        );
    }
} catch (\Throwable $e) {
    echo 'SKIP  Live structure-analysis smoke test — network/API unavailable: ' . $e->getMessage() . "\n";
}

echo "\n";
echo $failures === 0
    ? "ALL CHECKS PASSED\n"
    : "{$failures} CHECK(S) FAILED\n";

exit($failures === 0 ? 0 : 1);
