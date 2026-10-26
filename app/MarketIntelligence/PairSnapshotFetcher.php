<?php
declare(strict_types=1);
namespace App\MarketIntelligence;

use App\Config\MarketIntelligence as MiConfig;
use App\Contracts\MarketDataProviderInterface;
use App\Domain\TrendClassifier;
use App\Indicators\ADX;
use App\Indicators\ATR;
use App\Indicators\IndicatorRegistry;
use App\Indicators\RSI;
use App\Indicators\SMA;
use App\MarketData\Manager\MarketDataManager;
use App\Services\IndicatorService;
use RuntimeException;
use Throwable;

/**
 * Fetches and enriches one Daily-timeframe candle series per configured
 * market — the single shared dataset every other Market Intelligence
 * calculator (currency strength, volatility, correlation, strength ranking)
 * reads from, so the same data is only fetched/enriched once per request.
 * Same provider stack as App\MultiTimeframe\MultiTimeframeDataFetcher (Phase
 * 2.2A: App\MarketData\Manager\MarketDataManager, Deriv -> Yahoo Finance ->
 * cache), with ADX added to the registry (not part of
 * IndicatorRegistry::legacyDefaults()) for the strength-ranking magnitude.
 */
final class PairSnapshotFetcher
{
    private const MIN_BARS = 55;
    private const CLOSE_SERIES_LENGTH = 90;

    private MarketDataProviderInterface $provider;
    private IndicatorService $indicators;

    public function __construct(?MarketDataProviderInterface $provider = null)
    {
        $this->provider = $provider ?? new MarketDataManager();

        $registry = new IndicatorRegistry();
        $registry->register(new SMA(20, 'sma_20'));
        $registry->register(new SMA(50, 'sma_50'));
        $registry->register(new RSI(14, 'rsi_14'));
        $registry->register(new ATR(14, 'atr_14'));
        $registry->register(new ADX(14));

        $this->indicators = new IndicatorService($registry);
    }

    /**
     * @return array<string, PairSnapshot> Keyed by market label; a market
     *         that fails to fetch is skipped (logged), not fatal.
     */
    public function fetchAll(): array
    {
        $snapshots = [];

        foreach (configured_markets() as $market) {
            try {
                $snapshots[$market['label']] = $this->fetchOne($market['symbol'], $market['label']);
            } catch (Throwable $e) {
                error_log("[PairSnapshotFetcher] {$market['label']} fetch failed: " . $e->getMessage());
            }
        }

        return $snapshots;
    }

    /**
     * Public since Phase "Live Market Overview" — the dashboard's wider
     * instrument table (metals/indices/crypto, none of which are in
     * configured_markets()) needs to fetch a snapshot for an arbitrary
     * symbol/label pair, not just the configured set fetchAll() iterates.
     */
    public function fetchOne(string $symbol, string $label): PairSnapshot
    {
        $rows = $this->provider->fetchCandles($symbol, '1d', '2y');
        if (count($rows) < self::MIN_BARS) {
            throw new RuntimeException("Insufficient daily candle data for {$label}: only " . count($rows) . ' bars.');
        }

        $enriched = $this->indicators->enrich($rows);
        if (empty($enriched)) {
            throw new RuntimeException("Indicator enrichment produced no usable daily bars for {$label}.");
        }

        $count       = count($enriched);
        $latest      = $enriched[$count - 1];
        $previous    = $enriched[$count - 2] ?? $latest;
        $lookback    = MiConfig::returnLookbackDays();
        $periodStart = $enriched[max(0, $count - 1 - $lookback)];

        $close       = (float) $latest['close'];
        $prevClose   = (float) $previous['close'];
        $periodClose = (float) $periodStart['close'];
        $atr14       = (float) $latest['atr_14'];

        $closeSeries = array_map(
            static fn(array $row): float => (float) $row['close'],
            array_slice($enriched, -self::CLOSE_SERIES_LENGTH)
        );

        return new PairSnapshot(
            label: $label,
            symbol: $symbol,
            close: $close,
            dailyReturnPct: $prevClose > 0.0 ? round(($close - $prevClose) / $prevClose * 100, 4) : 0.0,
            periodReturnPct: $periodClose > 0.0 ? round(($close - $periodClose) / $periodClose * 100, 4) : 0.0,
            sma20: (float) $latest['sma_20'],
            sma50: (float) $latest['sma_50'],
            rsi14: (float) $latest['rsi_14'],
            atr14: $atr14,
            atrPercent: $close > 0.0 ? round($atr14 / $close * 100, 4) : 0.0,
            adx14: (float) ($latest['adx_14'] ?? 0.0),
            trend: TrendClassifier::classifyTrend($latest),
            momentum: TrendClassifier::classifyMomentum($latest),
            closeSeries: $closeSeries,
        );
    }
}
