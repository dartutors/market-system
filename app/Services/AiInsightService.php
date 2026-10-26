<?php
declare(strict_types=1);
namespace App\Services;

use App\AI\AIManager;
use App\AI\PromptLoader;
use App\Config\AI as AIConfig;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Unified AI Insight Layer.
 *
 * Combines backtest performance stats, economic calendar risk, and news
 * sentiment into a single beginner-friendly decision-support output.
 *
 * Priority order (highest first):
 *   1. Economic calendar  — hard WAIT if high-impact event is near
 *   2. Backtest/stats     — WATCH if data is thin or win rate is poor
 *   3. News/AI sentiment  — directional bias when conditions allow
 *
 * One AI call maximum per cycle; falls back to rule-based logic if AI
 * is disabled, key is missing, or the call fails.
 *
 * Cached in storage/cache/ai_insight.json for AI_SUMMARY_CACHE_MINUTES.
 */
final class AiInsightService
{
    private const CACHE_FILE        = 'ai_insight.json';
    private const HOURS_AHEAD_WARN  = 4;    // warn about events within this many hours
    private const MIN_SIGNALS       = 10;   // fewer signals = LOW RELIABILITY
    private const GOOD_WIN_RATE     = 55.0; // below this = WATCH territory
    private const POOR_WIN_RATE     = 45.0; // below this = WAIT / poor backtest

    private const VALID_DECISIONS = ['WAIT', 'WATCH', 'BULLISH_BIAS', 'BEARISH_BIAS'];
    private const VALID_RISKS     = ['low', 'medium', 'high'];

    // System/user prompt text now lives in resources/prompts/trade_decision.md
    // (loaded via App\AI\PromptLoader) — see Phase 7 of the foundation refactor.

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Generates a unified market insight.
     *
     * @param array $backtest       BacktestService::run() result OR SignalEvent::stats()
     *                              (both are normalised internally).
     * @param array $calendarEvents EconomicCalendarService::getUpcomingEvents() output.
     * @param array $newsSummary    AiSummaryService::summarize() / AiSummary::latest(1)[0].
     * @return array{decision:string, confidence:int, summary:string,
     *               key_points:array<string>, risk_level:string, provider:string}
     */
    public function generate(array $backtest, array $calendarEvents, array $newsSummary): array
    {
        // ── 1. Return from file cache if still fresh ──────────────────────────
        $cached = $this->loadCache();
        if ($cached !== null) {
            return $cached;
        }

        // ── 2. Try AI-enhanced insight (single call, optional) ────────────────
        $result = null;
        if ((bool) env('USE_AI_SUMMARY', false)) {
            $result = $this->callAi($backtest, $calendarEvents, $newsSummary);
        }

        // ── 3. Rule-based fallback ────────────────────────────────────────────
        if ($result === null) {
            $result = $this->ruleBasedInsight($backtest, $calendarEvents, $newsSummary);
        }

        // ── 4. Persist to file cache ──────────────────────────────────────────
        $this->writeCache($result);

        return $result;
    }

    // ── Rule-based logic ──────────────────────────────────────────────────────

