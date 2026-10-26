<?php
declare(strict_types=1);
namespace App\AI\Providers;

use App\AI\AIResponse;
use App\AI\Contracts\AIProviderInterface;
use App\Config\AI as AIConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Single canonical Claude client. Replaces the three copies of this same
 * HTTP call that used to live in AiSummaryService, AiInsightService, and
 * AiBacktestSummaryService (which had drifted to two different CLAUDE_MODEL
 * defaults). There is now exactly one default, read from App\Config\AI.
 */
final class ClaudeProvider implements AIProviderInterface
{
    public function name(): string
    {
        return 'claude';
    }

    public function generate(string $systemPrompt, string $userPrompt, array $options = []): ?AIResponse
    {
        $apiKey = AIConfig::claudeApiKey();
        if ($apiKey === '') {
            return null;
        }

        $model     = (string) ($options['model']       ?? AIConfig::claudeModel());
        $maxTokens = (int)    ($options['max_tokens']   ?? AIConfig::maxTokens());
        $temp      = (float)  ($options['temperature']  ?? AIConfig::temperature());

        // When an image is supplied, build a multi-content message (image
        // block + text block) instead of a plain string. Absent, this is
        // byte-for-byte the same request every existing text-only caller
        // (AiSummaryService, AiInsightService, AiBacktestSummaryService) sends.
        $content = isset($options['image_base64'])
            ? [
                [
                    'type'   => 'image',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => (string) ($options['image_media_type'] ?? 'image/jpeg'),
                        'data'       => (string) $options['image_base64'],
                    ],
                ],
                ['type' => 'text', 'text' => $userPrompt],
            ]
            : $userPrompt;

        $client = new Client([
            'timeout'         => AIConfig::timeoutSeconds(),
            'connect_timeout' => AIConfig::connectTimeoutSeconds(),
            'http_errors'     => false,
        ]);

        $startedAt = microtime(true);

        try {
            $response = $client->post('https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ],
                'json' => [
                    'model'       => $model,
                    'max_tokens'  => $maxTokens,
                    'temperature' => $temp,
                    'system'      => $systemPrompt,
                    'messages'    => [
                        ['role' => 'user', 'content' => $content],
                    ],
                ],
            ]);
        } catch (GuzzleException $e) {
            error_log('[ClaudeProvider] Network error: ' . $e->getMessage());
            return null;
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $status    = $response->getStatusCode();

        if ($status !== 200) {
            error_log('[ClaudeProvider] HTTP ' . $status . ': '
                . mb_substr((string) $response->getBody(), 0, 200));
            return null;
        }

        $body = json_decode((string) $response->getBody(), true);
        $text = (string) ($body['content'][0]['text'] ?? '');

        return new AIResponse(
            provider: 'claude',
            model: $model,
            text: $text,
            promptTokens: (int) ($body['usage']['input_tokens']  ?? 0),
            completionTokens: (int) ($body['usage']['output_tokens'] ?? 0),
            latencyMs: $latencyMs,
            raw: \is_array($body) ? $body : [],
        );
    }
}
