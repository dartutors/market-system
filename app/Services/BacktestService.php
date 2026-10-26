<?php
declare(strict_types=1);
namespace App\Services;

use App\Contracts\MarketDataProviderInterface;
use App\MarketData\Manager\MarketDataManager;

/**
 * Simulates signal generation on historical OHLCV data and evaluates outcomes.
 *
 * Rules:
 * - Uses only technical indicators (SMA, RSI, MACD) — no session/time filters.
 * - BUY win: price reaches +2×ATR before hitting −1.2×ATR stop.
 * - SELL win: price reaches −2×ATR before hitting +1.2×ATR stop.
 * - No real money, no live trades — educational only.
 *
 * Phase 2.2A: defaults to App\MarketData\Manager\MarketDataManager (Deriv ->
 * Yahoo Finance -> cache) instead of talking to Yahoo Finance directly —
 * historical-calculation logic below is unchanged; only where the candles
 * come from changed. Do not change historical calculations here (Phase 9's
 * explicit instruction) — only Deriv's future candle compatibility does.
 */
final class BacktestService
{
    private const STEP         = 4;   // evaluate every N candles (≈ every 4 hours)
    private const MIN_CANDLES  = 55;  // need 50 for SMA50 + lookback

    private MarketDataProviderInterface $provider;

    public function __construct(?MarketDataProviderInterface $provider = null)
    {
        $this->provider = $provider ?? new MarketDataManager();
    }