    private function ruleBasedInsight(
        array $backtest,
        array $calendarEvents,
        array $newsSummary
    ): array {
        $bt        = $this->normaliseBacktest($backtest);
        $keyPoints = [];
        $decision  = 'WAIT';
        $riskLevel = 'medium';
        $confidence = 45;

        // ── Priority 1: Economic calendar ────────────────────────────────────
        $nearbyEvent = $this->findNearbyHighImpactEvent($calendarEvents);
        if ($nearbyEvent !== null) {
            $title     = (string) ($nearbyEvent['title']    ?? 'Unknown Event');
            $currency  = (string) ($nearbyEvent['currency'] ?? '');
            $keyPoints[] = "High-impact {$currency} event nearby: {$title}";
            $keyPoints[] = 'Avoid new entries until after the release and initial reaction.';

            return $this->buildResult(
                'WAIT', 88, 'high',
                'A high-impact economic event is imminent. Market volatility is expected '
                . 'to spike around the release. Standing aside is the safest approach.',
                $keyPoints, 'rule_based'
            );
        }

        // ── Priority 2: Backtest / performance reliability ───────────────────
        $totalSignals = $bt['total_signals'];
        $winRate      = $bt['win_rate'];
        $maxDrawdown  = $bt['max_drawdown_r'];

        if ($totalSignals < self::MIN_SIGNALS) {
            $keyPoints[] = "Insufficient signal history ({$totalSignals} signals). Need at least " . self::MIN_SIGNALS . '.';
            $keyPoints[] = 'System reliability cannot be assessed with this sample size.';
            $decision    = 'WATCH';
            $riskLevel   = 'medium';
            $confidence  = 38;
        } elseif ($winRate < self::POOR_WIN_RATE) {
            $keyPoints[] = "Backtest win rate is poor: {$winRate}% (target: ≥ " . self::GOOD_WIN_RATE . "%).";
            $keyPoints[] = 'Current signal setup shows negative expected value.';
            $decision    = 'WAIT';
            $riskLevel   = 'high';
            $confidence  = 70;
        } elseif ($winRate < self::GOOD_WIN_RATE) {
            $keyPoints[] = "Backtest win rate is below target: {$winRate}% (target: ≥ " . self::GOOD_WIN_RATE . "%).";
            $decision    = 'WATCH';
            $riskLevel   = 'medium';
            $confidence  = 50;
        } else {
            // ── Priority 3: News / sentiment (only when backtest is OK) ──────
            $bias     = strtolower(trim((string) ($newsSummary['market_bias'] ?? 'mixed')));
            $newsConf = (float) ($newsSummary['confidence'] ?? 50.0);
            $summary  = (string) ($newsSummary['summary_text'] ?? '');

            $blendedConf = (int) min(78, ($winRate * 0.55 + $newsConf * 0.45));

            // Overconfidence guard: high calculated conf + borderline win rate
            if ($blendedConf > 75 && $winRate < 62) {
                $blendedConf = 65;
                $keyPoints[] = 'Overconfidence detected: reducing confidence to match backtest evidence.';
            }

            if ($bias === 'bullish_usd' && $newsConf >= 55) {
                // Bullish USD = bearish EUR/USD
                $decision   = 'BEARISH_BIAS';
                $riskLevel  = $newsConf >= 65 ? 'low' : 'medium';
                $confidence = $blendedConf;
                $keyPoints[] = 'News sentiment supports USD strength (bearish for EUR/USD, GBP/USD).';
            } elseif ($bias === 'bearish_usd' && $newsConf >= 55) {
                // Bearish USD = bullish EUR/USD
                $decision   = 'BULLISH_BIAS';
                $riskLevel  = $newsConf >= 65 ? 'low' : 'medium';
                $confidence = $blendedConf;
                $keyPoints[] = 'News sentiment supports USD weakness (bullish for EUR/USD, GBP/USD).';
            } elseif ($bias === 'risk_off') {
                $decision   = 'WAIT';
                $riskLevel  = 'high';
                $confidence = 72;
                $keyPoints[] = 'Risk-off sentiment detected — markets may be risk-averse.';
            } elseif ($bias === 'risk_on') {
                $decision   = 'WATCH';
                $riskLevel  = 'low';
                $confidence = $blendedConf;
                $keyPoints[] = 'Risk-on environment — watch for technical entry confirmation.';
            } else {
                $decision   = 'WAIT';
                $riskLevel  = 'medium';
                $confidence = 48;
                $keyPoints[] = 'News sentiment is mixed or neutral — no directional edge from news.';
            }

            if ($winRate >= self::GOOD_WIN_RATE) {
                $keyPoints[] = "Historical win rate is acceptable: {$winRate}% over {$bt['days']} days.";
            }

            if ($summary !== '') {
                $keyPoints[] = 'AI news insight: ' . mb_substr($summary, 0, 100) . '…';
            }
        }

        // ── Max drawdown warning ──────────────────────────────────────────────
        if ($maxDrawdown > 4.0) {
            $keyPoints[] = "Elevated historical drawdown: {$maxDrawdown}R — manage position size carefully.";
            if ($riskLevel === 'low') {
                $riskLevel = 'medium';
            }
        }

        return $this->buildResult(
            $decision, $confidence, $riskLevel,
            $this->generateSummary($decision, $riskLevel, $keyPoints),
            $keyPoints, 'rule_based'
        );
    }

