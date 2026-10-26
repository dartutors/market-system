<?php
declare(strict_types=1);
namespace App\Modules\MarketIntelligence;

use App\Modules\Contracts\ModuleInterface;

/**
 * Active as of Phase 2.7 — gated behind the (previously reserved but
 * unclaimed) ADVANCED_ANALYTICS feature flag, default off — same
 * "activate a reserved-but-unused flag" precedent as TradeJournalModule
 * used for TRADE_JOURNAL in Phase 2.6. On-demand only, no new tables: every
 * metric is computed fresh per request from live candle data, the existing
 * ai_summaries/news_items tables, and App\Models\SignalEvent-adjacent
 * currency-pair math already built for Risk Management (Phase 2.5).
 */
final class MarketIntelligenceModule implements ModuleInterface
{
    public function name(): string { return 'market_intelligence'; }

    public function routes(): array { return ['/market-intelligence']; }

    public function services(): array
    {
        return [
            \App\Controllers\MarketIntelligenceController::class,
            \App\MarketIntelligence\MarketIntelligenceEngine::class,
            \App\MarketIntelligence\PairSnapshotFetcher::class,
            \App\MarketIntelligence\CurrencyStrengthCalculator::class,
            \App\MarketIntelligence\VolatilityIndexCalculator::class,
            \App\MarketIntelligence\PairStrengthRanker::class,
            \App\MarketIntelligence\CorrelationMatrixCalculator::class,
            \App\MarketIntelligence\SessionActivityAnalyzer::class,
            \App\MarketIntelligence\SentimentIndexService::class,
            \App\MarketIntelligence\NewsImpactScorer::class,
        ];
    }

    public function config(): array { return ['FEATURE_ADVANCED_ANALYTICS']; }

    public function events(): array { return []; }

    public function permissions(): array { return ['authenticated']; }
}
