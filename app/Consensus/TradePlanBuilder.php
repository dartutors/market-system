<?php
declare(strict_types=1);
namespace App\Consensus;

use App\Config\Risk as RiskConfig;
use App\Learning\ConfidenceCalibrationService;
use App\Risk\RiskAssessmentEngine;
use Throwable;

/**
 * Objective 5's explicit rule: never generate entry/SL/TP/sizing for a WAIT
 * recommendation — build() returns null for anything but BUY/SELL. TP1/TP2
 * are the 1R/2R levels TakeProfitCalculator already produces (see
 * App\Risk\TakeProfitCalculator::levels()), which makes risk:reward exactly
 * 1:2 by construction — no separate RiskRewardCalculator call needed.
 * Position sizing uses App\Config\Risk::consensusReferenceBalance() (an
 * illustrative figure, always labeled as such — this app has no real
 * connected trading account anywhere).
 */
final class TradePlanBuilder
{
    /** @return array<string, mixed>|null Null for WAIT/NEUTRAL — never fabricates a trade plan for a non-actionable decision. */
    public function build(string $label, string $direction, array $technicalRaw, float $consensusScore): ?array
    {
        if (!\in_array($direction, [ConsensusVote::DIRECTION_BUY, ConsensusVote::DIRECTION_SELL], true)) {
            return null;
        }

        $entryPrice = $technicalRaw['entry_price'];
        $atr14      = $technicalRaw['atr14'];
        if ($entryPrice <= 0.0 || $atr14 <= 0.0) {
            return null;
        }

        try {
            $riskDirection = $direction === ConsensusVote::DIRECTION_BUY ? 'BUY' : 'SELL';
            $assessment = (new RiskAssessmentEngine())->assess(
                marketLabel: $label,
                direction: $riskDirection,
                balance: RiskConfig::consensusReferenceBalance(),
                riskPercent: RiskConfig::consensusReferenceRiskPercent(),
                entryPrice: $entryPrice,
                atr14: $atr14,
            )->toArray();
        } catch (Throwable $e) {
            error_log("[TradePlanBuilder] {$label} risk assessment failed: " . $e->getMessage());
            return null;
        }

        $takeProfitLevels = $assessment['take_profit_levels'];
        $positionSizing   = $assessment['position_sizing'];

        return [
            'entry_price'             => round($entryPrice, 6),
            'stop_loss'               => $assessment['stop_loss']['stop_price'],
            'take_profit_1'           => $takeProfitLevels['1R'] ?? null,
            'take_profit_2'           => $takeProfitLevels['2R'] ?? null,
            'risk_percent'            => RiskConfig::consensusReferenceRiskPercent(),
            'risk_reward_ratio'       => '1:2',
            'position_size_lots'      => $positionSizing['lot_size'],
            'reference_balance'       => RiskConfig::consensusReferenceBalance(),
            'estimated_loss'          => $positionSizing['risk_amount'],
            'estimated_profit'        => $positionSizing['risk_reward_2r'],
            'expected_duration_hours' => [$technicalRaw['hold_hours_min'], $technicalRaw['hold_hours_max']],
            'expected_probability'    => $this->expectedProbability($consensusScore),
            'overall_guidance'        => $assessment['overall_guidance'],
        ];
    }

    /**
     * Grounds "Expected Probability" in this system's own historical
     * confidence-vs-outcome calibration (ConfidenceCalibrationService) — the
     * same non-fabrication rule the rest of this app follows (e.g.
     * AiInsightService returns "insufficient data" rather than inventing a
     * number). Minimum sample-size gate (10) is enforced here since the
     * underlying service has none of its own.
     */
    private function expectedProbability(float $consensusScore): ?array
    {
        try {
            $calibration = (new ConfidenceCalibrationService())->calculate();
        } catch (Throwable $e) {
            error_log('[TradePlanBuilder] Calibration lookup failed: ' . $e->getMessage());
            return null;
        }

        $bandName = match (true) {
            $consensusScore >= 80 => '80%+',
            $consensusScore >= 70 => '70-79%',
            $consensusScore >= 60 => '60-69%',
            default               => 'Below 60%',
        };

        foreach ($calibration['by_band'] as $band) {
            if ($band['band'] === $bandName) {
                if ($band['sample_size'] < 10) {
                    return ['value' => null, 'note' => "Insufficient historical data ({$band['sample_size']} past signals in this confidence band) to estimate a probability."];
                }
                return ['value' => $band['actual_win_rate'], 'note' => "Based on {$band['sample_size']} past signals in the {$bandName} confidence band."];
            }
        }

        return ['value' => null, 'note' => 'No historical calibration data available yet.'];
    }
}
