<?php
declare(strict_types=1);
namespace App\TradeJournal;

use App\AI\AIManager;
use App\AI\PromptLoader;
use App\Models\TradeJournal;

/**
 * AI coaching report over the trader's own logged history
 * (TradeJournal::stats()) — same cache-less, on-demand, primary→fallback→
 * rule-based pattern as App\MultiTimeframe\MultiTimeframeAiSummaryService
 * and App\TradeCoach\TradeCoachAiService. Never recomputes the stats
 * themselves, only narrates them.
 */
final class JournalCoachingService
{
    /**
     * @return array{provider:string, model:string, narrative:string, focus_area:string, risk_note:string}
     */
    public function report(): array
    {
        $stats = TradeJournal::stats();

        if ($stats['closed_count'] === 0) {
            return [
                'provider'   => 'rule_based',
                'model'      => 'rule_based_v1',
                'narrative'  => 'No closed trades logged yet — coaching insights need at least a few closed trades to '
                              . 'find real patterns. Log and close out a few trades, then check back here.',
                'focus_area' => 'Log your next few trades, including the outcome, so patterns have something to work with.',
                'risk_note'  => 'Informational only — not financial advice.',
            ];
        }

        if (!(bool) env('USE_AI_SUMMARY', false)) {
            return $this->ruleBasedReport($stats);
        }

        $prompt = $this->buildPrompt($stats);

        $primary  = strtolower(trim((string) env('AI_PRIMARY_PROVIDER',  'claude')));
        $fallback = strtolower(trim((string) env('AI_FALLBACK_PROVIDER', 'openai')));

        $result = $this->callProvider($primary, $prompt)
               ?? $this->callProvider($fallback, $prompt);

        return $result ?? $this->ruleBasedReport($stats);
    }

    /** @return array{system:string, user:string} */
    private function buildPrompt(array $stats): array
    {
        $byMarketBlock = '';
        foreach ($stats['by_market'] as $m) {
            $wr = $m['total'] > 0 ? round(((int) $m['wins']) / max(1, (int) $m['total']) * 100, 1) : 0.0;
            $byMarketBlock .= "• {$m['market_label']}: {$m['total']} trades, {$wr}% win rate, P/L {$m['total_profit_loss']}\n";
        }
        if ($byMarketBlock === '') {
            $byMarketBlock = "No pair data yet.\n";
        }

        $bySessionBlock = '';
        foreach ($stats['by_session'] as $s) {
            $wr = $s['total'] > 0 ? round(((int) $s['wins']) / max(1, (int) $s['total']) * 100, 1) : 0.0;
            $bySessionBlock .= "• {$s['session_name']}: {$s['total']} trades, {$wr}% win rate, P/L {$s['total_profit_loss']}\n";
        }
        if ($bySessionBlock === '') {
            $bySessionBlock = "No session data yet.\n";
        }

        $mistakeBlock = '';
        $top = array_slice($stats['mistake_tag_counts'], 0, 5, true);
        foreach ($top as $tag => $count) {
            $mistakeBlock .= "• " . MistakeTagCatalog::label((string) $tag) . ": logged {$count} time(s)\n";
        }
        if ($mistakeBlock === '') {
            $mistakeBlock = "No mistakes tagged yet.\n";
        }

        return PromptLoader::load('journal_coaching_report', [
            'closed_count'      => (string) $stats['closed_count'],
            'total_count'       => (string) $stats['total'],
            'open_count'        => (string) $stats['open_count'],
            'win_rate'          => (string) $stats['win_rate'],
            'wins'              => (string) $stats['wins'],
            'losses'            => (string) $stats['losses'],
            'breakevens'        => (string) $stats['breakevens'],
            'total_profit_loss' => (string) $stats['total_profit_loss'],
            'avg_profit_loss'   => (string) $stats['avg_profit_loss'],
            'by_market_block'   => $byMarketBlock,
            'by_session_block'  => $bySessionBlock,
            'mistake_block'     => $mistakeBlock,
        ]);
    }

    /** @param array{system:string, user:string} $prompt */
    private function callProvider(string $provider, array $prompt): ?array
    {
        $response = (new AIManager())->generateWith(
            $provider,
            $prompt['system'],
            $prompt['user'],
            ['service' => 'journal_coaching_report']
        );

        if ($response === null) {
            return null;
        }

        return $this->parseAiJson($response->text, $response->model, $response->provider);
    }

