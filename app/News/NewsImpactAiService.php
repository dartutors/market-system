<?php
declare(strict_types=1);
namespace App\News;

use App\AI\AIManager;
use App\AI\PromptLoader;

/**
 * Section 8 "click a headline for its AI-explained likely market impact".
 * Follows TradeCoachAiService's exact provider dispatch pattern (primary ->
 * fallback -> null on failure/disabled). Unlike TradeCoachAiService, callers
 * MUST cache the result (see NewsController::explain() / NewsItem::
 * saveExplanation()) — this class itself calls the AI provider every time
 * it's invoked, with no memoization of its own.
 */
final class NewsImpactAiService
{
    /** @return array{provider:string, model:string, explanation:string}|null */
    public function explain(string $title, string $source, ?string $publishedAt, string $marketBias): ?array
    {
        if (!(bool) env('USE_AI_SUMMARY', false)) {
            return null;
        }

        $prompt = PromptLoader::load('news_impact_explain', [
            'title'        => $title,
            'source'       => $source,
            'published_at' => $publishedAt ?? 'unknown',
            'market_bias'  => $marketBias,
        ]);

        $primary  = strtolower(trim((string) env('AI_PRIMARY_PROVIDER',  'claude')));
        $fallback = strtolower(trim((string) env('AI_FALLBACK_PROVIDER', 'openai')));

        return $this->callProvider($primary, $prompt) ?? $this->callProvider($fallback, $prompt);
    }

    /** @param array{system:string, user:string} $prompt */
    private function callProvider(string $provider, array $prompt): ?array
    {
        $response = (new AIManager())->generateWith(
            $provider,
            $prompt['system'],
            $prompt['user'],
            ['service' => 'news_impact_explain']
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
            error_log("[NewsImpactAiService] {$provider} returned unparseable JSON: " . mb_substr($text, 0, 300));
            return null;
        }

        $explanation = trim((string) ($data['explanation'] ?? ''));
        if ($explanation === '') {
            error_log("[NewsImpactAiService] {$provider} returned an empty explanation.");
            return null;
        }

        return ['provider' => $provider, 'model' => $model, 'explanation' => $explanation];
    }
}
