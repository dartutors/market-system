<?php
declare(strict_types=1);
namespace App\AI;

/**
 * Immutable result of a single AI provider call.
 */
final class AIResponse
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $text,
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly int $latencyMs = 0,
        public readonly array $raw = [],
    ) {
    }
}
