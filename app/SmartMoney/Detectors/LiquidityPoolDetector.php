<?php
declare(strict_types=1);
namespace App\SmartMoney\Detectors;

use App\Patterns\Models\SwingPoint;
use App\Patterns\Support\SwingPointDetector;
use App\SmartMoney\Contracts\StructureDetectorInterface;
use App\SmartMoney\Models\StructureEvent;

/**
 * Clusters of 2+ swing highs (or lows) within a wider tolerance than
 * EqualHighsLowsDetector — a broader "where does resting liquidity live"
 * view rather than a strict pairwise comparison of just the last two
 * swings. Highs cluster into buy-side liquidity (resting buy-stops above
 * resistance); lows cluster into sell-side liquidity (resting sell-stops
 * below support).
 */
final class LiquidityPoolDetector implements StructureDetectorInterface
{
    private const CLUSTER_TOLERANCE_PCT = 0.003; // 0.3%
    private const MIN_TOUCHES           = 2;
    private const MIN_BARS              = 10;

    public function key(): string
    {
        return 'liquidity_pool';
    }

    public function label(): string
    {
        return 'Liquidity Pools';
    }

    public function detect(array $rows): array
    {
        if (count($rows) < self::MIN_BARS) {
            return [];
        }

        $swings = (new SwingPointDetector())->detect($rows);
        $highs  = SwingPointDetector::onlyHighs($swings);
        $lows   = SwingPointDetector::onlyLows($swings);

        $events = array_merge(
            $this->clusterPoints($highs, 'Buy-Side Liquidity Pool'),
            $this->clusterPoints($lows, 'Sell-Side Liquidity Pool')
        );

        usort($events, static fn(StructureEvent $a, StructureEvent $b): int => $b->endIndex <=> $a->endIndex);
        return array_slice($events, 0, 10);
    }

    /** @param array<int, SwingPoint> $points @return array<int, StructureEvent> */
    private function clusterPoints(array $points, string $label): array
    {
        $events = [];
        $used   = [];
        $n      = count($points);

        for ($i = 0; $i < $n; $i++) {
            if (isset($used[$i])) {
                continue;
            }
            $cluster = [$points[$i]];
            for ($j = $i + 1; $j < $n; $j++) {
                if (isset($used[$j])) {
                    continue;
                }
                $diffPct = abs($points[$i]->price - $points[$j]->price) / $points[$i]->price;
                if ($diffPct <= self::CLUSTER_TOLERANCE_PCT) {
                    $cluster[] = $points[$j];
                    $used[$j]  = true;
                }
            }

            if (count($cluster) >= self::MIN_TOUCHES) {
                $prices     = array_map(static fn(SwingPoint $p): float => $p->price, $cluster);
                $indices    = array_map(static fn(SwingPoint $p): int => $p->index, $cluster);
                $timestamps = array_map(static fn(SwingPoint $p): int => $p->timestamp, $cluster);

                $events[] = new StructureEvent(
                    'liquidity_pool',
                    $label,
                    'neutral',
                    min(90.0, 55.0 + count($cluster) * 8.0),
                    min($indices),
                    max($indices),
                    min($timestamps),
                    max($timestamps),
                    sprintf('%d touches clustered near %.5f-%.5f — resting liquidity.', count($cluster), min($prices), max($prices)),
                    max($prices),
                    min($prices),
                    false
                );
            }
        }

        return $events;
    }
}
