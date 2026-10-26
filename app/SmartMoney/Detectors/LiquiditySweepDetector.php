<?php
declare(strict_types=1);
namespace App\SmartMoney\Detectors;

use App\Patterns\Models\SwingPoint;
use App\Patterns\Support\SwingPointDetector;
use App\SmartMoney\Contracts\StructureDetectorInterface;
use App\SmartMoney\Models\StructureEvent;

/** A wick trades beyond a prior swing high/low (grabbing resting stops) but the close rejects back — a false breakout / stop hunt. */
final class LiquiditySweepDetector implements StructureDetectorInterface
{
    private const MIN_BARS = 10;

    public function key(): string
    {
        return 'liquidity_sweep';
    }

    public function label(): string
    {
        return 'Liquidity Sweeps';
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

        $events = [];

        foreach ($highs as $swing) {
            $event = $this->findSweep($rows, $swing, $count, sweepHigh: true);
            if ($event !== null) {
                $events[] = $event;
            }
        }
        foreach ($lows as $swing) {
            $event = $this->findSweep($rows, $swing, $count, sweepHigh: false);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        usort($events, static fn(StructureEvent $a, StructureEvent $b): int => $b->endIndex <=> $a->endIndex);
        return array_slice($events, 0, 10);
    }

    private function findSweep(array $rows, SwingPoint $swing, int $count, bool $sweepHigh): ?StructureEvent
    {
        for ($i = $swing->index + 1; $i < $count; $i++) {
            $high  = (float) $rows[$i]['high'];
            $low   = (float) $rows[$i]['low'];
            $close = (float) $rows[$i]['close'];

            if ($sweepHigh && $high > $swing->price && $close < $swing->price) {
                return new StructureEvent(
                    'liquidity_sweep', 'Bearish Liquidity Sweep', 'bearish', 62.0,
                    $swing->index, $i, $swing->timestamp, (int) $rows[$i]['timestamp'],
                    sprintf('Wick swept above the swing high %.5f but closed back below it at %.5f — stop hunt, bearish rejection.', $swing->price, $close),
                    $high, $swing->price, false
                );
            }
            if (!$sweepHigh && $low < $swing->price && $close > $swing->price) {
                return new StructureEvent(
                    'liquidity_sweep', 'Bullish Liquidity Sweep', 'bullish', 62.0,
                    $swing->index, $i, $swing->timestamp, (int) $rows[$i]['timestamp'],
                    sprintf('Wick swept below the swing low %.5f but closed back above it at %.5f — stop hunt, bullish rejection.', $swing->price, $close),
                    $swing->price, $low, false
                );
            }
        }
        return null;
    }
}
