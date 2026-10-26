<?php
declare(strict_types=1);
namespace App\Services;

use App\AI\AIManager;
use App\AI\PromptLoader;

/**
 * AI narrative layer over the already-computed App\Vision\
 * TopDownAnalysisEngine + App\Vision\VisionDecisionEngine result. Follows
 * App\MultiTimeframe\MultiTimeframeAiSummaryService's exact provider
 * dispatch pattern (primary -> fallback -> rule-based) since both services
 * do the same job: narrate a pre-computed technical decision, never make
 * one. The rule-based fallback is always available and produces the exact
 * "per-timeframe line -> Conclusion" structure from the spec's own worked
 * example.
 */
final class AiVisionSynthesisService
{
    /**
     * @param array<string, array<string, mixed>> $byTimeframe Label => parsed vision_analysis.md JSON.
     * @param array<string, mixed> $topDown Output of TopDownAnalysisEngine::analyze().
     * @param array<string, mixed> $completeness Output of CompletenessScorer::score().
     * @param array<string, mixed> $decision Output of VisionDecisionEngine::decide().
     * @return array{provider:string, model:string, agrees_with_technical:bool, narrative:string, risk_note:string}
     */
    public function synthesize(
        string $marketLabel,
        array $byTimeframe,
        array $topDown,
        array $completeness,
        array $decision
    ): array {
        if (!(bool) env('USE_AI_SUMMARY', false)) {
            return $this->ruleBasedNarrative($byTimeframe, $topDown, $decision);
        }

        $prompt = $this->buildPrompt($marketLabel, $byTimeframe, $topDown, $completeness, $decision);

        $primary  = strtolower(trim((string) env('AI_PRIMARY_PROVIDER',  'claude')));
        $fallback = strtolower(trim((string) env('AI_FALLBACK_PROVIDER', 'openai')));

        $result = $this->callProvider($primary, $prompt)
               ?? $this->callProvider($fallback, $prompt);

        return $result ?? $this->ruleBasedNarrative($byTimeframe, $topDown, $decision);
    }

    /** @return array{system:string, user:string} */
    private function buildPrompt(
        string $marketLabel,
        array $byTimeframe,
        array $topDown,
        array $completeness,
        array $decision
    ): array {
        $breakdownBlock = '';
        foreach ($byTimeframe as $label => $parsed) {
            $trend = (string) ($parsed['trend'] ?? 'neutral');
            $dec   = (string) ($parsed['recommendation']['decision']   ?? 'NO_TRADE');
            $conf  = (int)    ($parsed['recommendation']['confidence'] ?? 0);
            $breakdownBlock .= "• {$label}: {$trend} trend, {$dec} setup ({$conf}% confidence)\n";
        }

        $conflictsBlock = empty($topDown['conflicts'])
            ? "None.\n"
            : implode("\n", array_map(static fn(string $c): string => "• {$c}", $topDown['conflicts'])) . "\n";

        $warningsBlock = empty($completeness['warnings'])
            ? ''
            : implode("\n", array_map(static fn(string $w): string => "WARNING: {$w}", $completeness['warnings']));

        return PromptLoader::load('vision_topdown_synthesis', [
            'market_label'                => $marketLabel,
            'breakdown_block'             => $breakdownBlock,
            'overall_trend'               => $topDown['overall_trend'],
            'overall_confidence'          => (string) $topDown['overall_confidence'],
            'alignment'                   => $topDown['alignment'],
            'momentum'                    => $topDown['momentum'],
            'potential_reversal'          => $topDown['potential_reversal'] ? 'yes' : 'no',
            'conflicts_block'             => $conflictsBlock,
            'completeness_percentage'     => (string) $completeness['percentage'],
            'completeness_warnings_block' => $warningsBlock,
            'decision'                    => $decision['decision'],
            'decision_confidence'         => (string) $decision['confidence'],
            'risk_level'                  => $decision['risk_level'],
        ]);
    }

    /** @param array{system:string, user:string} $prompt */
    private function callProvider(string $provider, array $prompt): ?array
    {
        $response = (new AIManager())->generateWith(
            $provider,
            $prompt['system'],
            $prompt['user'],
            ['service' => 'vision_topdown_synthesis']
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
        if (!\is_array($data)) {
            error_log("[AiVisionSynthesisService] {$provider} returned unparseable JSON: " . mb_substr($text, 0, 300));
            return null;
        }

        $narrative = trim((string) ($data['narrative'] ?? ''));
        if ($narrative === '') {
            error_log("[AiVisionSynthesisService] {$provider} returned empty narrative.");
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

    /**
     * @param array<string, array<string, mixed>> $byTimeframe
     */
    private function ruleBasedNarrative(array $byTimeframe, array $topDown, array $decision): array
    {
        $lines = [];
        foreach ($byTimeframe as $label => $parsed) {
            $trend = (string) ($parsed['trend'] ?? 'neutral');
            $lines[] = "{$label} is {$trend}.";
        }

        $narrative = implode(' ', $lines) . "\n\nConclusion: " . $this->conclusionText($topDown, $decision);

        return [
            'provider'              => 'rule_based',
            'model'                 => 'rule_based_v1',
            'agrees_with_technical' => true,
            'narrative'             => $narrative,
            'risk_note'             => 'Rule-based analysis only — no AI was used. Informational only, not financial advice.',
        ];
    }

    private function conclusionText(array $topDown, array $decision): string
    {
        if (\in_array($decision['decision'], ['BUY', 'SELL'], true)) {
            $signal = $topDown['lowest_timeframe_signal'];
            $label  = $signal['label'] ?? 'a lower timeframe';
            return "The {$topDown['overall_trend']} bias is confirmed across supporting timeframes, with an entry trigger on {$label}.";
        }

        if ($decision['decision'] === 'WAIT') {
            return $topDown['potential_reversal']
                ? 'Lower timeframes are moving counter to the higher-timeframe bias — wait for alignment before entering.'
                : 'Timeframes are not yet aligned — wait for confirmation before entering.';
        }

        return 'Not enough data is available yet for a directional call.';
    }
}
