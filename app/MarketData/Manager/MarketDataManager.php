<?php
declare(strict_types=1);
namespace App\MarketData\Manager;

use App\Contracts\MarketDataProviderInterface as LegacyMarketDataProviderInterface;
use App\MarketData\Contracts\MarketDataProviderInterface;
use App\MarketData\Exceptions\MarketDataException;
use App\MarketData\Exceptions\ProviderUnavailableException;
use App\MarketData\Models\Candle;
use App\MarketData\Models\MarketSnapshot;
use App\MarketData\Models\Quote;
use App\MarketData\Providers\CachedMarketProvider;
use App\MarketData\Providers\DerivProvider;
use App\MarketData\Providers\YahooFinanceProvider;
use App\Models\MarketDataProviderLog;
use App\Services\MarketData\CachedMarketDataProvider as LegacyCachedMarketDataProvider;
use App\Services\MarketData\YahooFinanceProvider as LegacyYahooFinanceProvider;
use Throwable;

/**
 * The one thing application code should depend on for market data —
 * "Priority order: Deriv -> Yahoo Finance -> cached data" from the
 * Phase 2.2A brief. Each tier is itself cache-wrapped
 * (App\MarketData\Providers\CachedMarketProvider), so "cached data as
 * final fallback" isn't a separate third object in a literal chain — it's
 * each provider serving its own last-known-good data when its live call
 * fails (see that class's doc comment for why this is actually more
 * resilient than a naive 3-step chain: a slightly-stale Deriv cache is
 * preferred over an unrelated Yahoo call, and cache keys are
 * provider-agnostic so either provider's last successful write can rescue
 * the other's failure).
 *
 * Implements BOTH the new, richer App\MarketData\Contracts\
 * MarketDataProviderInterface (the future) and the pre-existing
 * App\Contracts\MarketDataProviderInterface (fetchCandles(): raw array
 * rows — unchanged shape) so every one of the six existing direct
 * Yahoo/CachedMarketDataProvider consumers can switch to
 * `new MarketDataManager()` as a pure drop-in replacement, zero other code
 * changes, zero behavior change when Deriv isn't configured (DERIV_APP_ID
 * empty — see App\MarketData\Providers\DerivProvider) or its timeframe
 * doesn't match any canonical TimeframeCatalog entry: the legacy path falls
 * straight through to the exact same
 * App\Services\MarketData\CachedMarketDataProvider(YahooFinanceProvider)
 * stack every consumer already used before this refactor.
 */
final class MarketDataManager implements MarketDataProviderInterface, LegacyMarketDataProviderInterface
{
    private CachedMarketProvider $deriv;
    private CachedMarketProvider $yahoo;
    private LegacyCachedMarketDataProvider $legacyYahoo;

    /**
     * $deriv/$yahoo are typed against the interface, not the concrete
     * (final) provider classes, so tests can inject a fake implementation —
     * standard DI practice, and consistent with how e.g.
     * App\Services\BacktestService already accepts
     * ?App\Contracts\MarketDataProviderInterface rather than a concrete class.
     */
    public function __construct(
        ?MarketDataProviderInterface $deriv = null,
        ?MarketDataProviderInterface $yahoo = null,
        ?LegacyCachedMarketDataProvider $legacyYahoo = null,
    ) {
        $this->deriv       = new CachedMarketProvider($deriv ?? new DerivProvider());
        $this->yahoo       = new CachedMarketProvider($yahoo ?? new YahooFinanceProvider());
        $this->legacyYahoo = $legacyYahoo ?? new LegacyCachedMarketDataProvider(new LegacyYahooFinanceProvider());
    }

    public function name(): string
    {
        return 'market_data_manager';
    }

    // ── New normalized API ──────────────────────────────────────────────────

