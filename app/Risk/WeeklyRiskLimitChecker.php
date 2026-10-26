<?php
declare(strict_types=1);
namespace App\Risk;

use App\Config\Risk as RiskConfig;
use App\Models\SignalEvent;
use DateTimeImmutable;

/**
 * Same approximation as DailyRiskLimitChecker, extended to the calendar week
 * (Monday-Sunday) containing the given date — a "weekly risk limit" concept
 * that did not exist anywhere in this codebase before Phase 2.5.
 */
final class WeeklyRiskLimitChecker
{
    /**
     * @return array{trades_this_week:int, proposed_risk_percent:float, projected_weekly_risk_percent:float, max_weekly_risk_percent:float, exceeded:bool, week_start:string, week_end:string}
     */
    public function check(string $signalDate, float $proposedRiskPercent): array
    {
        $date      = new DateTimeImmutable($signalDate);
        $weekStart = $date->modify('monday this week');
        $weekEnd   = $date->modify('sunday this week');

        $tradesThisWeek = SignalEvent::countByDateRange($weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d'));
        $maxWeekly      = RiskConfig::maxWeeklyRiskPercent();

        $projected = round(($tradesThisWeek + 1) * $proposedRiskPercent, 2);

        return [
            'trades_this_week'               => $tradesThisWeek,
            'proposed_risk_percent'          => $proposedRiskPercent,
            'projected_weekly_risk_percent'  => $projected,
            'max_weekly_risk_percent'        => $maxWeekly,
            'exceeded'                        => $projected > $maxWeekly,
            'week_start'                      => $weekStart->format('Y-m-d'),
            'week_end'                        => $weekEnd->format('Y-m-d'),
        ];
    }
}
