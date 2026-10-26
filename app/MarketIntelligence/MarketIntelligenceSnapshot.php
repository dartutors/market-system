<?php
declare(strict_types=1);
namespace App\MarketIntelligence;

use DateTimeImmutable;

final class MarketIntelligenceSnapshot
{
    /**
     * @param array<string, PairSnapshot> $pairSnapshots
     * @param array<string, float> $currencyStrength
     * @param array $volatilityIndex
     * @param array $sessionActivity
     * @param array $sentimentIndex
     * @param array $newsImpact
     * @param array<int, array> $correlationMatrix
     * @param array<int, array> $strengthRanking
     */
    public function __construct(
        public readonly array $pairSnapshots,
        public readonly array $currencyStrength,
        public readonly array $volatilityIndex,
        public readonly array $sessionActivity,
        public readonly array $sentimentIndex,
        public readonly array $newsImpact,
        public readonly array $correlationMatrix,
        public readonly array $strengthRanking,
        public readonly DateTimeImmutable $generatedAt,
    ) {
    }

    public function toArray(): array
    {
        return [
            'pair_snapshots'     => array_map(static fn(PairSnapshot $p): array => $p->toArray(), $this->pairSnapshots),
            'currency_strength'  => $this->currencyStrength,
            'volatility_index'   => $this->volatilityIndex,
            'session_activity'   => $this->sessionActivity,
            'sentiment_index'    => $this->sentimentIndex,
            'news_impact'        => $this->newsImpact,
            'correlation_matrix' => $this->correlationMatrix,
            'strength_ranking'   => $this->strengthRanking,
            'generated_at'       => $this->generatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