    /** @return array<int, Candle> */
    public function getCandles(string $symbol, string $timeframe): array
    {
        $start = microtime(true);

        if ($this->derivIsPrimary() && $this->deriv->supportsTimeframe($timeframe)) {
            try {
                $candles = $this->deriv->getCandles($symbol, $timeframe);
                $this->log('candles', $symbol, $timeframe, $this->resolvedProviderName($this->deriv), false, $this->deriv->wasLastCallFromCache(), true, $start, null);
                return $candles;
            } catch (Throwable $e) {
                $this->log('candles', $symbol, $timeframe, 'deriv', false, false, false, $start, $e->getMessage());
                error_log("[MarketDataManager] Deriv candles failed for {$symbol} ({$timeframe}), falling back to Yahoo Finance: " . $e->getMessage());
            }
        }

        $start = microtime(true);
        try {
            $candles = $this->yahoo->getCandles($symbol, $timeframe);
            $this->log('candles', $symbol, $timeframe, $this->resolvedProviderName($this->yahoo), $this->derivIsPrimary(), $this->yahoo->wasLastCallFromCache(), true, $start, null);
            return $candles;
        } catch (Throwable $e) {
            $this->log('candles', $symbol, $timeframe, 'yahoo_finance', $this->derivIsPrimary(), false, false, $start, $e->getMessage());
            throw new ProviderUnavailableException(
                "All market data providers failed for {$symbol} ({$timeframe}): " . $e->getMessage(), 0, $e
            );
        }
    }

    public function getQuote(string $symbol): Quote
    {
        $start = microtime(true);

        if ($this->derivIsPrimary()) {
            try {
                $quote = $this->deriv->getQuote($symbol);
                $this->log('quote', $symbol, null, $this->resolvedProviderName($this->deriv), false, $this->deriv->wasLastCallFromCache(), true, $start, null);
                return $quote;
            } catch (Throwable $e) {
                $this->log('quote', $symbol, null, 'deriv', false, false, false, $start, $e->getMessage());
                error_log("[MarketDataManager] Deriv quote failed for {$symbol}, falling back to Yahoo Finance: " . $e->getMessage());
            }
        }

        $start = microtime(true);
        try {
            $quote = $this->yahoo->getQuote($symbol);
            $this->log('quote', $symbol, null, $this->resolvedProviderName($this->yahoo), $this->derivIsPrimary(), $this->yahoo->wasLastCallFromCache(), true, $start, null);
            return $quote;
        } catch (Throwable $e) {
            $this->log('quote', $symbol, null, 'yahoo_finance', $this->derivIsPrimary(), false, false, $start, $e->getMessage());
            throw new ProviderUnavailableException("All market data providers failed for a quote on {$symbol}: " . $e->getMessage(), 0, $e);
        }
    }

    public function getMarketStatus(string $symbol): array
    {
        if ($this->derivIsPrimary()) {
            try {
                return $this->deriv->getMarketStatus($symbol);
            } catch (Throwable $e) {
                error_log("[MarketDataManager] Deriv market status failed for {$symbol}, falling back to Yahoo Finance: " . $e->getMessage());
            }
        }

        return $this->yahoo->getMarketStatus($symbol);
    }

    public function supportsTimeframe(string $timeframe): bool
    {
        return TimeframeCatalog::has($timeframe);
    }

    /** Convenience: the fully normalized snapshot (Candle + Quote merged), per the Phase 2.2A brief's "every module should consume this object." */
    public function getSnapshot(string $symbol, string $timeframe): MarketSnapshot
    {
        $candles = $this->getCandles($symbol, $timeframe);
        $latest  = end($candles);
        if ($latest === false) {
            throw new ProviderUnavailableException("No candle data available to build a snapshot for {$symbol} ({$timeframe}).");
        }

        $quote = null;
        try {
            $quote = $this->getQuote($symbol);
        } catch (MarketDataException) {
            // A snapshot from candles alone (bid = ask = close) is still useful; don't fail the whole snapshot over a quote miss.
        }

        return MarketSnapshot::fromCandleAndQuote($latest, $quote);
    }

