<?php
declare(strict_types=1);
namespace App\MultiTimeframe;

use App\AI\AIManager;
use App\AI\PromptLoader;

/**
 * AI narrative layer over the multi-timeframe confluence read. Follows
 * AiSummaryService's exact provider dispatch pattern (primary → fallback →
 * rule-based), but scoped to one on-demand MTF analysis run instead of the
 * cron news cycle, so — like the rest of this feature-flagged module — it
 * carries no DB cache: MultiTimeframeController::analyze() already recomputes
 * everything fresh on every POST.
 */
final class MultiTimeframeAiSummaryService
{
    /**
     * @param array<string, PullbackAssessment> $pullbacks
     * @return array{provider:string, model:string, agrees_with_technical:bool, narrative:string, risk_note:string}
     */
    public function summarize(
        string $marketLabel,
        MultiTimeframeResult $alignment,
        array $pullbacks,
        EntryConfirmationResult $entry
    ): array {
        if (!(bool) env('USE_AI_SUMMARY', false)) {
            return $this->ruleBasedSummary($alignment, $entry);
        }

        $prompt = $this->buildPrompt($marketLabel, $alignment, $pullbacks, $entry);

        $primary  = strtolower(trim((string) env('AI_PRIMARY_PROVIDER',  'claude')));
        $fallback = strtolower(trim((string) env('AI_FALLBACK_PROVIDER', 'openai')));

        $result = $this->callProvider($primary, $prompt)
               ?? $this->callProvider($fallback, $prompt);

        return $result ?? $this->ruleBasedSummary($alignment, $entry);
    }

    /**
     * @param array<string, PullbackAssessment> $pullbacks
     * @return array{system:string, user:string}
     */
    private function buildPrompt(
        string $marketLabel,
        MultiTimeframeResult $alignment,
        array $pullbacks,
        EntryConfirmationResult $entry
    ): array {
        $breakdownBlock = '';
        foreach ($alignment->breakdown as $label => $b) {
            $breakdownBlock .= "• {$label}: {$b['trend']} trend, {$b['momentum']} momentum, {$b['status']} (weight " . round($b['weight'] * 100) . "%)\n";
        }

        $conflictsBlock = empty($alignment->conflicts)
            ? "None.\n"
            : implode("\n", array_map(static fn(string $c): string => "• {$c}", $alignment->conflicts)) . "\n";

        $pullbackBlock = '';
        foreach ($pullbacks as $label => $assessment) {
            if ($assessment->isPullback) {
                $pullbackBlock .= "• {$assessment->note}\n";
            }
        }
        if ($pullbackBlock === '') {
            $pullbackBlock = "No pullbacks currently detected.\n";
        }

        return PromptLoader::load('multi_timeframe_summary', [
            'market_label'    => $marketLabel,
            'overall_bias'    => $alignment->overallBias,
            'confidence'      => (string) $alignment->confidence,
            'breakdown_block' => $breakdownBlock,
            'conflicts_block' => $conflictsBlock,
            'pullback_block'  => $pullbackBlock,
            'entry_state'     => $entry->state,
            'entry_reason'    => $entry->reason,
        ]);
    }

    /**
     * @param array{system:string, user:string} $prompt
     */
    private function callProvider(string $provider, array $prompt): ?array
    {
        $response = (new AIManager())->generateWith(
            $provider,
            $prompt['system'],
            $prompt['user'],
            ['service' => 'multi_timeframe_ai_summary']
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
            error_log("[MultiTimeframeAiSummaryService] {$provider} returned unparseable JSON: " . mb_substr($text, 0, 300));
            return null;
        }

        $narrative = trim((string) ($data['narrative'] ?? ''));
        if ($narrative === '') {
            error_log("[MultiTimeframeAiSummaryService] {$provider} returned empty narrative.");
            return null;
        }

        return [
            'provider'               => $provider,
            'model'                  => $model,
            'agrees_with_technical'  => (bool) ($data['agrees_with_technical'] ?? true),
            'narrative'              => $narrative,
            'risk_note'              => trim((string) ($data['risk_note'] ?? '')),
        ];
    }

    private function ruleBasedSummary(MultiTimeframeResult $alignment, EntryConfirmationResult $entry): array
    {
        $bias = $alignment->overallBias;

        $narrative = match (true) {
            $bias === 'neutral' => 'Higher timeframes show no dominant bias right now — the safest read is to '
                . 'wait for the weighted confluence to lean clearly one way before watching for an entry.',
            $entry->confirmed => "Higher timeframes lean {$bias} with {$alignment->confidence}% weighted confidence, "
                . "and a pullback has resumed in that direction on the entry timeframes. Still confirm structure "
                . "yourself and watch for risk around any pending news before acting.",
            $entry->state === 'pullback_only' => "Higher timeframes lean {$bias} with {$alignment->confidence}% weighted confidence "
                . "and a pullback is in progress, but the entry timeframes haven't confirmed a resumption yet — "
                . "wait for that confirmation rather than anticipating it.",
            default => "Higher timeframes lean {$bias} with {$alignment->confidence}% weighted confidence, but no "
                . "pullback has formed yet. Watch for a retracement toward the moving averages before looking for an entry.",
        };

        return [
            'provider'              => 'rule_based',
            'model'                 => 'rule_based_v1',
            'agrees_with_technical' => true,
            'narrative'             => $narrative,
            'risk_note'             => 'Rule-based analysis only — no AI was used. Informational only, not financial advice.',
        ];
    }
}