    // ── AI call ───────────────────────────────────────────────────────────────

    private function callAi(
        array $backtest,
        array $calendarEvents,
        array $newsSummary
    ): ?array {
        // Previously: pick Claude if configured, else OpenAI, with no
        // cross-provider fallback if that single choice failed — meaning a
        // transient Claude outage dropped straight to the rule-based summary
        // even though a configured, working OpenAI key sat unused. Phase 20
        // certification: switched to AIManager::complete(), which is exactly
        // AIConfig's own primary/fallback chain already used by every other
        // AI service in this app — strictly additive (only ever adds a
        // second attempt before falling back to rule-based, never removes
        // that safety net).
        if (AIConfig::claudeApiKey() === '' && AIConfig::openAiApiKey() === '') {
            return null;
        }

        $prompt   = $this->buildPrompt($backtest, $calendarEvents, $newsSummary);
        $response = (new AIManager())->complete(
            $prompt['system'],
            $prompt['user'],
            ['service' => 'ai_insight']
        );

        if ($response === null) {
            return null;
        }

        return $this->parseAiResponse($response->text, $response->provider);
    }

    /**
     * @return array{system:string, user:string}
     */
    private function buildPrompt(array $backtest, array $calendarEvents, array $newsSummary): array
    {
        $bt = $this->normaliseBacktest($backtest);

        // Calendar summary
        $calLines = '';
        $nearby   = $this->findNearbyHighImpactEvent($calendarEvents);
        if ($nearby !== null) {
            $calLines = "⚠ HIGH-IMPACT EVENT WITHIN 4 HOURS: {$nearby['title']} ({$nearby['currency']})\n";
        }
        foreach (array_slice($calendarEvents, 0, 5) as $ev) {
            $calLines .= "- [{$ev['impact']}] {$ev['title']} ({$ev['currency']}) at {$ev['scheduled_at']}\n";
        }
        if ($calLines === '') {
            $calLines = "No high-impact events in the next 7 days.\n";
        }

        // News summary
        $bias     = strtoupper((string) ($newsSummary['market_bias'] ?? 'MIXED'));
        $newsConf = (float) ($newsSummary['confidence'] ?? 50);
        $newsTxt  = (string) ($newsSummary['summary_text'] ?? '');

        // Opportunistic fix while this literal was already being touched:
        // the pre-refactor prompt hardcoded "EUR/USD, GBP/USD, USD/JPY" here
        // (one of the two hardcoded-3-pair spots flagged by the prior
        // architecture audit). configured_markets() produces the identical
        // string today, and removes the hardcoding for Phase 2's multi-market
        // expansion — zero behavior change now, one less blocker later.
        $trackedMarkets = implode(', ', array_column(configured_markets(), 'label'));

        return PromptLoader::load('trade_decision', [
            'win_rate'           => $bt['win_rate'],
            'total_signals'      => $bt['total_signals'],
            'max_drawdown_r'     => $bt['max_drawdown_r'],
            'days'               => $bt['days'],
            'calendar_lines'     => $calLines,
            'news_bias'          => $bias,
            'news_confidence'    => $newsConf,
            'news_summary_text'  => $newsTxt,
            'tracked_markets'    => $trackedMarkets,
        ]);
    }

