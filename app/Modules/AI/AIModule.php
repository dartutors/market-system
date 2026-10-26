<?php
declare(strict_types=1);
namespace App\Modules\AI;

use App\Modules\Contracts\ModuleInterface;

final class AIModule implements ModuleInterface
{
    public function name(): string { return 'ai'; }

    /** No dedicated route — the AI layer is consumed by the Scanner and Backtesting modules. */
    public function routes(): array { return []; }

    public function services(): array
    {
        return [
            \App\AI\AIManager::class, \App\Services\AiSummaryService::class, \App\Services\AiInsightService::class,
            \App\Services\AiBacktestSummaryService::class, \App\Decision\DecisionEngine::class,
            \App\Decision\ScoreEngine::class, \App\Explanation\ExplanationEngine::class,
        ];
    }

    public function config(): array
    {
        return ['AI_PRIMARY_PROVIDER', 'AI_FALLBACK_PROVIDER', 'CLAUDE_API_KEY', 'CLAUDE_MODEL', 'OPENAI_API_KEY', 'OPENAI_MODEL', 'USE_AI_SUMMARY'];
    }

    public function events(): array { return []; }

    public function permissions(): array { return ['authenticated']; }
}
