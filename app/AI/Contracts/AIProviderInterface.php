<?php
declare(strict_types=1);
namespace App\AI\Contracts;

use App\AI\AIResponse;

/**
 * Common contract for every AI text-completion provider (Claude, OpenAI,
 * and any future provider). Mirrors App\Services\News\NewsProviderInterface's
 * existing pattern in this codebase.
 */
interface AIProviderInterface
{
    public function name(): string;

    /**
     * Returns null on any failure (missing key, network error, non-200,
     * empty response) so callers can fall through to the next provider or
     * a rule-based path exactly as before this abstraction existed.
     */
    public function generate(string $systemPrompt, string $userPrompt, array $options = []): ?AIResponse;
}
