<?php
declare(strict_types=1);
namespace App\Modules\TradeCoach;

use App\Modules\Contracts\ModuleInterface;

/**
 * Active as of Phase 2.4 — gated behind the TRADE_COACH feature flag
 * (default off). On-demand only: explains the latest AiDecision row already
 * produced by the cron scan's DecisionEngine + ExplanationEngine, layering
 * beginner/professional framing, risk explanations, educational tips,
 * common mistakes, and trading psychology guidance on top — it never
 * recomputes the underlying decision.
 */
final class TradeCoachModule implements ModuleInterface
{
    public function name(): string { return 'trade_coach'; }

    public function routes(): array { return ['/trade-coach', '/trade-coach/explain']; }

    public function services(): array
    {
        return [
            \App\Explanation\ExplanationEngine::class,
            \App\Controllers\TradeCoachController::class,
            \App\TradeCoach\TradeCoachEngine::class,
            \App\TradeCoach\TradeCoachAiService::class,
            \App\TradeCoach\CoachContentLibrary::class,
            \App\Models\AiDecision::class,
        ];
    }

    public function config(): array { return ['FEATURE_TRADE_COACH']; }

    public function events(): array { return []; }

    public function permissions(): array { return ['authenticated']; }
}
