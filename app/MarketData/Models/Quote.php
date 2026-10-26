<?php
declare(strict_types=1);
namespace App\MarketData\Models;

/**
 * A normalized live/last-known price quote. Neither Yahoo Finance's chart
 * API nor a candle-derived quote actually carries a real bid/ask spread —
 * both providers here set bid = ask = last close as an honest
 * approximation (documented per-provider in each Providers/*.php file), so
 * consumers must not assume a real spread is present unless
 * $spreadIsReal is true.
 */
final class Quote
{
    public function __construct(
        public readonly string $symbol,
        public readonly float $bid,
        public readonly float $ask,
        public readonly int $timestamp,
        public readonly string $provider,
        public readonly bool $spreadIsReal = false,
    ) {
    }

    public function mid(): float
    {
        return round(($this->bid + $this->ask) / 2, 6);
    }

    public function toArray(): array
    {
        return [
            'symbol'         => $this->symbol,
            'bid'            => $this->bid,
            'ask'            => $this->ask,
            'mid'            => $this->mid(),
            'timestamp'      => $this->timestamp,
            'provider'       => $this->provider,
            'spread_is_real' => $this->spreadIsReal,
        ];
    }
}
