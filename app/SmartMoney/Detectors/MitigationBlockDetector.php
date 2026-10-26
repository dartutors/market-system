<?php
declare(strict_types=1);
namespace App\SmartMoney\Detectors;

use App\SmartMoney\Contracts\StructureDetectorInterface;
use App\SmartMoney\Models\StructureEvent;
use App\SmartMoney\Support\OrderBlockFinder;

/** An order-block zone that price has since traded back through — no longer a fresh zone. */
final class MitigationBlockDetector implements StructureDetectorInterface
{
    public function key(): string
    {
        return 'mitigation_block';
    }

    public function label(): string
    {
        return 'Mitigation Blocks';
    }

    public function detect(array $rows): array
    {
        $events = [];
        foreach (OrderBlockFinder::findAll($rows) as $c) {
            if ($c['mitigatedAtIndex'] === null) {
                continue;
            }

            $label = $c['direction'] === 'bullish' ? 'Bullish Mitigation Block' : 'Bearish Mitigation Block';
            $events[] = new StructureEvent(
                'mitigation_block',
                $label,
                $c['direction'],
                60.0,
                $c['index'],
                $c['mitigatedAtIndex'],
                (int) $rows[$c['index']]['timestamp'],
                (int) $rows[$c['mitigatedAtIndex']]['timestamp'],
                sprintf('%s: an order block zone %.5f - %.5f that price has since traded back through (mitigated).', $label, $c['low'], $c['high']),
                $c['high'],
                $c['low'],
                true
            );
        }

        return array_slice(array_reverse($events), 0, 10);
    }
}
