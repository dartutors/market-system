<?php
declare(strict_types=1);
namespace App\Config;

/**
 * Per-task provider routing for App\AI\AIManager::completeForTask(). Falls
 * back to the existing AI_PRIMARY_PROVIDER/AI_FALLBACK_PROVIDER when no
 * task-specific rule is configured (env AI_ROUTE_<TASK>, AI_ROUTE_<TASK>_FALLBACK).
 *
 * Additive only — the three existing AI services (AiSummaryService,
 * AiInsightService, AiBacktestSummaryService) do their own explicit
 * provider selection and never call this; it's for future callers (Vision,
 * Trade Coach) that want cost/capability-aware routing.
 */
final class AIRouting
{
    public static function providerForTask(string $taskType): string
    {
        $configured = self::envForTask($taskType, '');
        return $configured !== '' ? $configured : AI::primaryProvider();
    }

    public static function fallbackForTask(string $taskType): string
    {
        $configured = self::envForTask($taskType, '_FALLBACK');
        return $configured !== '' ? $configured : AI::fallbackProvider();
    }

    private static function envForTask(string $taskType, string $suffix): string
    {
        $envKey = 'AI_ROUTE_' . strtoupper(trim($taskType)) . $suffix;
        return strtolower(trim((string) env($envKey, '')));
    }
}
