<?php
declare(strict_types=1);
namespace App\Consensus\Adapters;

use App\Consensus\Contracts\ConsensusSourceInterface;
use App\Consensus\ConsensusVote;
use App\Consensus\EvidenceWeights;
use App\Models\BacktestSummary;
use Throwable;

/**
 * Reads the most recent PERSISTED Advanced Backtest run for this symbol
 * (App\Models\BacktestSummary, written by AdvancedBacktestController::run())
 * — never triggers a new backtest inline (a 1000-iteration Monte Carlo +
 * walk-forward test + AI explanation is far too slow for this to run on
 * every consensus refresh). A backtest is a manual, on-demand action, so
 * this is honestly "not available" until a user has actually run one for
 * this pair, and stale beyond 7 days (backtests reflect historical
 * strategy behavior, not live conditions, but a month-old run says less
 * about "should I trade this today" than a recent one).
 */
final class StrategyLabVoteAdapter implements ConsensusSourceInterface
{
    public const WEIGHT = 1.0;
    private const FRESHNESS_DAYS = 7;
    private const DEFAULT_TIMEFRAME = 'Daily';

    public function vote(string $symbol, string $label): ConsensusVote
    {
        try {
            $row = BacktestSummary::latestForSymbol($symbol, self::DEFAULT_TIMEFRAME);
            if ($row === null) {
                return $this->unavailable();
            }

            $ageDays = (time() - strtotime((string) $row['created_at'])) / 86400;
            if ($ageDays > self::FRESHNESS_DAYS) {
                return $this->unavailable();
            }

            $decision = strtoupper((string) $row['decision']);
            $direction = match ($decision) {
                'BUY'  => ConsensusVote::DIRECTION_BUY,
                'SELL' => ConsensusVote::DIRECTION_SELL,
                default => ConsensusVote::DIRECTION_NEUTRAL,
            };

            $stats = (array) ($row['stats'] ?? []);
            $primarySummary = (array) ($stats['primary']['summary'] ?? []);
            $winRate = $primarySummary['win_rate'] ?? null;
            $profitFactor = $primarySummary['profit_factor'] ?? null;
            $reliability = (array) ($primarySummary['reliability'] ?? []);

            $reasoning = "Last backtest ({$this->ago($ageDays)}): {$decision} at " . round((float) $row['confidence']) . '% confidence';
            if ($winRate !== null) {
                $reasoning .= ", {$winRate}% simulated win rate";
            }
            if ($profitFactor !== null) {
                $reasoning .= ", profit factor {$profitFactor}";
            }
            if (!empty($reliability['label'])) {
                $reasoning .= " ({$reliability['label']} reliability)";
            }
            $reasoning .= '.';

            return new ConsensusVote(
                source: 'StrategyLab',
                direction: $direction,
                confidence: (float) $row['confidence'],
                weight: EvidenceWeights::for('StrategyLab', self::WEIGHT),
                reasoning: $reasoning,
                available: true,
            );
        } catch (Throwable $e) {
            error_log("[StrategyLabVoteAdapter] {$label} failed: " . $e->getMessage());
            return $this->unavailable();
        }
    }

    private function ago(float $days): string
    {
        return $days < 1 ? round($days * 24) . 'h ago' : round($days, 1) . 'd ago';
    }

    private function unavailable(): ConsensusVote
    {
        return new ConsensusVote('StrategyLab', ConsensusVote::DIRECTION_NEUTRAL, 0.0, self::WEIGHT, 'No recent Advanced Backtest run for this pair.', false);
    }
}
