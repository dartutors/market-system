<?php
declare(strict_types=1);
namespace App\Domain;

use DateTimeImmutable;

/**
 * Single immutable view of "what do we know about this market right now."
 * Every future analysis module (Decision Engine, Trade Coach, Multi-Timeframe
 * Analysis) should consume this instead of rebuilding its own data structure
 * from raw scan pieces.
 */
final class MarketSnapshot
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $timeframe,
        public readonly array $ohlc,
        public readonly array $indicators,
        public readonly string $trend,
        public readonly string $momentum,
        public readonly string $session,
        public readonly array $sentiment,
        public readonly ?array $economicEvents,
        public readonly array $news,
        public readonly array $aiSummary,
        public readonly float $confidence,
        public readonly DateTimeImmutable $generatedAt,
    ) {
    }

    public function toArray(): array
    {
        return [
            'symbol'          => $this->symbol,
            'timeframe'       => $this->timeframe,
            'ohlc'            => $this->ohlc,
            'indicators'      => $this->indicators,
            'trend'           => $this->trend,
            'momentum'        => $this->momentum,
            'session'         => $this->session,
            'sentiment'       => $this->sentiment,
            'economic_events' => $this->economicEvents,
            'news'            => $this->news,
            'ai_summary'      => $this->aiSummary,
            'confidence'      => $this->confidence,
            'generated_at'    => $this->generatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
