<?php
declare(strict_types=1);
namespace App\MarketData\Providers;

use App\MarketData\Contracts\MarketDataProviderInterface;
use App\MarketData\Exceptions\MarketDataException;
use App\MarketData\Manager\TimeframeCatalog;
use App\MarketData\Models\Candle;
use App\MarketData\Models\Quote;
use Throwable;

/**
 * Read-through cache with a capability the pre-existing
 * App\Services\MarketData\CachedMarketDataProvider never had: when the
 * wrapped inner provider throws, this serves the last successfully cached
 * data regardless of TTL rather than propagating the exception — the
 * "cached data as final fallback" tier from the Phase 2.2A brief.
 *
 * Cache keys are provider-agnostic ({symbol}_{timeframe}, no provider name
 * in the key) — deliberately, so if Deriv wrote the last successful candle
 * set and BOTH Deriv and Yahoo are later unreachable, a CachedMarketProvider
 * wrapping either one can still serve that last-known-good data. Data
 * served purely from a fresh (within-TTL) cache hit keeps its original
 * provider tag (e.g. 'yahoo_finance') since nothing failed; data served as
 * a stale-on-failure rescue is retagged 'cache' so
 * App\MarketData\Manager\MarketDataManager's logging can tell the two
 * apart honestly.
 */
final class CachedMarketProvider implements MarketDataProviderInterface
{
    private bool $lastCallWasCacheFallback = false;

    public function __construct(
        private MarketDataProviderInterface $inner,
        private string $cacheDir = '',
    ) {
        if ($this->cacheDir === '') {
            $this->cacheDir = base_path('storage/cache/market_data_v2');
        }
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function wasLastCallFromCache(): bool
    {
        return $this->lastCallWasCacheFallback;
    }

    /** @return array<int, Candle> */
    public function getCandles(string $symbol, string $timeframe): array
    {
        $this->lastCallWasCacheFallback = false;
        $key = $this->keyFor('candles', $symbol, $timeframe);
        $ttl = $this->candleTtlFor($timeframe);

        $cached = $this->read($key);
        if ($cached !== null && (time() - $cached['fetched_at']) <= $ttl) {
            return array_map([self::class, 'candleFromArray'], $cached['data']);
        }

        try {
            $candles = $this->inner->getCandles($symbol, $timeframe);
            $this->write($key, array_map(static fn(Candle $c): array => $c->toArray(), $candles));
            return $candles;
        } catch (MarketDataException | Throwable $e) {
            if ($cached !== null) {
                $this->lastCallWasCacheFallback = true;
                return array_map(
                    static fn(array $row): Candle => self::candleFromArray($row, 'cache'),
                    $cached['data']
                );
            }
            throw $e instanceof MarketDataException ? $e : new MarketDataException($e->getMessage(), 0, $e);
        }
    }

    public function getQuote(string $symbol): Quote
    {
        $this->lastCallWasCacheFallback = false;
        $key = $this->keyFor('quote', $symbol, 'latest');
        $ttl = max(1, (int) env('MARKET_DATA_QUOTE_CACHE_SECONDS', 45));

        $cached = $this->read($key);
        if ($cached !== null && (time() - $cached['fetched_at']) <= $ttl) {
            return self::quoteFromArray($cached['data'][0]);
        }

        try {
            $quote = $this->inner->getQuote($symbol);
            $this->write($key, [$quote->toArray()]);
            return $quote;
        } catch (MarketDataException | Throwable $e) {
            if ($cached !== null) {
                $this->lastCallWasCacheFallback = true;
                return self::quoteFromArray($cached['data'][0], 'cache');
            }
            throw $e instanceof MarketDataException ? $e : new MarketDataException($e->getMessage(), 0, $e);
        }
    }

    public function getMarketStatus(string $symbol): array
    {
        // Cheap and time-sensitive (open/closed) — not worth caching/stale-serving.
        return $this->inner->getMarketStatus($symbol);
    }

    public function supportsTimeframe(string $timeframe): bool
    {
        return $this->inner->supportsTimeframe($timeframe);
    }

    private function candleTtlFor(string $timeframe): int
    {
        $spec = TimeframeCatalog::has($timeframe) ? TimeframeCatalog::get($timeframe) : null;
        return match (true) {
            $spec !== null && \in_array($timeframe, ['Monthly', 'Weekly'], true) => max(1, (int) env('MARKET_DATA_CACHE_SECONDS_LONG', 86400)),
            $timeframe === 'Daily' => max(1, (int) env('MARKET_DATA_CACHE_SECONDS_DAILY', 21600)),
            default => max(1, (int) env('MARKET_DATA_CACHE_SECONDS', 120)),
        };
    }

    private static function candleFromArray(array $row, ?string $providerOverride = null): Candle
    {
        return new Candle(
            symbol: (string) $row['symbol'],
            timeframe: (string) $row['timeframe'],
            timestamp: (int) $row['timestamp'],
            open: (float) $row['open'],
            high: (float) $row['high'],
            low: (float) $row['low'],
            close: (float) $row['close'],
            volume: (float) $row['volume'],
            provider: $providerOverride ?? (string) $row['provider'],
        );
    }

    private static function quoteFromArray(array $row, ?string $providerOverride = null): Quote
    {
        return new Quote(
            symbol: (string) $row['symbol'],
            bid: (float) $row['bid'],
            ask: (float) $row['ask'],
            timestamp: (int) $row['timestamp'],
            provider: $providerOverride ?? (string) $row['provider'],
            spreadIsReal: (bool) ($row['spread_is_real'] ?? false),
        );
    }

    private function keyFor(string $kind, string $symbol, string $timeframe): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', "{$kind}_{$symbol}_{$timeframe}");
        return $this->cacheDir . DIRECTORY_SEPARATOR . $safe . '.json';
    }

    private function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!\is_array($decoded) || !isset($decoded['fetched_at'], $decoded['data'])) {
            return null;
        }
        return $decoded;
    }

    private function write(string $path, array $data): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
        $payload = json_encode(['fetched_at' => time(), 'data' => $data], JSON_UNESCAPED_UNICODE);
        if ($payload !== false) {
            @file_put_contents($path, $payload, LOCK_EX);
        }
    }
}
