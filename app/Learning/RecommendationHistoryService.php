<?php
declare(strict_types=1);
namespace App\Learning;

use App\Models\AiDecision;
use App\Models\TradeJournal;

/**
 * Cross-references historical AiDecision rows against whatever actually
 * happened — but only for the subset a user chose to log to their Trade
 * Journal via trade_journal.decision_id (Phase 2.6's "Log to Journal" flow).
 * ai_decisions itself has no outcome column and no reliable join key to
 * signal_events, so full automatic outcome tracking for every historical
 * recommendation isn't possible without that gap — this is an honest partial
 * view, not a claim of complete coverage.
 */
final class RecommendationHistoryService
{
    /**
     * @return array<int, array{id:int, market_label:string, decision:string,
     *   confidence:float, created_at:string, logged:bool, outcome:?string, profit_loss:?float}>
     */
    public function recent(int $limit = 20): array
    {
        $decisions   = AiDecision::latest($limit);
        $decisionIds = array_map(static fn(array $d): int => (int) $d['id'], $decisions);
        $linked      = TradeJournal::indexedByDecisionId($decisionIds);

        $history = [];
        foreach ($decisions as $d) {
            $id     = (int) $d['id'];
            $journal = $linked[$id] ?? null;

            $history[] = [
                'id'            => $id,
                'market_label'  => (string) $d['market_label'],
                'decision'      => (string) $d['decision'],
                'confidence'    => (float) $d['confidence'],
                'created_at'    => (string) $d['created_at'],
                'logged'        => $journal !== null,
                'outcome'       => $journal['outcome']      ?? null,
                'profit_loss'   => isset($journal['profit_loss']) ? (float) $journal['profit_loss'] : null,
            ];
        }

        return $history;
    }
}
