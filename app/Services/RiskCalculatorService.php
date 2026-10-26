<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Simplified forex position-size / risk calculator.
 *
 * Assumptions:
 * - Standard lot = 100,000 units
 * - USD-quoted pairs: pip = 0.0001 × lot_size
 * - JPY pairs:        pip = 0.01   × lot_size / rate (approximated at a reference rate)
 *
 * For educational use only. Always verify with your broker's calculator.
 */
final class RiskCalculatorService
{
    /** Approximate pip value per standard lot in USD for common pairs. */
    private const PIP_VALUES = [
        'EUR/USD' => 10.0,
        'GBP/USD' => 10.0,
        'USD/JPY' => 9.10,   // approx at USD/JPY ~110
        'AUD/USD' => 10.0,
        'USD/CHF' => 10.0,
        'USD/CAD' => 7.70,   // approx
    ];

    /**
     * @return array{
     *   risk_amount:    float,
     *   lot_size:       float,
     *   mini_lots:      float,
     *   micro_lots:     float,
     *   pip_value:      float,
     *   stop_loss_cost: float,
     *   risk_reward_2r: float,
     *   pair:           string,
     *   note:           string,
     * }
     */
    public function calculate(
        float  $balance,
        float  $riskPercent,
        float  $stopPips,
        string $pair = 'EUR/USD'
    ): array {
        $balance    = max(0.0, $balance);
        $riskPct    = min(100.0, max(0.01, $riskPercent));
        $stopPips   = max(0.1, $stopPips);
        $pipValue   = self::PIP_VALUES[$pair] ?? 10.0;

        $riskAmount   = $balance * ($riskPct / 100.0);
        $lotSize      = $stopPips > 0 ? $riskAmount / ($stopPips * $pipValue) : 0.0;
        $miniLots     = $lotSize * 10.0;
        $microLots    = $lotSize * 100.0;
        $stopLossCost = $riskAmount;
        $rr2Target    = $riskAmount * 2.0;   // 2R target profit

        return [
            'risk_amount'    => round($riskAmount,   2),
            'lot_size'       => round($lotSize,       4),
            'mini_lots'      => round($miniLots,      3),
            'micro_lots'     => round($microLots,     2),
            'pip_value'      => round($pipValue,      2),
            'stop_loss_cost' => round($stopLossCost,  2),
            'risk_reward_2r' => round($rr2Target,     2),
            'pair'           => $pair,
            'note'           => 'Informational estimate only. Verify with your broker.',
        ];
    }
}
