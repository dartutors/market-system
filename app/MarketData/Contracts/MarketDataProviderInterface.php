<?php
declare(strict_types=1);
namespace App\MarketData\Contracts;

use App\MarketData\Models\Candle;
use App\MarketData\Models\Quote;

/**
 * The Phase 2.2A provider contract — richer than the pre-existing
 * App\Contracts\MarketDataProviderInterface (which only has
 * fetchCandles(): array of raw rows, and is left untouched for the
 * consumers still using it directly). Every App\MarketData\Providers\*
 * class implements this one; App\MarketData\Manager\MarketDataManager is
 * the only thing application code should depend on.
 *
 * @see \App\Contracts\MarketDataProviderInterface The older, still-active raw-array contract.
 */
interface MarketDataProviderInterface
{
    /** Short identifier used in logs/reports — e.g. 'deriv', 'yahoo_finance', 'cache'. */
    public function name(): string;

    /**
     * @param string $timeframe One of App\MarketData\Manager\TimeframeCatalog's labels
     *        (Monthly, Weekly, Daily, H4, H1, M30, M15, M5, M1).
     * @return array<int, Candle> Oldest first.
     * @throws \App\MarketData\Exceptions\MarketDataException
     */
    public function getCandles(string $symbol, string $timeframe): array;

    /** @throws \App\MarketData\Exceptions\MarketDataException */
    public function getQuote(string $symbol): Quote;

    /**
     * @return array{symbol:string, is_open:bool, provider:string}
     * @throws \App\MarketData\Exceptions\MarketDataException
     */
    public function getMarketStatus(string $symbol): array;

    /** Whether this provider can natively serve the given timeframe (see TimeframeCatalog doc comment on Deriv's granularity limits). */
    public function supportsTimeframe(string $timeframe): bool;
}
