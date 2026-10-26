<?php
declare(strict_types=1);
namespace App\Consensus\Adapters;

use App\Consensus\Contracts\ConsensusSourceInterface;
use App\Consensus\ConsensusVote;
use App\Services\EconomicCalendarService;
use Throwable;

/**
 * Not a directional voter — a hard veto gate, same "calendar hard-block"
 * rule SignalPreviewService::evaluate() already applies to the technical
 * preview signal. An active high-impact event window forces the overall
 * consensus decision to WAIT regardless of how the other sources voted.
 */
final class CalendarVoteAdapter implements ConsensusSourceInterface
{
    public function vote(string $symbol, string $label): ConsensusVote
    {
        try {
            $risk = (new EconomicCalendarService())->getActiveRiskWindow($label, now_eat());

            if ($risk === null) {
                return new ConsensusVote(
                    source: 'Calendar',
                    direction: ConsensusVote::DIRECTION_NEUTRAL,
                    confidence: 100.0,
                    weight: 0.0,
                    reasoning: 'Safe — no high-impact event window active.',
                    available: true,
                    isDirectional: false,
                );
            }

            return new ConsensusVote(
                source: 'Calendar',
                direction: ConsensusVote::DIRECTION_NEUTRAL,
                confidence: 0.0,
                weight: 0.0,
                reasoning: "Risky — {$risk['event_title']} ({$risk['currency']}) at {$risk['scheduled_at']}.",
                available: true,
                isDirectional: false,
                isBlocking: true,
            );
        } catch (Throwable $e) {
            error_log("[CalendarVoteAdapter] {$label} failed: " . $e->getMessage());
            return new ConsensusVote('Calendar', ConsensusVote::DIRECTION_NEUTRAL, 0.0, 0.0, 'Calendar check unavailable — treating as unknown risk.', false, false);
        }
    }
}
