<?php
declare(strict_types=1);
namespace App\Indicators;

use App\Indicators\Contracts\IndicatorInterface;
use App\Indicators\Support\SeriesMath;

/**
 * Average Directional Index — simplified variant using SMA smoothing rather
 * than true Wilder smoothing (sufficient for a not-yet-consumed indicator;
 * revisit if a future consumer needs the exact Wilder formula).
 * New indicator, not part of the legacy default registry.
 */
final class ADX implements IndicatorInterface
{
    use SeriesMath;

    public function __construct(private int $period = 14)
    {
    }

    public function key(): string
    {
        return 'adx_14';
    }

    public function apply(array $rows): array
    {
        $count   = count($rows);
        $plusDm  = [];
        $minusDm = [];
        $tr      = [];

        for ($i = 0; $i < $count; $i++) {
            if ($i === 0) {
                $plusDm[$i]  = 0.0;
                $minusDm[$i] = 0.0;
                $tr[$i]      = (float) $rows[$i]['high'] - (float) $rows[$i]['low'];
                continue;
            }

            $upMove   = (float) $rows[$i]['high']     - (float) $rows[$i - 1]['high'];
            $downMove = (float) $rows[$i - 1]['low']  - (float) $rows[$i]['low'];

            $plusDm[$i]  = ($upMove > $downMove && $upMove > 0)   ? $upMove   : 0.0;
            $minusDm[$i] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;

            $hl     = (float) $rows[$i]['high'] - (float) $rows[$i]['low'];
            $hc     = abs((float) $rows[$i]['high'] - (float) $rows[$i - 1]['close']);
            $lc     = abs((float) $rows[$i]['low']  - (float) $rows[$i - 1]['close']);
            $tr[$i] = max($hl, $hc, $lc);
        }

        $smoothedTr      = $this->smaSeries($tr, $this->period);
        $smoothedPlusDm  = $this->smaSeries($plusDm, $this->period);
        $smoothedMinusDm = $this->smaSeries($minusDm, $this->period);

        $dx = [];
        for ($i = 0; $i < $count; $i++) {
            if ($smoothedTr[$i] === null || $smoothedTr[$i] == 0.0) {
                $dx[$i] = 0.0;
                continue;
            }
            $plusDi  = 100 * ($smoothedPlusDm[$i]  ?? 0.0) / $smoothedTr[$i];
            $minusDi = 100 * ($smoothedMinusDm[$i] ?? 0.0) / $smoothedTr[$i];
            $sum     = $plusDi + $minusDi;
            $dx[$i]  = $sum > 0 ? 100 * abs($plusDi - $minusDi) / $sum : 0.0;
        }

        $adx = $this->smaSeries($dx, $this->period);

        foreach ($rows as $i => $row) {
            $rows[$i]['adx_14'] = $adx[$i] ?? 0.0;
        }

        return $rows;
    }
}
