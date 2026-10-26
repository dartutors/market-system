<?php
declare(strict_types=1);
namespace App\Learning;

use DateTimeImmutable;

final class LearningOverview
{
    /**
     * @param array $tradeJournalStats App\Models\TradeJournal::stats() shape
     * @param array $confidenceCalibration ConfidenceCalibrationService::calculate() shape
     * @param array|null $rememberedCalibration Latest App\Models\AiLearningMemory row for 'confidence_calibration', if any
     * @param array<int,array> $strategyStats
     * @param array<int,array> $patternStats
     * @param array<int,array> $recommendationHistory
     */
    public function __construct(
        public readonly array $tradeJournalStats,
        public readonly array $confidenceCalibration,
        public readonly ?array $rememberedCalibration,
        public readonly array $strategyStats,
        public readonly array $patternStats,
        public readonly array $recommendationHistory,
        public readonly DateTimeImmutable $generatedAt,
    ) {
    }

    public function toArray(): array
    {
        return [
            'trade_journal_stats'     => $this->tradeJournalStats,
            'confidence_calibration'  => $this->confidenceCalibration,
            'remembered_calibration'  => $this->rememberedCalibration,
            'strategy_stats'          => $this->strategyStats,
            'pattern_stats'           => $this->patternStats,
            'recommendation_history'  => $this->recommendationHistory,
            'generated_at'            => $this->generatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
