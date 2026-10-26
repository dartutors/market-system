<?php
declare(strict_types=1);
namespace App\Contracts;

/**
 * Common contract for market OHLCV data sources. Mirrors
 * App\Services\News\NewsProviderInterface's existing pattern.
 */
interface MarketDataProviderInterface
{
    /**
     * @return array<int, array{timestamp:int, open:float, high:float, low:float, close:float, volume:float}>
     */
    public function fetchCandles(string $symbol, string $timeframe, string $range): array;
}
