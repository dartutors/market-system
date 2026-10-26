<?php
declare(strict_types=1);
namespace App\TradeCoach;

use App\AI\AIManager;
use App\AI\PromptLoader;

/**
 * Optional AI narrative layer over an already-explained decision. Follows
 * AiSummaryService's exact provider dispatch pattern (primary → fallback →
 * null on failure) — unlike CoachContentLibrary's rule-based content, this
 * is additive and may simply be absent (returns null) when USE_AI_SUMMARY
 * is off or no provider responds; the coaching experience is complete
 * without it.
 */
final class TradeCoachAiService
{
    /**
     * @param array<string> $reasoning
     * @param array<string> $conflicts
     * @return array{provider:string, model:string, narrative:string, risk_note:string}|null
     */
    public function narrate(
        string $marketLabel,
        string $decision,
        float $confidence,
        string $riskLevel,
        string $mode,
        array $reasoning,
        array $conflicts
    ): ?array {
        if (!(bool) env('USE_AI_SUMMARY', false)) {
            return null;
        }

        $prompt = PromptLoader::load('trade_coach', [
            'market_label'    => $marketLabel,
            'decision'        => $decision,
            'confidence'      => (string) $confidence,
            'risk_level'      => $riskLevel,
            'mode'            => $mode,
            'reasoning_block' => empty($reasoning) ? 'None recorded.' : implode("\n", array_map(static fn(string $r): string => "• {$r}", $reasoning)),
            'conflicts_block' => empty($conflicts) ? 'None.' : implode("\n", array_map(static fn(string $c): string => "• {$c}", $conflicts)),
        ]);

        $primary  = strtolower(trim((string) env('AI_PRIMARY_PROVIDER',  'claude')));
        $fallback = strtolower(trim((string) env('AI_FALLBACK_PROVIDER', 'openai')));

        return $this->callProvider($primary, $prompt) ?? $this->callProvider($fallback, $prompt);
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
            ['service' => 'trade_coach']
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
            error_log("[TradeCoachAiService] {$provider} returned unparseable JSON: " . mb_substr($text, 0, 300));
            return null;
        }

        $narrative = trim((string) ($data['narrative'] ?? ''));
        if ($narrative === '') {
            error_log("[TradeCoachAiService] {$provider} returned empty narrative.");
            return null;
        }

        return [
            'provider'  => $provider,
            'model'     => $model,
            'narrative' => $narrative,
            'risk_note' => trim((string) ($data['risk_note'] ?? '')),
        ];
    }
}
