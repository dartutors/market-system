<?php
declare(strict_types=1);
namespace App\Services;

use DateTimeImmutable;

/**
 * Strict Entry Trigger Engine.
 *
 * Evaluates five conditions in priority order and returns one of four states:
 *
 *   WAIT  — Hard block: session closed, news event near, daily cap hit, or no trend.
 *   WATCH — Directional bias exists but confirmation is incomplete.
 *   READY — Setup is forming: session open, trend confirmed, awaiting momentum/news.
 *   ENTRY — ALL five conditions met; engine recommends BUY or SELL.
 *
 * This service never executes trades. Its output is informational only.
 * The confidence cap is 80% — certainty above that is never appropriate here.
 *
 * Input ($marketData keys):
 *   label          string   e.g. 'EUR/USD'
 *   signal_action  string   from SignalEngineService ('BUY','SELL','WAIT',...)
 *   signal_conf    float    0–100
 *   calendar_risk  ?array   from EconomicCalendarService::getActiveRiskWindow()
 *   ai_bias        string   from AiSummary ('bullish_usd','bearish_usd','mixed',...)
 *   ai_confidence  float    0–100
 *   today_count    int      total BUY/SELL signals already created today
 *   now            DateTimeImmutable
 */
final class EntrySignalService
{
    private const MAX_CONFIDENCE    = 80;
    private const MIN_CONF_FOR_ENTRY = 60.0; // signal_conf below this → READY at best