    private function parseAiJson(string $text, string $model, string $provider): ?array
    {
        $text = trim($text);
        $text = (string) preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = (string) preg_replace('/\s*```\s*$/i', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);
        if (!is_array($data)) {
            error_log("[JournalCoachingService] {$provider} returned unparseable JSON: " . mb_substr($text, 0, 300));
            return null;
        }

        $narrative = trim((string) ($data['narrative'] ?? ''));
        if ($narrative === '') {
            error_log("[JournalCoachingService] {$provider} returned empty narrative.");
            return null;
        }

        return [
            'provider'   => $provider,
            'model'      => $model,
            'narrative'  => $narrative,
            'focus_area' => trim((string) ($data['focus_area'] ?? '')),
            'risk_note'  => trim((string) ($data['risk_note']  ?? '')),
        ];
    }

    /**
     * Per-entry feedback (Objective 9's "AI Feedback" per saved trade) —
     * distinct from report() above, which narrates the AGGREGATE stats
     * across every closed trade. This looks at exactly one trade. Same
     * primary->fallback->rule-based convention; null only when USE_AI_SUMMARY
     * is off (no rule-based fallback here — a one-line specific comment
     * needs the trade's actual free-text notes to say anything real, unlike
     * report()'s stats-only rule-based path).
     */
    public function perEntryFeedback(array $entry): ?string
    {
        if (!(bool) env('USE_AI_SUMMARY', false)) {
            return null;
        }

        $mistakes = json_decode((string) ($entry['mistake_tags_json'] ?? '[]'), true);
        $mistakesLabel = \is_array($mistakes) && $mistakes !== []
            ? implode(', ', array_map([MistakeTagCatalog::class, 'label'], $mistakes))
            : 'none logged';

        $prompt = PromptLoader::load('journal_entry_feedback', [
            'market_label'    => (string) ($entry['market_label'] ?? 'unknown pair'),
            'direction'       => (string) ($entry['direction'] ?? 'unknown'),
            'outcome'         => (string) ($entry['outcome'] ?? 'unknown'),
            'profit_loss'     => (string) ($entry['profit_loss'] ?? 'n/a'),
            'emotion'         => $entry['emotion'] !== null ? \App\TradeJournal\EmotionCatalog::label((string) $entry['emotion']) : 'not logged',
            'mistakes'        => $mistakesLabel,
            'notes'           => trim((string) ($entry['notes'] ?? '')) !== '' ? (string) $entry['notes'] : 'none',
            'lessons_learned' => trim((string) ($entry['lessons_learned'] ?? '')) !== '' ? (string) $entry['lessons_learned'] : 'none',
        ]);

        $primary  = strtolower(trim((string) env('AI_PRIMARY_PROVIDER',  'claude')));
        $fallback = strtolower(trim((string) env('AI_FALLBACK_PROVIDER', 'openai')));

        return $this->callProviderForFeedback($primary, $prompt) ?? $this->callProviderForFeedback($fallback, $prompt);
    }

    /** @param array{system:string, user:string} $prompt */
    private function callProviderForFeedback(string $provider, array $prompt): ?string
    {
        $response = (new AIManager())->generateWith(
            $provider,
            $prompt['system'],
            $prompt['user'],
            ['service' => 'journal_entry_feedback']
        );

        if ($response === null) {
            return null;
        }

        $text = trim($response->text);
        $text = (string) preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = (string) preg_replace('/\s*```\s*$/i', '', $text);
        $data = json_decode(trim($text), true);

        $feedback = \is_array($data) ? trim((string) ($data['feedback'] ?? '')) : '';
        return $feedback !== '' ? $feedback : null;
    }

    private function ruleBasedReport(array $stats): array
    {
        $topMistake = array_key_first($stats['mistake_tag_counts']);
        $mistakeLine = $topMistake !== null
            ? 'Your most frequently logged mistake is "' . MistakeTagCatalog::label((string) $topMistake)
              . '" (' . $stats['mistake_tag_counts'][$topMistake] . ' time(s)) — that is the single highest-leverage thing to fix next.'
            : 'No mistakes have been tagged yet — keep logging them as they happen so a pattern can emerge.';

        $bestMarket = $stats['by_market'][0]['market_label'] ?? null;
        $marketLine = $bestMarket !== null
            ? "Your most-logged pair is {$bestMarket} ({$stats['by_market'][0]['total']} trades) — review whether that concentration reflects a genuine edge or just habit."
            : 'No pair data yet.';

        $narrative = "Across {$stats['closed_count']} closed trades your win rate is {$stats['win_rate']}%, "
                   . "with a total logged P/L of {$stats['total_profit_loss']}. {$marketLine} {$mistakeLine}";

        return [
            'provider'   => 'rule_based',
            'model'      => 'rule_based_v1',
            'narrative'  => $narrative,
            'focus_area' => $topMistake !== null
                ? 'Focus on eliminating: ' . MistakeTagCatalog::label((string) $topMistake) . '.'
                : 'Keep logging outcomes and mistakes consistently — patterns need data to appear.',
            'risk_note'  => 'Rule-based analysis only — no AI was used. Informational only, not financial advice.',
        ];
    }
}
