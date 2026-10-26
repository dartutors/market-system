<?php
declare(strict_types=1);
namespace App\MarketData\Providers;

use App\MarketData\Contracts\MarketDataProviderInterface;
use App\MarketData\Exceptions\ProviderUnavailableException;
use App\MarketData\Manager\TimeframeCatalog;
use App\MarketData\Models\Candle;
use App\MarketData\Models\Quote;
use App\Services\MarketData\YahooFinanceProvider as LegacyYahooFinanceProvider;
use Throwable;

/**
 * Adapter, not a rewrite: composes the existing, already-working
 * App\Services\MarketData\YahooFinanceProvider (Guzzle HTTP calls, response
 * parsing — unchanged) and normalizes its raw row shape into Candle/Quote
 * objects. Yahoo's chart API has no real bid/ask spread for retail forex
 * data, so getQuote() sets bid = ask = latest close (spreadIsReal: false) —
 * an honest approximation, not a real quote feed.
 */
final class YahooFinanceProvider implements MarketDataProviderInterface
{
    public function __construct(private LegacyYahooFinanceProvider $legacy = new LegacyYahooFinanceProvider())
    {
    }

    public function name(): string
    {
        return 'yahoo_finance';
    }

    public function getCandles(string $symbol, string $timeframe): array
    {
        $spec = TimeframeCatalog::get($timeframe);

        try {
            $rows = $this->legacy->fetchCandles($symbol, $spec['interval'], $spec['range']);
        } catch (Throwable $e) {
            throw new ProviderUnavailableException('Yahoo Finance: ' . $e->getMessage(), 0, $e);
        }

        return array_map(
            fn(array $row): Candle => new Candle(
                symbol: $symbol,
                timeframe: $timeframe,
                timestamp: (int) $row['timestamp'],
                open: (float) $row['open'],
                high: (float) $row['high'],
                low: (float) $row['low'],
                close: (float) $row['close'],
                volume: (float) $row['volume'],
                provider: $this->name(),
            ),
            $rows
        );
    }

    public function getQuote(string $symbol): Quote
    {
        // Daily is always supported (both by Yahoo and this catalog) and cheap
        // to cache — the freshest usable "last price" proxy without a real
        // tick feed.
        $candles = $this->getCandles($symbol, 'Daily');
        $latest  = end($candles);

        if ($latest === false) {
            throw new ProviderUnavailableException("Yahoo Finance: no candles available to derive a quote for {$symbol}.");
        }

        return new Quote(
            symbol: $symbol,
            bid: $latest->close,
            ask: $latest->close,
            timestamp: $latest->timestamp,
            provider: $this->name(),
            spreadIsReal: false,
        );
    }

    public function getMarketStatus(string $symbol): array
    {
        // Yahoo's chart API carries no explicit market-status flag for forex;
        // approximate with the standard FX trading week (closed Sat/Sun EAT).
        $dayOfWeek = (int) now_eat()->format('N');

        return [
            'symbol'   => $symbol,
            'is_open'  => $dayOfWeek < 6,
            'provider' => $this->name(),
        ];
    }

    public function supportsTimeframe(string $timeframe): bool
    {
        return TimeframeCatalog::has($timeframe);
    }
}
