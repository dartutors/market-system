<?php
declare(strict_types=1);
namespace App\AI;

use App\AI\Contracts\AIProviderInterface;
use App\AI\Factory\AIProviderFactory;
use App\Config\AI as AIConfig;
use App\Config\AIRouting;
use App\Models\AiProviderLog;

/**
 * Central entry point for every AI text-completion call in the system.
 *
 * Replaces the three hand-rolled Guzzle call sites that used to live inside
 * AiSummaryService, AiInsightService, and AiBacktestSummaryService. Those
 * services keep their own primary/fallback selection logic (preserved
 * exactly, quirks included) and call generateWith() for a single named
 * provider; complete() is a convenience wrapper for new callers (Decision
 * Engine, future prompt-file consumers) that want automatic primary→fallback
 * chaining in one call.
 */
final class AIManager
{
    public function __construct(private AIProviderFactory $factory = new AIProviderFactory())
    {
    }

    /**
     * Tries the configured (or explicitly passed) primary provider, then the
     * fallback provider if the primary fails or has no key configured.
     */
    public function complete(string $systemPrompt, string $userPrompt, array $options = []): ?AIResponse
    {
        $primary  = (string) ($options['primary']  ?? AIConfig::primaryProvider());
        $fallback = (string) ($options['fallback'] ?? AIConfig::fallbackProvider());

        $result = $this->generateWith($primary, $systemPrompt, $userPrompt, $options);
        if ($result !== null || $fallback === $primary) {
            return $result;
        }

        return $this->generateWith(
            $fallback,
            $systemPrompt,
            $userPrompt,
            $options + ['_is_fallback_attempt' => true]
        );
    }

    /**
     * Cost/capability-aware entry point: resolves the provider for a named
     * task type via App\Config\AIRouting (e.g. AI_ROUTE_SIMPLE_SUMMARY=openai,
     * AI_ROUTE_VISION=claude) and calls complete() with automatic fallback.
     * Purely additive — existing AI services keep calling generateWith()
     * directly with their own explicit provider selection; this is for
     * future callers that want routing.
     */
    public function completeForTask(string $taskType, string $systemPrompt, string $userPrompt, array $options = []): ?AIResponse
    {
        $options += [
            'primary'  => AIRouting::providerForTask($taskType),
            'fallback' => AIRouting::fallbackForTask($taskType),
            'service'  => 'task:' . strtolower(trim($taskType)),
        ];

        return $this->complete($systemPrompt, $userPrompt, $options);
    }

    /**
     * Attempts exactly one named provider — no automatic fallback to a
     * different provider. This is the method the AI services call from
     * within their own existing primary/fallback loops.
     */
    public function generateWith(
        string $providerName,
        string $systemPrompt,
        string $userPrompt,
        array $options = []
    ): ?AIResponse {
        $provider = $this->factory->make($providerName);
        if ($provider === null) {
            return null;
        }

        $attempts = max(1, AIConfig::retryAttempts() + 1);
        $response = null;

        // A provider returning HTTP 200 with blank/unusable text (e.g.
        // Claude declining a request — empty content array, still a 200)
        // is not distinguishable from a real answer by a null check alone.
        // Every other AI service in this app independently re-implements a
        // blank-text guard before treating a response as usable; centralizing
        // it here means AIManager::complete()'s primary->fallback chain
        // actually engages on a blank primary response too, which it
        // previously did not (App\Image\VisionManager was the one caller
        // relying solely on complete()'s own null-check and got no fallback
        // at all when Claude returned an empty vision analysis).
        $isUsable = static fn(?AIResponse $r): bool => $r !== null && trim($r->text) !== '';

        for ($i = 0; $i < $attempts; $i++) {
            $response = $provider->generate($systemPrompt, $userPrompt, $options);
            if ($isUsable($response)) {
                break;
            }
        }

        // Logged even when blank, so token/cost the provider actually
        // billed for isn't silently dropped from ai_provider_logs just
        // because the completion itself was unusable.
        $this->logUsage(
            (string) ($options['service'] ?? 'unknown'),
            $providerName,
            $response,
            (bool) ($options['_is_fallback_attempt'] ?? false)
        );

        return $isUsable($response) ? $response : null;
    }

    private function logUsage(string $serviceName, string $providerName, ?AIResponse $response, bool $wasFallback): void
    {
        try {
            AiProviderLog::create([
                'service_name'      => $serviceName,
                'provider'          => $providerName,
                'model'             => $response?->model ?? '',
                'prompt_tokens'     => $response?->promptTokens ?? 0,
                'completion_tokens' => $response?->completionTokens ?? 0,
                'cost_usd'          => $this->estimateCost($providerName, $response),
                'latency_ms'        => $response?->latencyMs ?? 0,
                'http_status'       => $response !== null ? 200 : 0,
                'was_fallback_used' => $wasFallback ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            // Never let usage logging break an AI call — same non-fatal
            // persistence pattern AiSummaryService already uses for its DB save.
            error_log('[AIManager] Usage log failed: ' . $e->getMessage());
        }
    }

    private function estimateCost(string $providerName, ?AIResponse $response): float
    {
        if ($response === null) {
            return 0.0;
        }

        $promptCost     = $response->promptTokens     / 1000 * AIConfig::costPerThousand($providerName, 'prompt');
        $completionCost = $response->completionTokens / 1000 * AIConfig::costPerThousand($providerName, 'completion');

        return round($promptCost + $completionCost, 6);
    }
}
