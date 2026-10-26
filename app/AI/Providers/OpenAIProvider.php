<?php
declare(strict_types=1);
namespace App\AI\Providers;

use App\AI\AIResponse;
use App\AI\Contracts\AIProviderInterface;
use App\Config\AI as AIConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Single canonical OpenAI client, replacing the three duplicated copies
 * previously in AiSummaryService, AiInsightService, and AiBacktestSummaryService.
 *
 * $options['json_mode'] (default true) controls whether response_format is
 * forced to json_object — the pre-refactor code enabled this for every JSON
 * consumer but omitted it for the plain-text daily report, so that switch
 * is preserved here rather than always-on.
 */
final class OpenAIProvider implements AIProviderInterface
{
    public function name(): string
    {
        return 'openai';
    }

    public function generate(string $systemPrompt, string $userPrompt, array $options = []): ?AIResponse
    {
        $apiKey = AIConfig::openAiApiKey();
        if ($apiKey === '') {
            return null;
        }

        $model     = (string) ($options['model']       ?? AIConfig::openAiModel());
        $maxTokens = (int)    ($options['max_tokens']   ?? AIConfig::maxTokens());
        $temp      = (float)  ($options['temperature']  ?? AIConfig::temperature());
        $jsonMode  = (bool)   ($options['json_mode']    ?? true);

        // When an image is supplied, build a multi-content message (text +
        // image_url block) instead of a plain string. Absent, this is
        // byte-for-byte the same request every existing text-only caller sends.
        // This matters beyond just "adding vision": if a Claude vision call
        // fails and AIManager::complete() falls back to OpenAI, an OpenAI
        // provider that silently dropped the image would return a confident
        // but completely fabricated chart analysis — worse than no fallback.
        $userContent = isset($options['image_base64'])
            ? [
                ['type' => 'text', 'text' => $userPrompt],
                [
                    'type'      => 'image_url',
                    'image_url' => [
                        'url' => 'data:' . (string) ($options['image_media_type'] ?? 'image/jpeg')
                               . ';base64,' . (string) $options['image_base64'],
                    ],
                ],
            ]
            : $userPrompt;

        $client = new Client([
            'timeout'         => AIConfig::timeoutSeconds(),
            'connect_timeout' => AIConfig::connectTimeoutSeconds(),
            'http_errors'     => false,
        ]);

        $payload = [
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'temperature' => $temp,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userContent],
            ],
        ];
        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $startedAt = microtime(true);

        try {
            $response = $client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            error_log('[OpenAIProvider] Network error: ' . $e->getMessage());
            return null;
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $status    = $response->getStatusCode();

        if ($status !== 200) {
            error_log('[OpenAIProvider] HTTP ' . $status . ': '
                . mb_substr((string) $response->getBody(), 0, 200));
            return null;
        }

        $body = json_decode((string) $response->getBody(), true);
        $text = (string) ($body['choices'][0]['message']['content'] ?? '');

        return new AIResponse(
            provider: 'openai',
            model: $model,
            text: $text,
            promptTokens: (int) ($body['usage']['prompt_tokens']     ?? 0),
            completionTokens: (int) ($body['usage']['completion_tokens'] ?? 0),
            latencyMs: $latencyMs,
            raw: \is_array($body) ? $body : [],
        );
    }
}
