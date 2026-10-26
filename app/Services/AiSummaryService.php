<?php
declare(strict_types=1);
namespace App\Services;

use App\AI\AIManager;
use App\AI\PromptLoader;
use App\Models\AiSummary;

/**
 * Generates educational AI summaries of forex market headlines.
 *
 * Call order: cache → Claude → OpenAI → rule-based fallback.
 * One API call maximum per scan cycle. Never called on page load.
 */
final class AiSummaryService
{
    private const VALID_BIASES = [
        'bullish_usd', 'bearish_usd', 'mixed', 'risk_off', 'risk_on', 'neutral',
    ];

    // System/user prompt text now lives in resources/prompts/market_summary.md
    // and daily_report.md (loaded via App\AI\PromptLoader) — see Phase 7 of
    // the foundation refactor.

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Summarizes news headlines with optional technical scan context.
     *
     * @param  array<int, array<string,mixed>> $headlines     Normalized news articles
     * @param  array<int, array<string,mixed>> $marketResults Per-market scan result rows
     * @return array{provider:string, model:string, summary_text:string,
     *              market_bias:string, confidence:float, headline_hash:string,
     *              pair_notes:array<string,string>, risk_note:string}
     */
    public function summarize(array $headlines, array $marketResults = []): array
    {
        if (empty($headlines)) {
            return $this->ruleBasedSummary([]);
        }

        $hash = $this->buildHeadlineHash($headlines);

        // ── 1. Check DB cache first ───────────────────────────────────────────
        $cacheMinutes = max(1, (int) env('AI_SUMMARY_CACHE_MINUTES', 30));
        $cached = AiSummary::findRecentByHash($hash, $cacheMinutes);
        if ($cached !== null) {
            return [
                'provider'      => 'cache',
                'model'         => (string) $cached['model'],
                'summary_text'  => (string) $cached['summary_text'],
                'market_bias'   => (string) $cached['market_bias'],
                'confidence'    => (float)  $cached['confidence'],
                'headline_hash' => $hash,
                'pair_notes'    => (array)  $cached['pair_notes'],
                'risk_note'     => (string) $cached['risk_note'],
            ];
        }

        // ── 2. Attempt AI providers ───────────────────────────────────────────
        $result = null;

        if ((bool) env('USE_AI_SUMMARY', false)) {
            $prompt = $this->buildPrompt($headlines, $marketResults);

            $primary  = strtolower(trim((string) env('AI_PRIMARY_PROVIDER',  'claude')));
            $fallback = strtolower(trim((string) env('AI_FALLBACK_PROVIDER', 'openai')));

            $result = $this->callProvider($primary, $prompt)
                   ?? $this->callProvider($fallback, $prompt);
        }

        // ── 3. Rule-based fallback ────────────────────────────────────────────
        if ($result === null) {
            $result = $this->ruleBasedSummary($headlines);
        }

        $result['headline_hash'] = $hash;

        // ── 4. Persist to DB ──────────────────────────────────────────────────
        try {
            AiSummary::create([
                'provider'      => $result['provider'],
                'model'         => $result['model'],
                'market_scope'  => 'ALL',
                'summary_text'  => $result['summary_text'],
                'market_bias'   => $result['market_bias'],
                'confidence'    => $result['confidence'],
                'headline_hash' => $hash,
                'pair_notes'    => $result['pair_notes'],
                'risk_note'     => $result['risk_note'],
            ]);
        } catch (\Throwable $e) {
            error_log('[AiSummaryService] DB save failed: ' . $e->getMessage());
        }

        return $result;
    }

    // ── Prompt building ───────────────────────────────────────────────────────

    /**
     * @return array{system:string, user:string}
     */
    private function buildPrompt(array $headlines, array $marketResults): array
    {
        $markets    = configured_markets();
        $marketList = implode(', ', array_column($markets, 'label'));
        $maxItems   = max(1, (int) env('AI_MAX_HEADLINES', 8));
        $slice      = array_slice($headlines, 0, $maxItems);

        $headlineBlock = '';
        foreach ($slice as $i => $h) {
            $title  = $this->sanitizeHeadlineForPrompt((string) ($h['title'] ?? ''));
            $source = $this->sanitizeHeadlineForPrompt((string) ($h['source'] ?? ''));
            $suffix = $source !== '' ? " [{$source}]" : '';
            $headlineBlock .= ($i + 1) . '. ' . $title . $suffix . "\n";
        }

        $scanBlock = '';
        if (!empty($marketResults)) {
            $scanBlock = "\nTECHNICAL SCAN RESULTS:\n";
            foreach ($marketResults as $r) {
                $label  = (string) ($r['market']  ?? '');
                $action = (string) ($r['action']  ?? 'WAIT');
                $conf   = isset($r['confidence']) ? " ({$r['confidence']}%)" : '';
                $detail = (string) ($r['details'] ?? $r['message'] ?? '');
                $detail = $detail !== '' ? ' — ' . mb_substr($detail, 0, 100) : '';
                $scanBlock .= "• {$label}: {$action}{$conf}{$detail}\n";
            }
        }

        return PromptLoader::load('market_summary', [
            'market_list'    => $marketList,
            'headline_block' => $headlineBlock,
            'scan_block'     => $scanBlock,
        ]);
    }

