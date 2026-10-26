<?php
declare(strict_types=1);
namespace App\Modules\MultiTimeframe;

use App\Modules\Contracts\ModuleInterface;

/**
 * Active as of Phase 2.3 — gated behind the MULTI_TIMEFRAME feature flag
 * (default off). On-demand only, not wired into the automatic cron scan.
 * Sprint A: trend alignment, conflict detection, confidence scoring.
 * Sprint B: pullback identification, entry confirmation, AI multi-timeframe
 * summary, and the composed recommendation (MultiTimeframeDecisionEngine).
 */
final class MultiTimeframeModule implements ModuleInterface
{
    public function name(): string { return 'multi_timeframe'; }

    public function routes(): array { return ['/multi-timeframe', '/multi-timeframe/analyze']; }

    public function services(): array
    {
        return [
            \App\Controllers\MultiTimeframeController::class,
            \App\MultiTimeframe\MultiTimeframeDataFetcher::class,
            \App\MultiTimeframe\TrendAlignmentEngine::class,
            \App\MultiTimeframe\PullbackDetector::class,
            \App\MultiTimeframe\EntryConfirmationEngine::class,
            \App\MultiTimeframe\MultiTimeframeAiSummaryService::class,
            \App\MultiTimeframe\MultiTimeframeDecisionEngine::class,
            \App\MultiTimeframe\TimeframeRegistry::class,
            \App\Models\MultiTimeframeAnalysis::class,
        ];
    }

    public function config(): array { return ['TIMEFRAME_WEIGHT_*', 'MARKET_DATA_CACHE_SECONDS_LONG', 'MARKET_DATA_CACHE_SECONDS_DAILY', 'FEATURE_MULTI_TIMEFRAME']; }

    public function events(): array { return []; }

    public function permissions(): array { return ['authenticated']; }
}
