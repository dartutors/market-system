<?php
declare(strict_types=1);
namespace App\SmartMoney\Detectors;

use App\SmartMoney\Contracts\StructureDetectorInterface;
use App\SmartMoney\Models\StructureEvent;
use App\SmartMoney\Support\OrderBlockFinder;

/** The last opposing candle before an impulsive move, still unmitigated (price hasn't traded back through it). */
final class OrderBlockDetector implements StructureDetectorInterface
{
    public function key(): string
    {
        return 'order_block';
    }

    public function label(): string
    {
        return 'Order Blocks';
    }

    public function detect(array $rows): array
    {
        $events = [];
        foreach (OrderBlockFinder::findAll($rows) as $c) {
            if ($c['mitigatedAtIndex'] !== null) {
                continue; // already mitigated — MitigationBlockDetector's job to report
            }

            $label = $c['direction'] === 'bullish' ? 'Bullish Order Block' : 'Bearish Order Block';
            $events[] = new StructureEvent(
                'order_block',
                $label,
                $c['direction'],
                65.0,
                $c['index'],
                $c['impulseEndIndex'],
                (int) $rows[$c['index']]['timestamp'],
                (int) $rows[$c['impulseEndIndex']]['timestamp'],
                sprintf('%s: last opposing candle before an impulsive move, zone %.5f - %.5f (still unmitigated).', $label, $c['low'], $c['high']),
                $c['high'],
                $c['low'],
                false
            );
        }

        return array_slice(array_reverse($events), 0, 10);
    }
}
