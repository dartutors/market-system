<?php
declare(strict_types=1);
namespace App\Risk;

use DateTimeImmutable;

final class RiskAssessment
{
    /**
     * @param array $positionSizing RiskCalculatorService::calculate() shape
     * @param array $stopLoss StopLossCalculator shape
     * @param array<string,float> $takeProfitLevels
     * @param array|null $riskReward RiskRewardCalculator::evaluate() shape (null if no target given)
     * @param array $dailyLimit DailyRiskLimitChecker::check() shape
     * @param array $weeklyLimit WeeklyRiskLimitChecker::check() shape
     * @param array $drawdown DrawdownGuard::assess() shape
     * @param array $exposure ExposureTracker::assess() shape
     * @param array<string> $correlationWarnings
     */
    public function __construct(
        public readonly string $marketLabel,
        public readonly string $direction,
        public readonly float $entryPrice,
        public readonly array $positionSizing,
        public readonly array $stopLoss,
        public readonly array $takeProfitLevels,
        public readonly ?array $riskReward,
        public readonly array $dailyLimit,
        public readonly array $weeklyLimit,
        public readonly array $drawdown,
        public readonly array $exposure,
        public readonly array $correlationWarnings,
        public readonly string $overallGuidance,
        public readonly DateTimeImmutable $generatedAt,
    ) {
    }

    public function toArray(): array
    {
        return [
            'market_label'          => $this->marketLabel,
            'direction'              => $this->direction,
            'entry_price'            => $this->entryPrice,
            'position_sizing'        => $this->positionSizing,
            'stop_loss'              => $this->stopLoss,
            'take_profit_levels'     => $this->takeProfitLevels,
            'risk_reward'            => $this->riskReward,
            'daily_limit'            => $this->dailyLimit,
            'weekly_limit'           => $this->weeklyLimit,
            'drawdown'               => $this->drawdown,
            'exposure'               => $this->exposure,
            'correlation_warnings'   => $this->correlationWarnings,
            'overall_guidance'       => $this->overallGuidance,
            'generated_at'           => $this->generatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