    // ── Provider dispatcher ───────────────────────────────────────────────────

    /**
     * Routes to a single named provider via the AI Manager — no automatic
     * fallback to a different provider here; summarize() already tries
     * primary then fallback by calling this twice, exactly as before.
     */
    /**
     * @param array{system:string, user:string} $prompt
     */
    private function callProvider(string $provider, array $prompt): ?array
    {
        $response = (new AIManager())->generateWith(
            $provider,
            $prompt['system'],
            $prompt['user'],
            ['service' => 'ai_summary']
        );

        if ($response === null) {
            return null;
        }

        return $this->parseAiJson($response->text, $response->model, $response->provider);
    }

    // ── JSON parser ───────────────────────────────────────────────────────────

    private function parseAiJson(string $text, string $model, string $provider): ?array
    {
        $text = trim($text);

        // Strip markdown code fences if the model wrapped the JSON
        $text = (string) preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = (string) preg_replace('/\s*```\s*$/i', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);

        if (!is_array($data)) {
            error_log("[AiSummaryService] {$provider} returned unparseable JSON: "
                . mb_substr($text, 0, 300));
            return null;
        }

        $bias = strtolower(trim((string) ($data['market_bias'] ?? 'mixed')));
        if (!\in_array($bias, self::VALID_BIASES, true)) {
            $bias = 'mixed';
        }

        $confidence = (float) ($data['confidence'] ?? 50.0);
        $confidence = max(0.0, min(100.0, $confidence));

        $summary = trim((string) ($data['summary_text'] ?? ''));
        if ($summary === '') {
            error_log("[AiSummaryService] {$provider} returned empty summary_text.");
            return null;
        }

        $pairNotes = [];
        if (isset($data['pair_notes']) && is_array($data['pair_notes'])) {
            foreach ($data['pair_notes'] as $pair => $note) {
                $pairNotes[trim((string) $pair)] = trim((string) $note);
            }
        }

        return [
            'provider'     => $provider,
            'model'        => $model,
            'summary_text' => $summary,
            'market_bias'  => $bias,
            'confidence'   => round($confidence, 2),
            'pair_notes'   => $pairNotes,
            'risk_note'    => trim((string) ($data['risk_note'] ?? '')),
        ];
    }

    // ── Rule-based fallback ───────────────────────────────────────────────────

    private function ruleBasedSummary(array $headlines): array
    {
        $bullish = 0;
        $bearish = 0;
        $neutral = 0;

        foreach ($headlines as $h) {
            $bias     = strtolower((string) ($h['market_bias'] ?? 'neutral'));
            $polarity = (float) ($h['raw_sentiment'] ?? 0.0);

            if ($polarity > 0 || $bias === 'bullish') {
                $bullish++;
            } elseif ($polarity < 0 || $bias === 'bearish') {
                $bearish++;
            } else {
                $neutral++;
            }
        }

        $total  = max(1, $bullish + $bearish + $neutral);
        $ratio  = static fn(int $n): float => $n / $total;

        if ($bullish > $bearish * 1.5) {
            $bias       = 'bullish_usd';
            $confidence = round(min(62.0, 44.0 + $ratio($bullish) * 28.0), 2);
            $summary    = 'Market headlines lean broadly positive, suggesting potential USD or '
                        . 'risk-asset strength. News sentiment alone is not sufficient for trade '
                        . 'decisions — wait for technical confirmation during the London or New '
                        . 'York session before forming a directional bias.';
        } elseif ($bearish > $bullish * 1.5) {
            $bias       = 'bearish_usd';
            $confidence = round(min(62.0, 44.0 + $ratio($bearish) * 28.0), 2);
            $summary    = 'Market headlines lean broadly negative, suggesting potential USD '
                        . 'weakness or risk-off sentiment. This does not confirm a trade signal '
                        . 'by itself. Wait for technical alignment during an approved session '
                        . 'window before making any assessment.';
        } else {
            $bias       = 'mixed';
            $confidence = round(min(52.0, 36.0 + $ratio($neutral) * 14.0), 2);
            $summary    = 'Market news is mixed with no clear directional consensus. Some '
                        . 'headlines support USD strength while others support EUR or GBP. '
                        . 'The safer interpretation is WAIT until technical confirmation '
                        . 'appears during the London or New York session.';
        }

        return [
            'provider'     => 'rule_based',
            'model'        => 'rule_based_v1',
            'summary_text' => $summary,
            'market_bias'  => $bias,
            'confidence'   => $confidence,
            'pair_notes'   => [],
            'risk_note'    => 'Rule-based analysis only — no AI was used. '
                            . 'Informational only. New traders should paper trade first.',
        ];
    }

