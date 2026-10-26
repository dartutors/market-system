<?php
declare(strict_types=1);
namespace App\MultiTimeframe;

use DateTimeImmutable;

final class MultiTimeframeResult
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $overallBias, // bullish|bearish|neutral
        public readonly float $confidence,
        public readonly array $breakdown,    // label => ['trend','momentum','status','weight']
        public readonly array $conflicts,    // human-readable conflict descriptions
        public readonly DateTimeImmutable $generatedAt,
    ) {
    }

    public function toArray(): array
    {
        return [
            'symbol'       => $this->symbol,
            'overall_bias' => $this->overallBias,
            'confidence'   => $this->confidence,
            'breakdown'    => $this->breakdown,
            'conflicts'    => $this->conflicts,
            'generated_at' => $this->generatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