    // ── Legacy compatibility: App\Contracts\MarketDataProviderInterface ────

    /**
     * Drop-in replacement for every existing
     * `new CachedMarketDataProvider(new YahooFinanceProvider())` call site.
     * $timeframe/$range here are the OLD raw Yahoo-style params (e.g.
     * '60m'/'60d'), not a TimeframeCatalog label — Deriv is only attempted
     * when they happen to exactly match a canonical catalog entry; the
     * guaranteed fallback is the exact same pre-existing Yahoo+file-cache
     * stack every consumer already used, so behavior is byte-identical to
     * before whenever Deriv isn't configured or doesn't match.
     *
     * @return array<int, array{timestamp:int, open:float, high:float, low:float, close:float, volume:float}>
     */
    public function fetchCandles(string $symbol, string $timeframe, string $range): array
    {
        $start = microtime(true);
        $label = $this->findCatalogLabel($timeframe, $range);

        if ($label !== null && $this->derivIsPrimary() && $this->deriv->supportsTimeframe($label)) {
            try {
                $candles = $this->deriv->getCandles($symbol, $label);
                $this->log('candles', $symbol, $label, $this->resolvedProviderName($this->deriv), false, $this->deriv->wasLastCallFromCache(), true, $start, null);
                return array_map(static fn(Candle $c): array => $c->toLegacyRow(), $candles);
            } catch (Throwable $e) {
                $this->log('candles', $symbol, $label, 'deriv', false, false, false, $start, $e->getMessage());
                error_log("[MarketDataManager] Deriv legacy candles failed for {$symbol} ({$timeframe}/{$range}), falling back to Yahoo Finance: " . $e->getMessage());
            }
        }

        $start = microtime(true);
        try {
            $rows = $this->legacyYahoo->fetchCandles($symbol, $timeframe, $range);
            $this->log('candles', $symbol, $label ?? "{$timeframe}/{$range}", 'yahoo_finance', $label !== null && $this->derivIsPrimary(), false, true, $start, null);
            return $rows;
        } catch (Throwable $e) {
            $this->log('candles', $symbol, $label ?? "{$timeframe}/{$range}", 'yahoo_finance', $label !== null && $this->derivIsPrimary(), false, false, $start, $e->getMessage());
            throw $e;
        }
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function derivIsPrimary(): bool
    {
        return strtolower(trim((string) env('MARKET_DATA_PRIMARY_PROVIDER', 'deriv'))) === 'deriv'
            && trim((string) env('DERIV_APP_ID', '')) !== '';
    }

    private function resolvedProviderName(CachedMarketProvider $provider): string
    {
        return $provider->wasLastCallFromCache() ? 'cache' : $provider->name();
    }

    private function findCatalogLabel(string $interval, string $range): ?string
    {
        foreach (TimeframeCatalog::all() as $label => $spec) {
            if ($spec['interval'] === $interval && $spec['range'] === $range) {
                return $label;
            }
        }
        return null;
    }

    private function log(
        string $operation,
        string $symbol,
        ?string $timeframe,
        string $providerUsed,
        bool $wasFallback,
        bool $wasCache,
        bool $success,
        float $startMicrotime,
        ?string $errorMessage,
    ): void {
        try {
            MarketDataProviderLog::create([
                'operation'        => $operation,
                'symbol'           => $symbol,
                'timeframe'        => $timeframe,
                'provider_used'    => $providerUsed,
                'primary_provider' => $this->derivIsPrimary() ? 'deriv' : 'yahoo_finance',
                'was_fallback'     => $wasFallback ? 1 : 0,
                'was_cache'        => $wasCache ? 1 : 0,
                'success'          => $success ? 1 : 0,
                'latency_ms'       => (int) round((microtime(true) - $startMicrotime) * 1000),
                'error_message'    => $errorMessage,
            ]);
        } catch (Throwable $e) {
            error_log('[MarketDataManager] Provider log write failed: ' . $e->getMessage());
        }
    }
}
