<?php
declare(strict_types=1);
namespace App\AI\Factory;

use App\AI\Contracts\AIProviderInterface;
use App\AI\Providers\ClaudeProvider;
use App\AI\Providers\OpenAIProvider;

/**
 * Resolves a provider name to its implementation. Adding a future provider
 * (Gemini, a local LLM) means adding one case here plus one class under
 * App\AI\Providers — nothing else in the AI layer needs to change.
 */
final class AIProviderFactory
{
    public function make(string $name): ?AIProviderInterface
    {
        return match (strtolower(trim($name))) {
            'claude' => new ClaudeProvider(),
            'openai' => new OpenAIProvider(),
            default  => null,
        };
    }
}
