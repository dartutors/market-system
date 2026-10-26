<?php
declare(strict_types=1);
namespace App\Risk;

use App\Config\Risk as RiskConfig;

final class RiskRewardCalculator
{
    /**
     * @return array{ratio:float, meets_minimum:bool, minimum_required:float, verdict:string}
     */
    public function evaluate(float $entry, float $stopPrice, float $targetPrice, ?float $minAcceptable = null): array
    {
        $minRatio = $minAcceptable ?? RiskConfig::minRiskRewardRatio();

        $risk   = abs($entry - $stopPrice);
        $reward = abs($targetPrice - $entry);
        $ratio  = $risk > 0.0 ? round($reward / $risk, 2) : 0.0;

        $meetsMinimum = $ratio >= $minRatio;

        $verdict = match (true) {
            $risk <= 0.0    => 'Stop-loss distance is zero — cannot evaluate risk/reward.',
            $meetsMinimum   => "Favorable: {$ratio}:1 reward-to-risk meets the {$minRatio}:1 minimum.",
            default         => "Below target: {$ratio}:1 reward-to-risk is under the {$minRatio}:1 minimum — consider a tighter stop or a farther target before entering.",
        };

        return [
            'ratio'            => $ratio,
            'meets_minimum'    => $meetsMinimum,
            'minimum_required' => $minRatio,
            'verdict'          => $verdict,
        ];
    }
}
