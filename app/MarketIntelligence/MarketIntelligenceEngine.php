<?php
declare(strict_types=1);
namespace App\MarketIntelligence;

use DateTimeImmutable;

/**
 * Phase 2.7 deliverable: a richer overview of market conditions before
 * drilling into individual instruments. Fetches each configured pair's
 * Daily snapshot once (PairSnapshotFetcher) and derives currency strength,
 * volatility, correlation, and strength/weakness ranking from that single
 * shared dataset; session activity, sentiment, and news impact are
 * independent reads (they don't depend on the Daily OHLCV fetch).
 */
final class MarketIntelligenceEngine
{
    public function __construct(
        private PairSnapshotFetcher $snapshotFetcher = new PairSnapshotFetcher(),
        private SessionActivityAnalyzer $sessionAnalyzer = new SessionActivityAnalyzer(),
        private SentimentIndexService $sentimentService = new SentimentIndexService(),
        private NewsImpactScorer $newsImpactScorer = new NewsImpactScorer(),
    ) {
    }

    public function build(): MarketIntelligenceSnapshot
    {
        $snapshots = $this->snapshotFetcher->fetchAll();

        if (empty($snapshots)) {
            throw new \RuntimeException('No market data could be fetched right now. Try again shortly.');
        }

        return new MarketIntelligenceSnapshot(
            pairSnapshots: $snapshots,
            currencyStrength: CurrencyStrengthCalculator::calculate($snapshots),
            volatilityIndex: VolatilityIndexCalculator::calculate($snapshots),
            sessionActivity: $this->sessionAnalyzer->analyze(),
            sentimentIndex: $this->sentimentService->calculate(),
            newsImpact: $this->newsImpactScorer->calculate(),
            correlationMatrix: CorrelationMatrixCalculator::calculate($snapshots),
            strengthRanking: PairStrengthRanker::rank($snapshots),
            generatedAt: new DateTimeImmutable('now'),
        );
    }

    /**
     * build() fetches Daily/2y candles for every configured pair
     * sequentially with no cache of its own — measured live at ~10s on a
     * cold underlying candle cache vs ~0.4s once warm. Both the dashboard's
     * sentiment panel and the dedicated /market-intelligence page called
     * build() directly and independently, so on a cold cache two different
     * pages could each separately pay that cost within moments of each
     * other, and (more subtly) could show slightly different currency-
     * strength numbers if a fetch landed between the two calls. Sharing one
     * short-lived file cache here fixes both: at most one computation per
     * $ttlSeconds, one shared answer everywhere it's shown.
     */
    public function buildCached(int $ttlSeconds = 300): MarketIntelligenceSnapshot
    {
        $cachePath = base_path('storage/cache/market_intelligence_snapshot.cache');

        if (is_file($cachePath) && (time() - (int) filemtime($cachePath)) <= $ttlSeconds) {
            $raw = @file_get_contents($cachePath);
            if ($raw !== false) {
                $snap = @unserialize($raw, ['allowed_classes' => [MarketIntelligenceSnapshot::class, PairSnapshot::class, DateTimeImmutable::class]]);
                if ($snap instanceof MarketIntelligenceSnapshot) {
                    return $snap;
                }
            }
        }

        $snap = $this->build();

        $written = @file_put_contents($cachePath, serialize($snap));
        if ($written === false) {
            error_log("[MarketIntelligenceEngine] failed to write snapshot cache: {$cachePath}");
        }

        return $snap;
    }
}
