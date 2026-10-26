<?php
declare(strict_types=1);
namespace App\Learning;

use App\Models\AiLearningMemory;
use App\Models\PatternStatistic;
use App\Models\StrategyStatistic;
use App\Models\TradeJournal;
use DateTimeImmutable;

/**
 * Phase 2.8 deliverable: composes historical-trade learning (Trade Journal),
 * backtest learning (Strategy Statistics), pattern success tracking, and
 * confidence calibration into one overview, plus a partial recommendation
 * history. Read-only / on-demand — the actual "recommendations improve"
 * mechanism lives in App\Decision\DecisionEngine reading
 * App\Models\AiLearningMemory directly, not through this class.
 */
final class LearningEngine
{
    public function __construct(
        private ConfidenceCalibrationService $calibrationService = new ConfidenceCalibrationService(),
        private RecommendationHistoryService $historyService = new RecommendationHistoryService(),
    ) {
    }

    public function buildOverview(): LearningOverview
    {
        return new LearningOverview(
            tradeJournalStats: TradeJournal::stats(),
            confidenceCalibration: $this->calibrationService->calculate(),
            rememberedCalibration: AiLearningMemory::latest(ConfidenceCalibrationService::memoryKey()),
            strategyStats: StrategyStatistic::latest(10),
            patternStats: PatternStatistic::latest(10),
            recommendationHistory: $this->historyService->recent(20),
            generatedAt: new DateTimeImmutable('now'),
        );
    }
}