    // ── Daily report ─────────────────────────────────────────────────────────

    /**
     * Generates an end-of-day performance + learning report.
     * Called once per day from cron (around 20:00 EAT).
     * Returns plain-text report string, or empty string on failure.
     */
    public function dailyReport(): string
    {
        // Build context from today's signals
        $todaySignals = \App\Models\SignalEvent::todaySignals();
        $stats        = \App\Models\SignalEvent::stats();
        $signalLines  = '';

        foreach ($todaySignals as $sig) {
            $outcome      = $sig['outcome'] ?? 'pending';
            $signalLines .= "- {$sig['market_label']}: {$sig['action']} ({$sig['confidence']}%) — outcome: {$outcome}\n";
        }

        if ($signalLines === '') {
            $signalLines = "- No signals generated today.\n";
        }

        $apiKey = trim((string) env('CLAUDE_API_KEY', ''));
        if ($apiKey === '' && trim((string) env('OPENAI_API_KEY', '')) === '') {
            return $this->ruleBasedDailyReport($todaySignals, $stats);
        }

        $useAi = (bool) env('USE_AI_SUMMARY', false);
        if (!$useAi) {
            return $this->ruleBasedDailyReport($todaySignals, $stats);
        }

        $prompt = PromptLoader::load('daily_report', [
            'date'         => date('Y-m-d'),
            'signal_lines' => $signalLines,
            'total'        => $stats['total'],
            'win_rate'     => $stats['win_rate'],
            'loss_rate'    => $stats['loss_rate'],
            'best_market'  => $stats['best_market'],
        ]);

        $primary  = strtolower(trim((string) env('AI_PRIMARY_PROVIDER',  'claude')));
        $fallback = strtolower(trim((string) env('AI_FALLBACK_PROVIDER', 'openai')));

        // Reuse the same provider clients but with the plain-text report prompt
        $result = $this->callProviderForReport($primary, $prompt)
               ?? $this->callProviderForReport($fallback, $prompt);

        return $result ?? $this->ruleBasedDailyReport($todaySignals, $stats);
    }

    /**
     * @param array{system:string, user:string} $prompt
     */
    private function callProviderForReport(string $provider, array $prompt): ?string
    {
        // json_mode=false: the daily report is a plain-text narrative, not
        // JSON — the pre-refactor code never set response_format here either.
        $response = (new AIManager())->generateWith(
            $provider,
            $prompt['system'],
            $prompt['user'],
            ['service' => 'ai_summary_daily_report', 'json_mode' => false]
        );

        return $response !== null ? (trim($response->text) ?: null) : null;
    }

    private function ruleBasedDailyReport(array $todaySignals, array $stats): string
    {
        $count   = \count($todaySignals);
        $wins    = $stats['wins'];
        $wr      = $stats['win_rate'];
        $best    = $stats['best_market'];
        $tip     = $wr >= 60
            ? 'Your win rate is solid. Focus on maintaining discipline and avoiding overtrading.'
            : 'Win rate is developing. Consider paper-trading signals before using real money.';

        return "Daily Summary — " . date('Y-m-d') . "\n\n"
            . "Signals today: {$count}. "
            . "All-time win rate: {$wr}% (best pair: {$best}).\n\n"
            . "Learning tip: {$tip}\n\n"
            . "This is an automated rule-based summary. No financial advice.";
    }

    // ── Prompt injection defence ──────────────────────────────────────────────

    /**
     * Strips newlines, control characters, and truncates headline text before
     * it is embedded in the AI prompt. Prevents multi-line instruction injection.
     */
    private function sanitizeHeadlineForPrompt(string $text): string
    {
        // Strip all newlines and control characters that could break prompt structure
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
        // Collapse multiple spaces
        $text = (string) preg_replace('/\s{2,}/', ' ', $text);
        // Truncate to 150 chars — enough for any legitimate headline
        return mb_substr(trim($text), 0, 150);
    }

    // ── Hash helper ───────────────────────────────────────────────────────────

    private function buildHeadlineHash(array $headlines): string
    {
        $titles = array_map(
            static fn(array $h): string => mb_strtolower(trim((string) ($h['title'] ?? ''))),
            $headlines
        );
        sort($titles);
        return hash('sha256', implode('|', $titles));
    }
}