    // Directional news mapping: which AI bias supports which action for each pair
    private const NEWS_DIRECTION = [
        'EUR/USD' => [
            'bearish_usd' => 'BUY',  'bullish_usd' => 'SELL',
            'risk_on'     => 'BUY',  'risk_off'    => 'SELL',
        ],
        'GBP/USD' => [
            'bearish_usd' => 'BUY',  'bullish_usd' => 'SELL',
            'risk_on'     => 'BUY',  'risk_off'    => 'SELL',
        ],
        'USD/JPY' => [
            'bullish_usd' => 'BUY',  'bearish_usd' => 'SELL',
            'risk_on'     => 'BUY',  'risk_off'    => 'SELL',
        ],
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    public function evaluate(array $marketData): array
    {
        $label        = (string) ($marketData['label']         ?? '');
        $signalAction = strtoupper(trim((string) ($marketData['signal_action'] ?? 'WAIT')));
        $signalConf   = (float) ($marketData['signal_conf']    ?? 0.0);
        $calendarRisk = $marketData['calendar_risk']           ?? null;
        $aiBias       = strtolower(trim((string) ($marketData['ai_bias'] ?? 'mixed')));
        $aiConf       = (float) ($marketData['ai_confidence']  ?? 50.0);
        $todayCount   = (int) ($marketData['today_count']      ?? 0);
        $now          = $marketData['now'] instanceof DateTimeImmutable
                        ? $marketData['now'] : now_eat();

        $isActionable = \in_array($signalAction, ['BUY', 'SELL'], true);
        $isBuilding   = \in_array($signalAction, ['WATCH'], true);

        // ── Condition 1: Session ─────────────────────────────────────────────
        $session     = current_session_name($now);
        $sessionOpen = ($session !== 'off');

        // ── Condition 2: Risk clear (no high-impact event in window) ─────────
        $riskClear = ($calendarRisk === null);

        // ── Condition 3: Trend confirmed ─────────────────────────────────────
        // The signal engine uses SMA20 / SMA50 for trend; score ≥ 3 requires
        // SMA alignment (±2) plus at least one more indicator. If the engine
        // says BUY/SELL, trend is confirmed. If WATCH, trend is partially seen.
        $trendConfirmed = $isActionable || $isBuilding;

        // ── Condition 4: Momentum confirmed ──────────────────────────────────
        // Confidence ≥ MIN_CONF_FOR_ENTRY means the engine got RSI or MACD
        // confirmation on top of the SMA trend (score ≥ 3 → conf ≥ 77.5).
        $momentumConfirmed = $isActionable && $signalConf >= self::MIN_CONF_FOR_ENTRY;

        // ── Condition 5: News / AI alignment ─────────────────────────────────
        $newsDirection   = self::NEWS_DIRECTION[$label][$aiBias] ?? null;
        $newsAligned     = ($newsDirection !== null)
                           && ($isActionable ? ($newsDirection === $signalAction) : true)
                           && $aiConf >= 50.0;

        $conditions = [
            'session'        => $sessionOpen,
            'trend'          => $trendConfirmed,
            'momentum'       => $momentumConfirmed,
            'news_alignment' => $newsAligned,
            'risk_clear'     => $riskClear,
        ];

        $metCount = array_sum(array_map('intval', $conditions));

        // ── HARD BLOCKS — Daily cap ───────────────────────────────────────────
        $maxToday = max_signals_for_day($now);
        if ($todayCount >= $maxToday) {
            return $this->build(
                'WAIT', null, $label, 30, $conditions,
                "Daily limit of {$maxToday} high-quality trades reached. Discipline is part of the strategy.",
                'Daily limit reached — no more entries today.'
            );
        }

        // ── HARD BLOCK — Session ─────────────────────────────────────────────
        if (!$sessionOpen) {
            return $this->build(
                'WAIT', null, $label, 28, $conditions,
                'Market is outside the approved London (10:00–13:00) and New York (15:30–18:00) EAT windows. '
                . 'Entering outside sessions increases slippage and reduces edge.',
                'Outside trading session.'
            );
        }

        // ── HARD BLOCK — Calendar risk ────────────────────────────────────────
        if (!$riskClear) {
            $eventTitle = (string) ($calendarRisk['event_title'] ?? 'economic event');
            $currency   = (string) ($calendarRisk['currency']   ?? '');
            return $this->build(
                'WAIT', null, $label, 35, $conditions,
                "High-impact {$currency} event nearby: {$eventTitle}. "
                . 'Volatility spikes around releases can stop out otherwise valid setups. Wait for the dust to settle.',
                "High-impact event block: {$eventTitle}."
            );
        }

        // ── ENTRY — All five conditions met ───────────────────────────────────
        if ($metCount === 5 && $isActionable) {
            $action     = $signalAction;
            $confidence = $this->blendConfidence($signalConf, $aiConf, $metCount);
            return $this->build(
                'ENTRY', $action, $label, $confidence, $conditions,
                $this->entryNarrative($action, $label, $session, $aiBias, $aiConf),
                "All conditions met — {$action} setup confirmed."
            );
        }

        // ── READY — Session + risk clear + trend, momentum or news incomplete ─
        if ($sessionOpen && $riskClear && $trendConfirmed && $isActionable) {
            $action     = $signalAction;
            $confidence = max(45, (int) ($signalConf * 0.65 + $aiConf * 0.15));
            $missing    = $this->missingConditions($conditions);
            return $this->build(
                'READY', $action, $label,
                min(65, $confidence), $conditions,
                "Setup is forming for a {$action} on {$label}. "
                . "Session and trend are confirmed. Still waiting for: "
                . implode(', ', $missing) . '.',
                "Near-trigger: {$action} setup forming."
            );
        }

        // ── WATCH — Partial setup, bias exists ───────────────────────────────
        if ($trendConfirmed && $metCount >= 2) {
            $action = $isActionable ? $signalAction : ($newsDirection ?? null);
            return $this->build(
                'WATCH', $action, $label,
                min(55, 30 + $metCount * 5), $conditions,
                $this->watchNarrative($label, $signalAction, $aiBias, $conditions),
                'Bias developing — monitor for confirmation.'
            );
        }

        // ── WAIT — Default ────────────────────────────────────────────────────
        return $this->build(
            'WAIT', null, $label, 25, $conditions,
            'No clear directional setup at this time. '
            . 'Conditions are insufficient for even a monitoring stance. '
            . 'Patience now protects capital for a better opportunity.',
            'No setup — stand aside.'
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function build(
        string  $state,
        ?string $action,
        string  $label,
        int     $confidence,
        array   $conditions,
        string  $entryReason,
        string  $shortReason
    ): array {
        return [
            'state'        => $state,
            'action'       => $action,
            'pair'         => $label,
            'confidence'   => max(0, min(self::MAX_CONFIDENCE, $confidence)),
            'entry_reason' => $entryReason,
            'short_reason' => $shortReason,
            'conditions'   => $conditions,
        ];
    }

    private function blendConfidence(float $signalConf, float $aiConf, int $metCount): int
    {
        // Weighted blend: 60% signal, 25% AI, 15% conditions completeness
        $blend = ($signalConf * 0.60) + ($aiConf * 0.25) + ($metCount / 5 * 100 * 0.15);
        return (int) min(self::MAX_CONFIDENCE, max(50, $blend));
    }

    private function missingConditions(array $conditions): array
    {
        $labels = [
            'momentum'       => 'momentum confirmation',
            'news_alignment' => 'news alignment',
            'trend'          => 'trend confirmation',
            'session'        => 'active session',
            'risk_clear'     => 'event-risk clearance',
        ];
        $missing = [];
        foreach ($conditions as $key => $met) {
            if (!$met && isset($labels[$key])) {
                $missing[] = $labels[$key];
            }
        }
        return $missing;
    }

    private function entryNarrative(
        string $action,
        string $label,
        string $session,
        string $aiBias,
        float  $aiConf
    ): string {
        $dir         = $action === 'BUY' ? 'bullish' : 'bearish';
        $usdDir      = $action === 'BUY' ? 'weakening' : 'strengthening';
        $sessionName = $session === 'london' ? 'London' : 'New York';

        return "USD {$usdDir} confirmed by AI news analysis ({$aiConf}% confidence). "
             . "{$sessionName} session is active — the highest-liquidity window. "
             . "Technical indicators show a {$dir} structure on {$label}: "
             . "price above key moving averages (trend), RSI and MACD aligned with direction (momentum). "
             . "No high-impact events blocking entry. "
             . "This is an educational observation only — always confirm with your own analysis "
             . "and use strict risk management (1–2% per trade maximum).";
    }

    private function watchNarrative(
        string $label,
        string $signalAction,
        string $aiBias,
        array  $conditions
    ): string {
        $biasDesc  = match ($aiBias) {
            'bullish_usd' => 'USD bullish bias from news',
            'bearish_usd' => 'USD bearish bias from news',
            'risk_off'    => 'risk-off sentiment from news',
            'risk_on'     => 'risk-on sentiment from news',
            default       => 'mixed news sentiment',
        };
        $missing = $this->missingConditions($conditions);
        $waitFor = !empty($missing) ? 'Waiting for: ' . implode(', ', $missing) . '.' : '';

        return "A directional lean is emerging on {$label} ({$biasDesc}). "
             . "The technical signal from the engine is {$signalAction}. "
             . "{$waitFor} "
             . "Do not enter until all conditions align. Monitor closely.";
    }
}
