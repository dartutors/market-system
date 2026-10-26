<?php
declare(strict_types=1);
namespace App\Consensus;

use App\MarketData\Manager\MarketDataManager;
use App\Models\ConsensusDecision;
use Throwable;

/**
 * Objective 8/9's "Outcome (when known)" — resolves past BUY/SELL decisions
 * against REAL forward price action, not a simulation. A decision is ready
 * to check once its own expected_duration_hours has elapsed; win/loss is
 * determined by whether the current price has reached the stored take-
 * profit-1 or stop-loss level, same win/loss geometry BacktestRunner uses
 * for simulated trades, applied here to what actually happened. If neither
 * level was reached by review time, the outcome is 'timeout' — real
 * information (the setup didn't resolve either way), not silently dropped.
 */
final class OutcomeResolver
{
    public function __construct(private MarketDataManager $marketData = new MarketDataManager())
    {
    }

    /** @return array{checked:int, resolved:int, errors:int} */
    public function resolvePending(): array
    {
        $pending = ConsensusDecision::pendingOutcomeResolution();
        $checked = 0;
        $resolved = 0;
        $errors = 0;

        foreach ($pending as $row) {
            $checked++;
            try {
                if ($this->resolveOne($row)) {
                    $resolved++;
                }
            } catch (Throwable $e) {
                $errors++;
                error_log("[OutcomeResolver] Failed to resolve decision #{$row['id']}: " . $e->getMessage());
            }
        }

        return ['checked' => $checked, 'resolved' => $resolved, 'errors' => $errors];
    }

    private function resolveOne(array $row): bool
    {
        $tradePlan = json_decode((string) $row['trade_plan_json'], true);
        if (!\is_array($tradePlan) || !isset($tradePlan['entry_price'], $tradePlan['stop_loss'], $tradePlan['take_profit_1'])) {
            return false;
        }

        $durationHours = (array) ($tradePlan['expected_duration_hours'] ?? [4, 18]);
        $maxHours = (float) ($durationHours[1] ?? 18);
        $generatedAt = strtotime((string) $row['generated_at']);
        $elapsedHours = (time() - $generatedAt) / 3600;

        if ($elapsedHours < $maxHours) {
            return false; // not due for review yet
        }

        try {
            $quote = $this->marketData->getQuote((string) $row['market_symbol']);
        } catch (Throwable $e) {
            error_log("[OutcomeResolver] Quote fetch failed for {$row['market_symbol']}: " . $e->getMessage());
            return false;
        }

        $currentPrice = $quote->mid();
        $direction    = (string) $row['overall_decision'];
        $entry        = (float) $tradePlan['entry_price'];
        $stop         = (float) $tradePlan['stop_loss'];
        $target       = (float) $tradePlan['take_profit_1'];

        $outcome = 'timeout';
        if ($direction === 'BUY') {
            if ($currentPrice >= $target) $outcome = 'win';
            elseif ($currentPrice <= $stop) $outcome = 'loss';
        } elseif ($direction === 'SELL') {
            if ($currentPrice <= $target) $outcome = 'win';
            elseif ($currentPrice >= $stop) $outcome = 'loss';
        }

        ConsensusDecision::markOutcome((int) $row['id'], $outcome);
        return true;
    }
}
