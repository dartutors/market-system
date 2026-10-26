<?php
declare(strict_types=1);
namespace App\Services\MarketData;

use App\Contracts\MarketDataProviderInterface;

/**
 * Read-through file cache decorator, mirroring NewsService's existing
 * storage/cache/news_cache.json idiom (LOCK_EX write, @file_get_contents
 * read, TTL from env). Short default TTL (2 minutes) so live signal
 * freshness isn't harmed — this only collapses truly duplicate calls (e.g.
 * a live scan and a manual backtest run close together), not staleness.
 *
 * TTL is interval-aware as of Phase 2.3: Monthly/Weekly candles (`1mo`/`1wk`)
 * don't change within a scan session, so they get a 24h TTL; Daily (`1d`)
 * gets 6h. Every other interval — including the `60m`/`4h` intervals
 * MarketDataService/BacktestService already pass — keeps the exact original
 * MARKET_DATA_CACHE_SECONDS (120s) default, unchanged.
 */
final class CachedMarketDataProvider implements MarketDataProviderInterface
{
    public function __construct(private MarketDataProviderInterface $inner)
    {
    }

    public function fetchCandles(string $symbol, string $timeframe, string $range): array
    {
        $cacheFile = $this->cacheFilePath($symbol, $timeframe, $range);
        $cached    = $this->readCache($cacheFile, $timeframe);

        if ($cached !== null) {
            return $cached;
        }

        $rows = $this->inner->fetchCandles($symbol, $timeframe, $range);
        $this->writeCache($cacheFile, $rows);

        return $rows;
    }

    private function ttlSecondsFor(string $timeframe): int
    {
        return match ($timeframe) {
            '1mo', '1wk' => max(0, (int) env('MARKET_DATA_CACHE_SECONDS_LONG', 86400)),
            '1d'         => max(0, (int) env('MARKET_DATA_CACHE_SECONDS_DAILY', 21600)),
            default      => max(0, (int) env('MARKET_DATA_CACHE_SECONDS', 120)),
        };
    }

    private function cacheFilePath(string $symbol, string $timeframe, string $range): string
    {
        $dir = base_path('storage/cache/market_data');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $key = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', "{$symbol}_{$timeframe}_{$range}");
        return $dir . DIRECTORY_SEPARATOR . $key . '.json';
    }

    private function readCache(string $path, string $timeframe): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!\is_array($data) || !isset($data['fetched_at'], $data['rows'])) {
            return null;
        }

        $ttl = $this->ttlSecondsFor($timeframe);
        if ($ttl === 0 || (time() - (int) $data['fetched_at']) > $ttl) {
            return null;
        }

        if (!\is_array($data['rows'])) {
            return null;
        }

        // JSON has no int/float distinction — a whole-number float like
        // volume=0.0 round-trips back as an int. Re-cast so a cache hit
        // returns exactly the same types fetchCandles() always has.
        return array_map(static fn(array $row): array => [
            'timestamp' => (int)   $row['timestamp'],
            'open'      => (float) $row['open'],
            'high'      => (float) $row['high'],
            'low'       => (float) $row['low'],
            'close'     => (float) $row['close'],
            'volume'    => (float) $row['volume'],
        ], $data['rows']);
    }

    private function writeCache(string $path, array $rows): void
    {
        $payload = json_encode(['fetched_at' => time(), 'rows' => $rows], JSON_UNESCAPED_UNICODE);
        if ($payload !== false) {
            @file_put_contents($path, $payload, LOCK_EX);
        }
    }
}
