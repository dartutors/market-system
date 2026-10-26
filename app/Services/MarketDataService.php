<?php
declare(strict_types=1);
namespace App\Services;

use App\Contracts\MarketDataProviderInterface;
use App\MarketData\Manager\MarketDataManager;
use RuntimeException;

/**
 * Thin wrapper preserving the original fetchCandles(string $symbol) contract
 * (env-driven timeframe, 60-candle minimum guard) while delegating the
 * actual HTTP call to a MarketDataProviderInterface implementation — no
 * business logic outside this class talks to Yahoo Finance directly anymore.
 *
 * Phase 2.2A: defaults to App\MarketData\Manager\MarketDataManager, which
 * tries Deriv first, then Yahoo Finance, then cached data (see that class's
 * doc comment) — a pure drop-in for the CachedMarketDataProvider(Yahoo)
 * stack this used before; MarketDataManager also implements this same
 * MarketDataProviderInterface, so the injection seam and its type are
 * unchanged.
 */
final class MarketDataService
{
    private MarketDataProviderInterface $provider;

    public function __construct(?MarketDataProviderInterface $provider = null)
    {
        $this->provider = $provider ?? new MarketDataManager();
    }

    public function fetchCandles(string $symbol): array
    {
        $timeframe = (string) env('MARKET_TIMEFRAME', '60m');
        $rows      = $this->provider->fetchCandles($symbol, $timeframe, '60d');

        if (count($rows) < 60) {
            throw new RuntimeException(
                'Insufficient candle data for ' . $symbol
                . ': only ' . count($rows) . ' valid candles returned (need 60+).'
            );
        }

        return $rows;
    }
}
