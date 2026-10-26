<?php
declare(strict_types=1);
namespace App\Modules\Vision;

use App\Modules\Contracts\ModuleInterface;

/**
 * Active as of Phase 2.2 Sprint A — gated behind the AI_VISION feature flag
 * (default off). As of the Vision+Risk integration, a BUY/SELL recommendation
 * is additionally sized through the Risk Management Suite (position sizing,
 * stop/take-profit/risk-reward, daily/weekly/drawdown/exposure guardrails)
 * when RISK_MANAGEMENT is also enabled — see VisionController::buildRiskGuidance().
 * That dependency is one-directional: RiskManagementModule does not depend on this one.
 */
final class VisionModule implements ModuleInterface
{
    public function name(): string { return 'vision'; }

    public function routes(): array { return ['/vision', '/vision/analyze', '/vision/image']; }

    public function services(): array
    {
        return [
            \App\Controllers\VisionController::class,
            \App\Image\UploadService::class, \App\Image\ImageValidator::class,
            \App\Image\ImageProcessor::class, \App\Image\ImageStorage::class, \App\Image\VisionManager::class,
            \App\Models\UploadedChart::class, \App\Models\ChartAnalysis::class, \App\Models\VisionSession::class,
            \App\Vision\VisionTimeframeCatalog::class, \App\Vision\TopDownAnalysisEngine::class,
            \App\Vision\CompletenessScorer::class, \App\Vision\VisionDecisionEngine::class,
            \App\Services\AiVisionSynthesisService::class,
            \App\Risk\RiskAssessmentEngine::class, \App\Risk\LiveAtrLookup::class, \App\Risk\LevelPicker::class,
        ];
    }

    public function config(): array { return ['CHART_IMAGE_MAX_MB', 'VISION_MAX_TOKENS', 'FEATURE_AI_VISION']; }

    public function events(): array { return []; }

    public function permissions(): array { return ['authenticated']; }
}
