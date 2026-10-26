<?php
declare(strict_types=1);
namespace App\Risk;

use App\Services\RiskCalculatorService;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Phase 2.5 deliverable: composes position sizing, stop-loss/take-profit/
 * risk-reward calculators, and the daily/weekly/drawdown/exposure/
 * correlation guardrails into one actionable assessment for a trade the
 * user is considering — before they enter it.
 */
final class RiskAssessmentEngine
{
    public function __construct(
        private RiskCalculatorService $positionSizer = new RiskCalculatorService(),
        private StopLossCalculator $stopLossCalculator = new StopLossCalculator(),
        private TakeProfitCalculator $takeProfitCalculator = new TakeProfitCalculator(),
        private RiskRewardCalculator $riskRewardCalculator = new RiskRewardCalculator(),
        private DailyRiskLimitChecker $dailyLimitChecker = new DailyRiskLimitChecker(),
        private WeeklyRiskLimitChecker $weeklyLimitChecker = new WeeklyRiskLimitChecker(),
        private DrawdownGuard $drawdownGuard = new DrawdownGuard(),
        private ExposureTracker $exposureTracker = new ExposureTracker(),
        private CorrelationAnalyzer $correlationAnalyzer = new CorrelationAnalyzer(),
    ) {
    }

    public function assess(
        string $marketLabel,
        string $direction,
        float $balance,
        float $riskPercent,
        float $entryPrice,
        ?float $stopPrice = null,
        ?float $stopPips = null,
        ?float $atr14 = null,
        ?float $targetPrice = null,
        ?string $signalDate = null,
    ): RiskAssessment {
        $direction  = strtoupper(trim($direction)) === 'SELL' ? 'SELL' : 'BUY';
        $signalDate = $signalDate ?? now_eat()->format('Y-m-d');

        $stopLoss = $this->resolveStop($entryPrice, $direction, $marketLabel, $stopPrice, $stopPips, $atr14);

        $positionSizing = $this->positionSizer->calculate($balance, $riskPercent, $stopLoss['stop_pips'], $marketLabel);
        $takeProfitLevels = $this->takeProfitCalculator->levels($entryPrice, $stopLoss['stop_price'], $direction);

        $riskReward = $targetPrice !== null
            ? $this->riskRewardCalculator->evaluate($entryPrice, $stopLoss['stop_price'], $targetPrice)
            : null;

        $dailyLimit  = $this->dailyLimitChecker->check($signalDate, $riskPercent);
        $weeklyLimit = $this->weeklyLimitChecker->check($signalDate, $riskPercent);
        $drawdown    = $this->drawdownGuard->assess();
        $exposure    = $this->exposureTracker->assess();
        $correlationWarnings = $this->correlationAnalyzer->warningsFor($marketLabel, $direction);

        $overallGuidance = $this->buildGuidance($dailyLimit, $weeklyLimit, $drawdown, $exposure, $correlationWarnings, $riskReward);

        return new RiskAssessment(
            marketLabel: $marketLabel,
            direction: $direction,
            entryPrice: $entryPrice,
            positionSizing: $positionSizing,
            stopLoss: $stopLoss,
            takeProfitLevels: $takeProfitLevels,
            riskReward: $riskReward,
            dailyLimit: $dailyLimit,
            weeklyLimit: $weeklyLimit,
            drawdown: $drawdown,
            exposure: $exposure,
            correlationWarnings: $correlationWarnings,
            overallGuidance: $overallGuidance,
            generatedAt: new DateTimeImmutable('now'),
        );
    }

    /** @return array{stop_price:float, stop_distance:float, stop_pips:float} */
    private function resolveStop(
        float $entryPrice,
        string $direction,
        string $marketLabel,
        ?float $stopPrice,
        ?float $stopPips,
        ?float $atr14
    ): array {
        if ($stopPrice !== null) {
            $distance = abs($entryPrice - $stopPrice);
            return [
                'stop_price'    => round($stopPrice, 6),
                'stop_distance' => round($distance, 6),
                'stop_pips'     => round(PipMath::priceDistanceToPips($distance, $marketLabel), 1),
            ];
        }

        if ($stopPips !== null) {
            return $this->stopLossCalculator->fromPips($entryPrice, $direction, $stopPips, $marketLabel);
        }

        if ($atr14 !== null) {
            $suggestion = $this->stopLossCalculator->fromAtr($entryPrice, $direction, $atr14, $marketLabel);
            unset($suggestion['atr_multiple']);
            return $suggestion;
        }

        throw new InvalidArgumentException('A stop price, stop distance in pips, or ATR value is required to size this trade.');
    }

    /**
     * @param array<string> $correlationWarnings
     */
    private function buildGuidance(
        array $dailyLimit,
        array $weeklyLimit,
        array $drawdown,
        array $exposure,
        array $correlationWarnings,
        ?array $riskReward
    ): string {
        if ($drawdown['triggered']) {
            return 'Drawdown protection: ' . $drawdown['message'];
        }

        if ($weeklyLimit['exceeded']) {
            return "Weekly risk limit: taking this trade would bring committed risk to roughly {$weeklyLimit['projected_weekly_risk_percent']}% "
                 . "for the week ({$weeklyLimit['week_start']} to {$weeklyLimit['week_end']}), above the {$weeklyLimit['max_weekly_risk_percent']}% limit — consider skipping or reducing size.";
        }

        if ($dailyLimit['exceeded']) {
            return "Daily risk limit: taking this trade would bring committed risk to roughly {$dailyLimit['projected_daily_risk_percent']}% "
                 . "for today, above the {$dailyLimit['max_daily_risk_percent']}% limit — consider skipping or reducing size.";
        }

        if (!empty($exposure['warnings'])) {
            return 'Exposure warning: ' . $exposure['warnings'][0];
        }

        if (!empty($correlationWarnings)) {
            return 'Correlation warning: ' . $correlationWarnings[0];
        }

        if ($riskReward !== null && !$riskReward['meets_minimum']) {
            return 'Risk/reward: ' . $riskReward['verdict'];
        }

        return 'No guardrails triggered — position size, stop, and targets look reasonable against your current limits. Still confirm the setup yourself before entering.';
    }
}
