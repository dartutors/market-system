<?php
declare(strict_types=1);
namespace App\MarketData\Models;

/**
 * Phase 2.2A's normalized market-data-layer snapshot — one instrument, one
 * timeframe, whatever a provider last reported, regardless of which
 * provider produced it. Fields exactly as specified in the Phase 2.2A brief:
 * Symbol, Timeframe, Bid, Ask, Open, High, Low, Close, Volume, Timestamp,
 * Provider.
 *
 * IMPORTANT — do not confuse this with App\Domain\MarketSnapshot: that is a
 * pre-existing, unrelated class (an analysis-layer aggregate consumed by
 * App\Decision\DecisionEngine, carrying trend/momentum/sentiment/AI-bias/
 * economic-events — built from data a scan cycle already computed, never
 * touching a provider directly). This class is the opposite end of the
 * pipeline: the raw, normalized output OF a provider, before any indicator
 * or decision logic runs on it. The two are intentionally similarly named
 * per the brief; they live in different namespaces
 * (App\MarketData\Models vs App\Domain) and are never interchangeable.
 */
final class MarketSnapshot
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $timeframe,
        public readonly float $bid,
        public readonly float $ask,
        public readonly float $open,
        public readonly float $high,
        public readonly float $low,
        public readonly float $close,
        public readonly float $volume,
        public readonly int $timestamp,
        public readonly string $provider,
    ) {
    }

    public static function fromCandleAndQuote(Candle $candle, ?Quote $quote = null): self
    {
        $bid = $quote?->bid ?? $candle->close;
        $ask = $quote?->ask ?? $candle->close;

        return new self(
            symbol: $candle->symbol,
            timeframe: $candle->timeframe,
            bid: $bid,
            ask: $ask,
            open: $candle->open,
            high: $candle->high,
            low: $candle->low,
            close: $candle->close,
            volume: $candle->volume,
            timestamp: $candle->timestamp,
            provider: $candle->provider,
        );
    }

    public function toArray(): array
    {
        return [
            'symbol'    => $this->symbol,
            'timeframe' => $this->timeframe,
            'bid'       => $this->bid,
            'ask'       => $this->ask,
            'open'      => $this->open,
            'high'      => $this->high,
            'low'       => $this->low,
            'close'     => $this->close,
            'volume'    => $this->volume,
            'timestamp' => $this->timestamp,
            'provider'  => $this->provider,
        ];
    }
}
