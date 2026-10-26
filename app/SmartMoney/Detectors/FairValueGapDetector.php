<?php
declare(strict_types=1);
namespace App\SmartMoney\Detectors;

use App\SmartMoney\Contracts\StructureDetectorInterface;
use App\SmartMoney\Models\StructureEvent;

/** A 3-candle imbalance: candle A's high/low doesn't overlap candle C's low/high, leaving an unfilled gap. */
final class FairValueGapDetector implements StructureDetectorInterface
{
    private const MIN_GAP_PCT = 0.0005; // 0.05% — filters out insignificant noise gaps

    public function key(): string
    {
        return 'fair_value_gap';
    }

    public function label(): string
    {
        return 'Fair Value Gaps';
    }

    public function detect(array $rows): array
    {
        $count = count($rows);
        if ($count < 5) {
            return [];
        }

        $events = [];
        for ($i = 0; $i <= $count - 3; $i++) {
            $a = $rows[$i];
            $c = $rows[$i + 2];
            $aHigh = (float) $a['high'];
            $aLow  = (float) $a['low'];
            $cHigh = (float) $c['high'];
            $cLow  = (float) $c['low'];

            if ($aHigh < $cLow) {
                $direction = 'bullish';
                $zoneLow   = $aHigh;
                $zoneHigh  = $cLow;
            } elseif ($aLow > $cHigh) {
                $direction = 'bearish';
                $zoneLow   = $cHigh;
                $zoneHigh  = $aLow;
            } else {
                continue;
            }

            $mid    = ($zoneHigh + $zoneLow) / 2;
            $gapPct = $mid > 0.0 ? ($zoneHigh - $zoneLow) / $mid : 0.0;
            if ($gapPct < self::MIN_GAP_PCT) {
                continue;
            }

            $mitigated = false;
            for ($j = $i + 3; $j < $count; $j++) {
                $low  = (float) $rows[$j]['low'];
                $high = (float) $rows[$j]['high'];
                if ($low <= $zoneHigh && $high >= $zoneLow) {
                    $mitigated = true;
                    break;
                }
            }

            $label      = $direction === 'bullish' ? 'Bullish Fair Value Gap' : 'Bearish Fair Value Gap';
            $events[]   = new StructureEvent(
                'fair_value_gap',
                $label,
                $direction,
                60.0,
                $i,
                $i + 2,
                (int) $a['timestamp'],
                (int) $c['timestamp'],
                sprintf(
                    '%s: 3-candle imbalance zone %.5f - %.5f (%.2f%% wide)%s.',
                    $label,
                    $zoneLow,
                    $zoneHigh,
                    $gapPct * 100,
                    $mitigated ? ' — mitigated' : ' — unmitigated'
                ),
                $zoneHigh,
                $zoneLow,
                $mitigated
            );
        }

        return array_slice(array_reverse($events), 0, 10);
    }
}