    public function run(string $symbol, string $timeframe, int $days): array
    {
        $range = $this->daysToRange($days);

        try {
            $candles = $this->fetchCandles($symbol, $timeframe, $range);
        } catch (\Throwable $e) {
            return ['error' => 'Data fetch failed: ' . $e->getMessage()];
        }

        if (\count($candles) < self::MIN_CANDLES + 4) {
            return ['error' => 'Insufficient historical data for backtest.'];
        }

        $indicators  = new IndicatorService();
        $enriched    = $indicators->enrich($candles);
        $total       = \count($enriched);
        $trades      = [];
        $plCurve     = [];      // for max-drawdown
        $runningPl   = 0.0;
        $peak        = 0.0;
        $maxDrawdown = 0.0;

        for ($i = self::MIN_CANDLES; $i < $total - 4; $i += self::STEP) {
            $latest = $enriched[$i];
            $prev   = $enriched[$i - 1];

            $score  = $this->technicalScore($latest, $prev);
            if ($score === 0) {
                continue;
            }

            $action = match (true) {
                $score >= 3  => 'BUY',
                $score <= -3 => 'SELL',
                default      => null,
            };

            if ($action === null) {
                continue;
            }

            $confidence  = min(85.0, 55.0 + abs($score) * 7.5);
            $entryPrice  = (float) $latest['close'];
            $atr         = max((float) $latest['atr_14'], 0.0001);
            $forward     = array_slice($enriched, $i + 1, 8); // up to 8 candles forward

            $outcome     = $this->determineOutcome($action, $entryPrice, $atr, $forward);

            // R-multiple P&L: win=+2, loss=−1, breakeven=0
            $pl = match ($outcome) {
                'win'       => 2.0,
                'loss'      => -1.0,
                default     => 0.0,
            };
            $runningPl += $pl;
            $plCurve[]  = $runningPl;
            if ($runningPl > $peak) $peak = $runningPl;
            $dd = $peak - $runningPl;
            if ($dd > $maxDrawdown) $maxDrawdown = $dd;

            $trades[] = [
                'index'       => $i,
                'timestamp'   => $latest['timestamp'],
                'action'      => $action,
                'entry_price' => round($entryPrice, 5),
                'atr'         => round($atr, 5),
                'confidence'  => round($confidence, 1),
                'outcome'     => $outcome,
                'score'       => $score,
            ];
        }

        return $this->summarize($trades, $maxDrawdown, $days, $symbol);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function technicalScore(array $latest, array $prev): int
    {
        $score        = 0;
        $close        = (float) ($latest['close']     ?? 0.0);
        $sma20        = (float) ($latest['sma_20']    ?? 0.0);
        $sma50        = (float) ($latest['sma_50']    ?? 0.0);
        $rsi          = (float) ($latest['rsi_14']    ?? 50.0);
        $macdHist     = (float) ($latest['macd_hist'] ?? 0.0);
        $prevMacdHist = (float) ($prev['macd_hist']   ?? 0.0);

        if ($close > $sma20 && $sma20 > $sma50)      $score += 2;
        elseif ($close < $sma20 && $sma20 < $sma50)  $score -= 2;

        if ($rsi < 32)      $score += 1;
        elseif ($rsi > 68)  $score -= 1;

        if ($macdHist > 0 && $prevMacdHist <= 0)     $score += 1;
        elseif ($macdHist < 0 && $prevMacdHist >= 0) $score -= 1;

        return $score;
    }

    private function determineOutcome(
        string $action,
        float  $entry,
        float  $atr,
        array  $forward
    ): string {
        if (empty($forward)) {
            return 'unknown';
        }

        $target = $atr * 2.0;   // 2R take-profit
        $stop   = $atr * 1.2;   // 1.2×ATR stop

        foreach ($forward as $c) {
            $hi = (float) $c['high'];
            $lo = (float) $c['low'];
            if ($action === 'BUY') {
                if ($hi >= $entry + $target) return 'win';
                if ($lo <= $entry - $stop)   return 'loss';
            } else {
                if ($lo <= $entry - $target) return 'win';
                if ($hi >= $entry + $stop)   return 'loss';
            }
        }

        // Neither target nor stop hit — judge by final close
        $lastClose = (float) end($forward)['close'];
        $delta     = $lastClose - $entry;
        if ($action === 'BUY') {
            return $delta > $atr * 0.2 ? 'win' : ($delta < -$atr * 0.2 ? 'loss' : 'breakeven');
        }
        return $delta < -$atr * 0.2 ? 'win' : ($delta > $atr * 0.2 ? 'loss' : 'breakeven');
    }

    private function summarize(array $trades, float $maxDrawdown, int $days, string $symbol): array
    {
        $total      = \count($trades);
        $wins       = \count(array_filter($trades, fn($t) => $t['outcome'] === 'win'));
        $losses     = \count(array_filter($trades, fn($t) => $t['outcome'] === 'loss'));
        $breakevens = \count(array_filter($trades, fn($t) => $t['outcome'] === 'breakeven'));
        $base       = max(1, $total);
        $avgConf    = $total > 0
                      ? round(array_sum(array_column($trades, 'confidence')) / $total, 1)
                      : 0.0;

        $buys  = array_filter($trades, fn($t) => $t['action'] === 'BUY');
        $sells = array_filter($trades, fn($t) => $t['action'] === 'SELL');
        $buyWins  = \count(array_filter($buys,  fn($t) => $t['outcome'] === 'win'));
        $sellWins = \count(array_filter($sells, fn($t) => $t['outcome'] === 'win'));

        return [
            'symbol'           => $symbol,
            'days'             => $days,
            'total_signals'    => $total,
            'wins'             => $wins,
            'losses'           => $losses,
            'breakevens'       => $breakevens,
            'win_rate'         => $total > 0 ? round($wins  / $base * 100, 1) : 0.0,
            'loss_rate'        => $total > 0 ? round($losses / $base * 100, 1) : 0.0,
            'avg_confidence'   => $avgConf,
            'max_drawdown_r'   => round($maxDrawdown, 2),
            'buy_win_rate'     => \count($buys)  > 0 ? round($buyWins  / \count($buys)  * 100, 1) : 0.0,
            'sell_win_rate'    => \count($sells) > 0 ? round($sellWins / \count($sells) * 100, 1) : 0.0,
            'trades'           => $trades,
            'note'             => 'Simulated results only. No session filter applied. No financial advice.',
        ];
    }

    private function daysToRange(int $days): string
    {
        return match (true) {
            $days >= 85 => '3mo',
            $days >= 55 => '60d',
            default     => '30d',
        };
    }

    private function fetchCandles(string $symbol, string $timeframe, string $range): array
    {
        return $this->provider->fetchCandles($symbol, $timeframe, $range);
    }
}
