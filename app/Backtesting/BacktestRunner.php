<?php
declare(strict_types=1);
namespace App\Backtesting;

use App\Backtesting\Models\TradeResult;
use App\Backtesting\Support\EquityCurveBuilder;
use App\MarketData\Manager\MarketDataManager;
use App\MarketData\Models\Candle;
use App\Services\IndicatorService;
use App\Strategies\StrategyManager;

/**
 * Phase 3.1's core simulation primitive. Same educational semantics as the
 * existing App\Services\BacktestService (STEP=4 sampling cadence,
 * MIN_CANDLES=55 warmup, ATR target/stop, 8-candle forward window,
 * win=+2R/loss=-1R/breakeven=0R) — that service is deliberately left
 * untouched (still backs the original /backtest page). The one real
 * difference: scoring goes through App\Strategies\StrategyManager instead
 * of a hardcoded technicalScore(), which is what makes strategy comparison,
 * walk-forward testing, and parameter optimization possible at all.
 *
 * Split into run() (fetch+enrich from live data) and runOnRows() (pure
 * simulation over already-enriched rows) so callers that need many passes
 * over the same history — StrategyComparator, WalkForwardTester,
 * ParameterOptimizer — fetch candles exactly once per request.
 */
final class BacktestRunner
{
    private const STEP        = 4;
    private const MIN_CANDLES = 55;

    public function run(
        string $symbol,
        string $timeframeLabel,
        int $barsBack,
        string $strategyName,
        float $targetAtrMult = 2.0,
        float $stopAtrMult = 1.2
    ): array {
        try {
            $candles = (new MarketDataManager())->getCandles($symbol, $timeframeLabel);
        } catch (\Throwable $e) {
            return ['error' => 'Data fetch failed: ' . $e->getMessage()];
        }

        $rows     = array_map(static fn(Candle $c): array => $c->toLegacyRow(), $candles);
        $enriched = (new IndicatorService())->enrich($rows);

        return $this->runOnRows($enriched, $strategyName, $targetAtrMult, $stopAtrMult, $barsBack);
    }

