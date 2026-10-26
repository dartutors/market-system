<?php
declare(strict_types=1);
namespace App\Consensus;

/**
 * Named, specific conflict rules — deliberately more structured than just
 * "these sources disagreed" (which ConsensusScorer already reports via
 * disagreement_reasons). Each rule here checks a real, nameable market
 * condition a trader would recognize, not just "vote A != vote B". Every
 * detected conflict both explains itself AND reduces the eventual
 * confidence score — see DecisionIntelligenceEngine::applyConflictPenalty().
 */
final class ConflictDetector
{
    /**
     * @param ConsensusVote[] $votes
     * @return array<int, array{type:string, description:string, severity:string}>
     */
    public function detect(array $votes): array
    {
        $conflicts = [];
        $byName = [];
        foreach ($votes as $vote) {
            $byName[$vote->source] = $vote;
        }

        $technical = $byName['Technical'] ?? null;
        $trend     = $byName['TrendAlignment'] ?? null;
        $news      = $byName['News'] ?? null;
        $calendar  = $byName['Calendar'] ?? null;
        $liquidity = $byName['Liquidity'] ?? null;
        $session   = $byName['Session'] ?? null;

        // 1. Higher-timeframe bias vs immediate technical read disagree.
        if ($technical !== null && $trend !== null && $technical->available && $trend->available
            && $technical->direction !== ConsensusVote::DIRECTION_NEUTRAL
            && $trend->direction !== ConsensusVote::DIRECTION_NEUTRAL
            && $technical->direction !== $trend->direction
        ) {
            $conflicts[] = [
                'type'        => 'timeframe_disagreement',
                'description' => "Higher-timeframe bias ({$trend->direction}) disagrees with the immediate technical read ({$technical->direction}).",
                'severity'    => 'high',
            ];
        }

        // 2. News sentiment vs technical indicators disagree.
        if ($technical !== null && $news !== null && $technical->available && $news->available
            && $technical->direction !== ConsensusVote::DIRECTION_NEUTRAL
            && $news->direction !== ConsensusVote::DIRECTION_NEUTRAL
            && $technical->direction !== $news->direction
        ) {
            $conflicts[] = [
                'type'        => 'news_technical_disagreement',
                'description' => "News sentiment ({$news->direction}) disagrees with technical indicators ({$technical->direction}).",
                'severity'    => 'medium',
            ];
        }

        // 3. High-impact news imminent (Calendar's own hard-block window).
        if ($calendar !== null && $calendar->isBlocking) {
            $conflicts[] = [
                'type'        => 'calendar_risk_window',
                'description' => "Active high-impact news window: {$calendar->reasoning}",
                'severity'    => 'high',
            ];
        }

        // 4. Low liquidity while other sources lean toward a trade.
        $hasDirectionalLean = false;
        foreach ($votes as $vote) {
            if ($vote->isDirectional && $vote->available && $vote->direction !== ConsensusVote::DIRECTION_NEUTRAL) {
                $hasDirectionalLean = true;
                break;
            }
        }
        if ($liquidity !== null && $liquidity->available && $liquidity->confidence < 40.0 && $hasDirectionalLean) {
            $conflicts[] = [
                'type'        => 'low_liquidity_entry',
                'description' => "Directional signal present but liquidity is thin right now ({$liquidity->reasoning}) — wider spreads and less reliable execution.",
                'severity'    => 'medium',
            ];
        }

        // 5. Strong signal during an unsuitable session (off-hours / low favorability).
        if ($session !== null && $session->available && $session->confidence < 30.0 && $hasDirectionalLean) {
            $conflicts[] = [
                'type'        => 'unsuitable_session',
                'description' => "Directional signal present outside a favorable trading session ({$session->reasoning}).",
                'severity'    => 'medium',
            ];
        }

        return $conflicts;
    }
}
