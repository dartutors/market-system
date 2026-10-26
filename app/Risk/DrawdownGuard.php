<?php
declare(strict_types=1);
namespace App\Risk;

use App\Config\Risk as RiskConfig;
use App\Models\SignalEvent;

/**
 * Detects a consecutive-loss streak in the most recently closed signals
 * (SignalEvent::recentOutcomes()) and recommends cutting position size
 * rather than continuing at full risk — the "drawdown protection" the phase
 * calls for. There is no live equity-curve tracking anywhere in this system
 * (App\Services\BacktestService's max_drawdown_r is backtest-only), so this
 * uses the one real signal available: an actual losing streak on closed trades.
 */
final class DrawdownGuard
{
    /**
     * @return array{consecutive_losses:int, triggered:bool, recommended_risk_multiplier:float, message:string}
     */
    public function assess(int $lookback = 10): array
    {
        $outcomes = SignalEvent::recentOutcomes($lookback);

        $streak = 0;
        foreach ($outcomes as $outcome) {
            if ($outcome !== 'loss') {
                break;
            }
            $streak++;
        }

        $threshold = RiskConfig::drawdownLossStreakThreshold();
        $triggered = $streak >= $threshold;
        $multiplier = $triggered ? RiskConfig::drawdownRiskMultiplier() : 1.0;

        $message = match (true) {
            $triggered => "{$streak} consecutive losses recorded — drawdown protection suggests risking "
                        . 'only ' . round($multiplier * 100) . '% of your normal size until a win breaks the streak.',
            $streak > 0 => "{$streak} consecutive loss(es) recorded — below the {$threshold}-loss threshold, "
                         . 'no size reduction suggested yet, but stay disciplined.',
            default     => 'No active losing streak — normal position sizing applies.',
        };

        return [
            'consecutive_losses'           => $streak,
            'triggered'                     => $triggered,
            'recommended_risk_multiplier'  => $multiplier,
            'message'                       => $message,
        ];
    }
}
