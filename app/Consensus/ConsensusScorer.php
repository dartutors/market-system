<?php
declare(strict_types=1);
namespace App\Consensus;

/**
 * Fuses a set of ConsensusVote objects into one overall decision. Only
 * votes with isDirectional=true AND available=true count toward the
 * BUY/SELL/NEUTRAL tally and the agreement percentage — Session/Volatility/
 * Calendar never vote a direction, and an unavailable source (no recent
 * Vision analysis, AI call failed) is excluded rather than silently
 * counted as a NEUTRAL vote, which would understate real agreement.
 */
final class ConsensusScorer
{
    private const MIN_DIRECTIONAL_VOTERS = 2;

    /**
     * @param ConsensusVote[] $votes
     * @return array{
     *   overall_decision: string, consensus_score: float, agreement_percentage: float,
     *   disagreement_reasons: array<int, array{source:string, direction:string, reasoning:string}>,
     *   blocked_reason: ?string
     * }
     */
    public function score(array $votes): array
    {
        $calendarVote = $this->findSource($votes, 'Calendar');
        if ($calendarVote !== null && $calendarVote->isBlocking) {
            return [
                'overall_decision'      => ConsensusVote::DIRECTION_NEUTRAL,
                'consensus_score'       => 0.0,
                'agreement_percentage'  => 0.0,
                'disagreement_reasons'  => [],
                'blocked_reason'        => $calendarVote->reasoning,
            ];
        }

        $directional = array_values(array_filter(
            $votes,
            static fn(ConsensusVote $v): bool => $v->isDirectional && $v->available
        ));

        if (\count($directional) < self::MIN_DIRECTIONAL_VOTERS) {
            return [
                'overall_decision'      => ConsensusVote::DIRECTION_NEUTRAL,
                'consensus_score'       => 0.0,
                'agreement_percentage'  => 0.0,
                'disagreement_reasons'  => [],
                'blocked_reason'        => 'Insufficient data — fewer than ' . self::MIN_DIRECTIONAL_VOTERS . ' sources available to form a consensus.',
            ];
        }

        $weightedSums = [ConsensusVote::DIRECTION_BUY => 0.0, ConsensusVote::DIRECTION_SELL => 0.0, ConsensusVote::DIRECTION_NEUTRAL => 0.0];
        $totalWeight  = 0.0;

        foreach ($directional as $vote) {
            $weightedSums[$vote->direction] += $vote->confidence * $vote->weight;
            $totalWeight += $vote->weight;
        }

        $majority = (string) array_search(max($weightedSums), $weightedSums, true);

        if ($totalWeight <= 0.0) {
            return [
                'overall_decision'      => ConsensusVote::DIRECTION_NEUTRAL,
                'consensus_score'       => 0.0,
                'agreement_percentage'  => 0.0,
                'disagreement_reasons'  => $this->disagreements($directional, ConsensusVote::DIRECTION_NEUTRAL),
                'blocked_reason'        => null,
            ];
        }

        // Computed the same way regardless of whether the majority lands on
        // BUY, SELL, or NEUTRAL — "how much do sources actually agree" is
        // real information even when what they agree on is "don't trade
        // this," not something to zero out just because it's not a
        // directional call. Only NEUTRAL's consensus_score is suppressed
        // below: a numeric "confidence in not trading" isn't meaningful to
        // surface the same way a BUY/SELL confidence is.
        $agreeingWeight = 0.0;
        $agreeingConfidenceWeighted = 0.0;
        foreach ($directional as $vote) {
            if ($vote->direction === $majority) {
                $agreeingWeight += $vote->weight;
                $agreeingConfidenceWeighted += $vote->confidence * $vote->weight;
            }
        }

        $agreementPercentage = round(($agreeingWeight / $totalWeight) * 100, 1);
        $consensusScore = $majority === ConsensusVote::DIRECTION_NEUTRAL
            ? 0.0
            : ($agreeingWeight > 0 ? round($agreeingConfidenceWeighted / $agreeingWeight, 1) : 0.0);

        return [
            'overall_decision'      => $majority,
            'consensus_score'       => $consensusScore,
            'agreement_percentage'  => $agreementPercentage,
            'disagreement_reasons'  => $this->disagreements($directional, $majority),
            'blocked_reason'        => null,
        ];
    }

    /** @param ConsensusVote[] $directional @return array<int, array{source:string, direction:string, reasoning:string}> */
    private function disagreements(array $directional, string $majority): array
    {
        $out = [];
        foreach ($directional as $vote) {
            if ($vote->direction !== $majority) {
                $out[] = ['source' => $vote->source, 'direction' => $vote->direction, 'reasoning' => $vote->reasoning];
            }
        }
        return $out;
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