    private function parseAiResponse(string $text, string $provider): ?array
    {
        $text = trim((string) preg_replace('/^```(?:json)?\s*/i', '', $text));
        $text = trim((string) preg_replace('/\s*```\s*$/i', '', $text));

        $data = json_decode($text, true);
        if (!is_array($data)) {
            error_log("[AiInsightService] {$provider} returned unparseable JSON.");
            return null;
        }

        $decision = strtoupper(trim((string) ($data['decision'] ?? 'WAIT')));
        if (!\in_array($decision, self::VALID_DECISIONS, true)) {
            $decision = 'WAIT';
        }

        $riskLevel = strtolower(trim((string) ($data['risk_level'] ?? 'medium')));
        if (!\in_array($riskLevel, self::VALID_RISKS, true)) {
            $riskLevel = 'medium';
        }

        $confidence = (int) max(0, min(100, (int) ($data['confidence'] ?? 50)));
        $summary    = trim((string) ($data['summary'] ?? ''));
        if ($summary === '') {
            return null;
        }

        $keyPoints = [];
        if (isset($data['key_points']) && is_array($data['key_points'])) {
            foreach ($data['key_points'] as $kp) {
                $keyPoints[] = trim((string) $kp);
            }
        }

        return $this->buildResult($decision, $confidence, $riskLevel, $summary, $keyPoints, $provider);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Normalises either BacktestService::run() or SignalEvent::stats() into
     * a common format used by this service.
     */
    private function normaliseBacktest(array $data): array
    {
        return [
            'total_signals'  => (int)   ($data['total_signals'] ?? $data['total']          ?? 0),
            'win_rate'       => (float) ($data['win_rate']                                  ?? 0.0),
            'loss_rate'      => (float) ($data['loss_rate']                                 ?? 0.0),
            'max_drawdown_r' => (float) ($data['max_drawdown_r']                            ?? 0.0),
            'days'           => (int)   ($data['days']          ?? $data['with_outcome']    ?? 0),
            'avg_confidence' => (float) ($data['avg_confidence']                            ?? 0.0),
        ];
    }

    private function findNearbyHighImpactEvent(array $calendarEvents): ?array
    {
        $tz     = new DateTimeZone((string) env('APP_TIMEZONE', 'Africa/Dar_es_Salaam'));
        $now    = new DateTimeImmutable('now', $tz);
        $cutoff = $now->modify('+' . self::HOURS_AHEAD_WARN . ' hours');

        foreach ($calendarEvents as $event) {
            if (strtolower((string) ($event['impact'] ?? '')) !== 'high') {
                continue;
            }

            try {
                $eventTime = new DateTimeImmutable((string) $event['scheduled_at'], $tz);
            } catch (\Throwable) {
                continue;
            }

            if ($eventTime >= $now && $eventTime <= $cutoff) {
                return $event;
            }
        }

        return null;
    }

    private function generateSummary(string $decision, string $riskLevel, array $keyPoints): string
    {
        $lead = match ($decision) {
            'BULLISH_BIAS'  => 'Combined analysis suggests a bullish lean.',
            'BEARISH_BIAS'  => 'Combined analysis suggests a bearish lean.',
            'WATCH'         => 'Conditions are developing but not yet confirmed.',
            default         => 'Conditions are unclear or unfavourable at this time.',
        };

        $tail = match ($riskLevel) {
            'high'   => ' Stand aside until risk factors subside.',
            'low'    => ' Wait for a technical entry signal to confirm.',
            default  => ' Monitor for confirmation before considering any position.',
        };

        return $lead . $tail
             . ' Always use proper risk management and paper-trade before using real money.';
    }

    private function buildResult(
        string $decision,
        int    $confidence,
        string $riskLevel,
        string $summary,
        array  $keyPoints,
        string $provider
    ): array {
        return [
            'decision'     => $decision,
            'confidence'   => max(0, min(100, $confidence)),
            'summary'      => $summary,
            'key_points'   => array_values(array_slice($keyPoints, 0, 5)),
            'risk_level'   => $riskLevel,
            'provider'     => $provider,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    // ── File cache ────────────────────────────────────────────────────────────

    private function cacheFilePath(): string
    {
        $dir = base_path('storage/cache');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir . DIRECTORY_SEPARATOR . self::CACHE_FILE;
    }

    private function loadCache(): ?array
    {
        $path = $this->cacheFilePath();
        if (!file_exists($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['generated_at'], $data['insight'])) {
            return null;
        }

        $ttl = max(60, (int) env('AI_SUMMARY_CACHE_MINUTES', 30) * 60);
        if ((time() - (int) strtotime((string) $data['generated_at'])) > $ttl) {
            return null;
        }

        return is_array($data['insight']) ? $data['insight'] : null;
    }

    private function writeCache(array $insight): void
    {
        $payload = json_encode([
            'generated_at' => date('Y-m-d H:i:s'),
            'insight'      => $insight,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($payload !== false) {
            @file_put_contents($this->cacheFilePath(), $payload, LOCK_EX);
        }
    }
}
