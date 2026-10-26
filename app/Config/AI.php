<?php
declare(strict_types=1);
namespace App\Config;

/**
 * Static config accessor for the AI layer, mirroring App\Config\Mail's pattern.
 * Reads the exact env vars already used by the pre-refactor AI services —
 * no new required env vars.
 */
final class AI
{
    public static function primaryProvider(): string
    {
        return strtolower(trim((string) env('AI_PRIMARY_PROVIDER', 'claude')));
    }

    public static function fallbackProvider(): string
    {
        return strtolower(trim((string) env('AI_FALLBACK_PROVIDER', 'openai')));
    }

    public static function claudeApiKey(): string
    {
        return trim((string) env('CLAUDE_API_KEY', ''));
    }

    public static function claudeModel(): string
    {
        return (string) env('CLAUDE_MODEL', 'claude-haiku-4-5');
    }

    public static function openAiApiKey(): string
    {
        return trim((string) env('OPENAI_API_KEY', ''));
    }

    public static function openAiModel(): string
    {
        return (string) env('OPENAI_MODEL', 'gpt-4o-mini');
    }

    public static function maxTokens(): int
    {
        return max(100, (int) env('AI_MAX_TOKENS', 350));
    }

    public static function temperature(): float
    {
        return min(1.0, max(0.0, (float) env('AI_TEMPERATURE', 0.2)));
    }

    public static function retryAttempts(): int
    {
        return max(0, (int) env('AI_RETRY_ATTEMPTS', 0));
    }

    public static function timeoutSeconds(): int
    {
        return max(5, (int) env('AI_TIMEOUT_SECONDS', 30));
    }

    public static function connectTimeoutSeconds(): int
    {
        return max(2, (int) env('AI_CONNECT_TIMEOUT_SECONDS', 10));
    }

    /**
     * Optional cost-per-1K-token overrides, e.g. AI_COST_CLAUDE_PROMPT_PER_1K.
     * Defaults to 0.0 (no cost estimate) when unset — cost tracking never
     * fails or guesses a price the operator hasn't configured.
     */
    public static function costPerThousand(string $provider, string $kind): float
    {
        $key = 'AI_COST_' . strtoupper($provider) . '_' . strtoupper($kind) . '_PER_1K';
        return (float) env($key, 0.0);
    }
}
