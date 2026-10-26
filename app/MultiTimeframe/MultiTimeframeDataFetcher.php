<?php
declare(strict_types=1);
namespace App\MultiTimeframe;

use App\Contracts\MarketDataProviderInterface;
use App\Domain\TrendClassifier;
use App\MarketData\Manager\MarketDataManager;
use App\Services\IndicatorService;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Fetches and enriches all 8 registered timeframes for one symbol.
 *
 * Phase 2.2A: defaults to App\MarketData\Manager\MarketDataManager (Deriv ->
 * Yahoo Finance -> cache) — same IndicatorService MarketDataService/
 * BacktestService already use, no new HTTP client, no duplicated indicator
 * math. TimeframeRegistry's interval/range pairs below match
 * App\MarketData\Manager\TimeframeCatalog's entries exactly, so all 8
 * timeframes get Deriv acceleration when it's configured.
 */
final class MultiTimeframeDataFetcher
{
    private const MIN_BARS = 55;

    private MarketDataProviderInterface $provider;
    private IndicatorService $indicators;

    public function __construct(?MarketDataProviderInterface $provider = null, ?IndicatorService $indicators = null)
    {
        $this->provider   = $provider ?? new MarketDataManager();
        $this->indicators = $indicators ?? new IndicatorService();
    }

    /**
     * @return array<string, TimeframeAnalysis> Keyed by label; a timeframe
     *         that fails to fetch is skipped (logged), not fatal — the
     *         alignment engine works fine with a partial set.
     */
    public function fetchAll(string $symbol): array
    {
        $results = [];

        foreach (TimeframeRegistry::all() as $label => $spec) {
            try {
                $results[$label] = $this->fetchOne($symbol, $label, $spec['interval'], $spec['range']);
            } catch (Throwable $e) {
                error_log("[MultiTimeframeDataFetcher] {$label} fetch failed for {$symbol}: " . $e->getMessage());
            }
        }

        return $results;
    }

    private function fetchOne(string $symbol, string $label, string $interval, string $range): TimeframeAnalysis
    {
        $rows = $this->provider->fetchCandles($symbol, $interval, $range);
        if (count($rows) < self::MIN_BARS) {
            throw new RuntimeException(
                "Insufficient {$label} candle data for {$symbol}: only " . count($rows) . ' bars (need ' . self::MIN_BARS . '+).'
            );
        }

        $enriched = $this->indicators->enrich($rows);
        if (empty($enriched)) {
            throw new RuntimeException("Indicator enrichment produced no usable {$label} bars for {$symbol}.");
        }

        $latest = $enriched[count($enriched) - 1];

        return new TimeframeAnalysis(
            label: $label,
            interval: $interval,
            range: $range,
            trend: TrendClassifier::classifyTrend($latest),
            momentum: TrendClassifier::classifyMomentum($latest),
            close: (float) $latest['close'],
            sma20: (float) $latest['sma_20'],
            sma50: (float) $latest['sma_50'],
            rsi14: (float) $latest['rsi_14'],
            macdHist: (float) $latest['macd_hist'],
            atr14: (float) $latest['atr_14'],
            barsUsed: count($enriched),
            fetchedAt: new DateTimeImmutable('now'),
        );
    }
}
