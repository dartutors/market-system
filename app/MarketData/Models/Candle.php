<?php
declare(strict_types=1);
namespace App\MarketData\Models;

/**
 * One normalized OHLCV bar, identical shape regardless of which provider
 * produced it (Deriv, Yahoo Finance, or a cached copy of either). This is
 * the Phase 2.2A replacement for the raw
 * `array{timestamp:int, open:float, high:float, low:float, close:float, volume:float}`
 * shape that App\Contracts\MarketDataProviderInterface's fetchCandles()
 * still returns — that older interface and its array shape are left
 * untouched for backward compatibility (see
 * App\MarketData\Manager\MarketDataManager's doc comment), but any new code
 * should consume Candle objects instead.
 */
final class Candle
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $timeframe,
        public readonly int $timestamp,
        public readonly float $open,
        public readonly float $high,
        public readonly float $low,
        public readonly float $close,
        public readonly float $volume,
        public readonly string $provider,
    ) {
    }

    /** @return array{timestamp:int, open:float, high:float, low:float, close:float, volume:float} */
    public function toLegacyRow(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'open'      => $this->open,
            'high'      => $this->high,
            'low'       => $this->low,
            'close'     => $this->close,
            'volume'    => $this->volume,
        ];
    }

    public function toArray(): array
    {
        return [
            'symbol'    => $this->symbol,
            'timeframe' => $this->timeframe,
            'timestamp' => $this->timestamp,
            'open'      => $this->open,
            'high'      => $this->high,
            'low'       => $this->low,
            'close'     => $this->close,
            'volume'    => $this->volume,
            'provider'  => $this->provider,
        ];
    }
}
