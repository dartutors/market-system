<?php
declare(strict_types=1);
namespace App\Risk;

use App\Config\Risk as RiskConfig;
use App\Models\SignalEvent;

/**
 * Projects today's cumulative committed risk% by assuming today's already-
 * taken BUY/SELL signals (SignalEvent::countByDate() — the same count
 * App\Services\TradeLimiterService uses for the trade-count cap) each risked
 * roughly the same percentage as the trade being sized now. This is a
 * deliberate approximation: no table persists actual per-trade risk% today
 * (see Phase 2.5 research), so this is the best available signal without
 * introducing a new ledger.
 */
final class DailyRiskLimitChecker
{
    /**
     * @return array{trades_today:int, proposed_risk_percent:float, projected_daily_risk_percent:float, max_daily_risk_percent:float, exceeded:bool}
     */
    public function check(string $signalDate, float $proposedRiskPercent): array
    {
        $tradesToday = SignalEvent::countByDate($signalDate);
        $maxDaily    = RiskConfig::maxDailyRiskPercent();

        // +1 for the trade currently being sized, which hasn't been taken yet.
        $projected = round(($tradesToday + 1) * $proposedRiskPercent, 2);

        return [
            'trades_today'                  => $tradesToday,
            'proposed_risk_percent'         => $proposedRiskPercent,
            'projected_daily_risk_percent'  => $projected,
            'max_daily_risk_percent'        => $maxDaily,
            'exceeded'                       => $projected > $maxDaily,
        ];
    }
}
