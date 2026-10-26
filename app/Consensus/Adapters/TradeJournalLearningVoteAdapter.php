<?php
declare(strict_types=1);
namespace App\Consensus\Adapters;

use App\Consensus\Contracts\ConsensusSourceInterface;
use App\Consensus\ConsensusVote;
use App\Models\TradeJournal;
use Throwable;

/**
 * Not a directional voter — the trader's own logged history for this pair
 * doesn't imply BUY or SELL either, it's a "should I trust myself on this
 * pair today" modifier. Reuses TradeJournal::stats()['by_market'] (already
 * computed for the Trade Journal page) rather than a new query — finds this
 * market's row if the trader has closed trades on it. Honestly reports "no
 * journal history" rather than a fabricated neutral score when nothing's
 * been logged for this pair yet.
 */
final class TradeJournalLearningVoteAdapter implements ConsensusSourceInterface
{
    private const MIN_SAMPLE_SIZE = 5;

    public function vote(string $symbol, string $label): ConsensusVote
    {
        try {
            $stats = TradeJournal::stats();
            $marketRow = null;
            foreach ($stats['by_market'] as $row) {
                if ((string) $row['market_label'] === $label) {
                    $marketRow = $row;
                    break;
                }
            }

            if ($marketRow === null || (int) $marketRow['total'] < self::MIN_SAMPLE_SIZE) {
                $have = $marketRow !== null ? (int) $marketRow['total'] : 0;
                return new ConsensusVote(
                    'TradeJournalLearning', ConsensusVote::DIRECTION_NEUTRAL, 50.0, 0.0,
                    "Not enough logged trade history for {$label} yet ({$have} closed trades, need " . self::MIN_SAMPLE_SIZE . ').',
                    true, false,
                );
            }

            $total = (int) $marketRow['total'];
            $wins = (int) $marketRow['wins'];
            $winRate = round($wins / $total * 100, 1);

            return new ConsensusVote(
                source: 'TradeJournalLearning',
                direction: ConsensusVote::DIRECTION_NEUTRAL,
                confidence: $winRate,
                weight: 0.0,
                reasoning: "Your own journal shows a {$winRate}% win rate on {$label} across {$total} logged trades.",
                available: true,
                isDirectional: false,
            );
        } catch (Throwable $e) {
            error_log("[TradeJournalLearningVoteAdapter] {$label} failed: " . $e->getMessage());
            return new ConsensusVote('TradeJournalLearning', ConsensusVote::DIRECTION_NEUTRAL, 50.0, 0.0, 'Journal history unavailable.', false, false);
        }
    }
}
