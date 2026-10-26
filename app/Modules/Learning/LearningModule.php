<?php
declare(strict_types=1);
namespace App\Modules\Learning;

use App\Modules\Contracts\ModuleInterface;

/**
 * Active as of Phase 2.8 — gated behind the AI_LEARNING feature flag,
 * default off. Activates the previously-unused strategy_statistics/
 * pattern_statistics tables (BacktestController now writes to
 * strategy_statistics; the Learning page can rebuild pattern_statistics
 * from Trade Journal outcomes) and adds confidence calibration: when this
 * flag is on AND SCORE_WEIGHT_CALIBRATION is explicitly set above its
 * default of 0.0, App\Decision\DecisionEngine blends a historical-evidence
 * factor into every live recommendation via App\Models\AiLearningMemory —
 * see App\Config\Score's doc comment for exactly how this stays a no-op
 * until both are explicitly opted into.
 */
final class LearningModule implements ModuleInterface
{
    public function name(): string { return 'learning'; }

    public function routes(): array
    {
        return ['/learning', '/learning/rebuild-calibration', '/learning/rebuild-patterns'];
    }

    public function services(): array
    {
        return [
            \App\Models\AiProviderLog::class, \App\Models\AiDecision::class,
            \App\Models\StrategyStatistic::class, \App\Models\PatternStatistic::class,
            \App\Models\AiLearningMemory::class,
            \App\Controllers\LearningController::class,
            \App\Learning\LearningEngine::class,
            \App\Learning\ConfidenceCalibrationService::class,
            \App\Learning\StrategyLearningService::class,
            \App\Learning\PatternLearningService::class,
            \App\Learning\RecommendationHistoryService::class,
            \App\Learning\SetupPatternCatalog::class,
        ];
    }

    public function config(): array { return ['FEATURE_AI_LEARNING', 'SCORE_WEIGHT_CALIBRATION']; }

    public function events(): array { return []; }

    public function permissions(): array { return ['authenticated']; }
}