    /**
     * @param array<int, array<string, mixed>> $enrichedRows Chronological, already IndicatorService::enrich()'d.
     */
    public function runOnRows(
        array $enrichedRows,
        string $strategyName,
        float $targetAtrMult = 2.0,
        float $stopAtrMult = 1.2,
        ?int $barsBack = null
    ): array {
        $total = \count($enrichedRows);
        if ($total < self::MIN_CANDLES + 4) {
            return ['error' => 'Insufficient historical data for backtest.'];
        }

        $startIndex = self::MIN_CANDLES;
        if ($barsBack !== null) {
            $startIndex = max(self::MIN_CANDLES, $total - $barsBack);
        }

        $manager = new StrategyManager();
        $trades  = [];

        for ($i = $startIndex; $i < $total - 4; $i += self::STEP) {
            $rowsSoFar  = array_slice($enrichedRows, 0, $i + 1);
            $evaluation = $manager->evaluate($rowsSoFar, ['score' => 0.0], $strategyName);
            $action     = (string) ($evaluation['action'] ?? 'HOLD');

            if ($action !== 'BUY' && $action !== 'SELL') {
                continue;
            }

            $latest     = $enrichedRows[$i];
            $entryPrice = (float) $latest['close'];
            $atr        = max((float) ($latest['atr_14'] ?? 0.0), 0.0001);
            $forward    = array_slice($enrichedRows, $i + 1, 8);
            [$outcome, $resolvedAt] = $this->determineOutcome($action, $entryPrice, $atr, $forward, $targetAtrMult, $stopAtrMult);
            $pl         = match ($outcome) {
                'win'   => 2.0,
                'loss'  => -1.0,
                default => 0.0,
            };

            $trades[] = new TradeResult(
                $i,
                (int) $latest['timestamp'],
                $action,
                $entryPrice,
                $atr,
                (float) ($evaluation['confidence'] ?? 50.0),
                $outcome,
                $pl,
                \is_array($evaluation['reasons'] ?? null) ? $evaluation['reasons'] : [],
                $resolvedAt,
            );
        }

        $curve = EquityCurveBuilder::build($trades);

        return [
            'strategy'     => $strategyName,
            'bars_used'    => $total,
            'trades'       => $trades,
            'equity_curve' => $curve['points'],
            'summary'      => $this->summarize($trades, $curve, $targetAtrMult, $stopAtrMult),
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** @return array{0: string, 1: ?int} [outcome, bars-forward-to-resolution] — resolution index is null when nothing hit within the forward window (timed out to breakeven/unknown, not a real "0 bars" resolution). */
    private function determineOutcome(
        string $action,
        float  $entry,
        float  $atr,
        array  $forward,
        float  $targetAtrMult,
        float  $stopAtrMult
    ): array {
        if (empty($forward)) {
            return ['unknown', null];
        }

        $target = $atr * $targetAtrMult;
        $stop   = $atr * $stopAtrMult;

        foreach (array_values($forward) as $barsForward => $c) {
            $hi = (float) $c['high'];
            $lo = (float) $c['low'];
            if ($action === 'BUY') {
                if ($hi >= $entry + $target) return ['win', $barsForward + 1];
                if ($lo <= $entry - $stop)   return ['loss', $barsForward + 1];
            } else {
                if ($lo <= $entry - $target) return ['win', $barsForward + 1];
                if ($hi >= $entry + $stop)   return ['loss', $barsForward + 1];
            }
        }

        $lastClose = (float) end($forward)['close'];
        $delta     = $lastClose - $entry;
        $timedOutAt = \count($forward);
        if ($action === 'BUY') {
            $outcome = $delta > $atr * 0.2 ? 'win' : ($delta < -$atr * 0.2 ? 'loss' : 'breakeven');
        } else {
            $outcome = $delta < -$atr * 0.2 ? 'win' : ($delta > $atr * 0.2 ? 'loss' : 'breakeven');
        }
        // A directional call decided by where price simply ended up (never
        // cleanly hit target or stop) still has a real duration — only
        // 'breakeven' (no meaningful move either way) has no resolution to
        // measure a duration against.
        return [$outcome, $outcome === 'breakeven' ? null : $timedOutAt];
    }

    /** @param array<int, TradeResult> $trades */
    private function summarize(array $trades, array $curve, float $targetAtrMult, float $stopAtrMult): array
    {
        $total      = \count($trades);
        $wins       = \count(array_filter($trades, static fn(TradeResult $t): bool => $t->outcome === 'win'));
        $losses     = \count(array_filter($trades, static fn(TradeResult $t): bool => $t->outcome === 'loss'));
        $breakevens = \count(array_filter($trades, static fn(TradeResult $t): bool => $t->outcome === 'breakeven'));
        $base       = max(1, $total);
        $avgConf    = $total > 0
                      ? round(array_sum(array_map(static fn(TradeResult $t): float => $t->confidence, $trades)) / $total, 1)
                      : 0.0;

        $buys     = array_filter($trades, static fn(TradeResult $t): bool => $t->action === 'BUY');
        $sells    = array_filter($trades, static fn(TradeResult $t): bool => $t->action === 'SELL');
        $buyWins  = \count(array_filter($buys,  static fn(TradeResult $t): bool => $t->outcome === 'win'));
        $sellWins = \count(array_filter($sells, static fn(TradeResult $t): bool => $t->outcome === 'win'));

        $grossWin  = array_sum(array_map(static fn(TradeResult $t): float => $t->pl > 0 ? $t->pl : 0.0, $trades));
        $grossLoss = abs(array_sum(array_map(static fn(TradeResult $t): float => $t->pl < 0 ? $t->pl : 0.0, $trades)));
        $profitFactor = $grossLoss > 0.0 ? round($grossWin / $grossLoss, 2) : ($grossWin > 0.0 ? 99.99 : 0.0);

        // Every trade here is generated against the SAME target/stop ATR
        // multiples (see runOnRows()'s $targetAtrMult/$stopAtrMult params) —
        // so unlike win rate, average R:R isn't something to derive from
        // individual trades that don't vary; it's the ratio those two
        // parameters already are, stated honestly rather than as a computed
        // per-trade average that would just be this same number every time.
        $avgRiskReward = $stopAtrMult > 0.0 ? round($targetAtrMult / $stopAtrMult, 2) : 0.0;

        $durations = array_values(array_filter(array_map(
            static fn(TradeResult $t): ?int => $t->resolvedAtBarsForward,
            $trades
        ), static fn(?int $d): bool => $d !== null));
        $avgDurationBars = $durations !== [] ? round(array_sum($durations) / \count($durations), 1) : null;

        return [
            'total_signals'  => $total,
            'wins'           => $wins,
            'losses'         => $losses,
            'breakevens'     => $breakevens,
            'win_rate'       => $total > 0 ? round($wins   / $base * 100, 1) : 0.0,
            'loss_rate'      => $total > 0 ? round($losses / $base * 100, 1) : 0.0,
            'avg_confidence' => $avgConf,
            'max_drawdown_r' => $curve['max_drawdown'],
            'final_equity_r' => $curve['final_equity'],
            'profit_factor'  => $profitFactor,
            'avg_risk_reward'        => $avgRiskReward,
            'avg_trade_duration_bars'=> $avgDurationBars,
            'reliability'    => $this->reliabilityRating($total, $profitFactor, $total > 0 ? $wins / $base : 0.0),
            'buy_win_rate'   => \count($buys)  > 0 ? round($buyWins  / \count($buys)  * 100, 1) : 0.0,
            'sell_win_rate'  => \count($sells) > 0 ? round($sellWins / \count($sells) * 100, 1) : 0.0,
            'note'           => 'Simulated results only. No session filter applied. No financial advice.',
        ];
    }

    /**
     * A small sample tells you almost nothing about whether a strategy is
     * actually good — this deliberately refuses to rate anything below a
     * minimum trade count rather than hand out a confident-looking label
     * off 3 simulated trades.
     */
    private function reliabilityRating(int $totalTrades, float $profitFactor, float $winRate): array
    {
        if ($totalTrades < 15) {
            return ['label' => 'Insufficient data', 'note' => "Only {$totalTrades} simulated trades — need at least 15 for a reliability read."];
        }

        $score = match (true) {
            $profitFactor >= 1.5 && $winRate >= 0.45 => 'High',
            $profitFactor >= 1.1 && $winRate >= 0.35 => 'Medium',
            default => 'Low',
        };

        return ['label' => $score, 'note' => "Based on {$totalTrades} simulated trades, profit factor {$profitFactor}, win rate " . round($winRate * 100, 1) . '%.'];
    }
}
