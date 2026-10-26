<?php
declare(strict_types=1);
namespace App\Consensus;

/**
 * "Is today a good day to trade this market" — separate from the
 * directional consensus (which says WHAT to do); this says how favorable
 * the overall conditions are regardless of direction. Weighted average of:
 * how much the sources agree (the strongest quality signal — a split panel
 * means uncertain conditions even if one source is loud about it), calendar
 * safety, session liquidity, and volatility favorability.
 */
final class MarketQualityScorer
{
    /**
     * @param ConsensusVote[] $votes
     * @return array{score: float, stars: int, label: string}
     */
    public function score(array $votes, float $agreementPercentage): array
    {
        $calendar   = $this->findSource($votes, 'Calendar');
        $session    = $this->findSource($votes, 'Session');
        $volatility = $this->findSource($votes, 'Volatility');

        $calendarSafety      = ($calendar !== null && $calendar->isBlocking) ? 0.0 : 100.0;
        $sessionFavorability = $session?->confidence ?? 50.0;
        $volatilityFavorability = $volatility?->confidence ?? 50.0;

        $score = round(
            $agreementPercentage * 0.35
            + $calendarSafety * 0.25
            + $sessionFavorability * 0.20
            + $volatilityFavorability * 0.20,
            1
        );
        $score = max(0.0, min(100.0, $score));

        $label = match (true) {
            $score >= 80 => 'Excellent Trading Conditions',
            $score >= 60 => 'Good Trading Conditions',
            $score >= 40 => 'Fair Trading Conditions',
            default      => 'Poor Trading Conditions',
        };

        return [
            'score' => $score,
            'stars' => max(1, min(5, (int) round($score / 20))),
            'label' => $label,
        ];
    }

    /** @param ConsensusVote[] $votes */
    private function findSource(array $votes, string $source): ?ConsensusVote
    {
        foreach ($votes as $vote) {
            if ($vote->source === $source) {
                return $vote;
            }
        }
        return null;
    }
}
