<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Centralized feature-flag lookup. All 8 named future-feature flags default
 * to disabled (none are built yet). Any flag NOT in the table defaults to
 * enabled — the literal mechanism for "existing functionality defaults to
 * enabled" — though nothing existing is actually gated by any flag today;
 * no call site was retrofitted with a flag check in this pass.
 *
 * Env override: FEATURE_<NAME>=true|false — deliberately distinct from this
 * codebase's existing USE_<NAME> convention (USE_AI_SUMMARY, etc.), which
 * toggles real already-built optional behavior. FEATURE_* gates entire
 * features that don't exist yet.
 */
final class FeatureFlagService
{
    private const DEFAULT_FLAGS = [
        'AI_VISION'          => false,
        'MULTI_TIMEFRAME'    => false,
        'TRADE_COACH'        => false,
        'TRADE_JOURNAL'      => false,
        'AI_LEARNING'        => false,
        'VOICE_ASSISTANT'    => false,
        'ADVANCED_ANALYTICS' => false,
        'SMART_MONEY'        => false,
        'RISK_MANAGEMENT'    => false,
        'ADVANCED_BACKTESTING' => false,
    ];

    public static function isEnabled(string $flag): bool
    {
        $flag    = strtoupper(trim($flag));
        $default = self::DEFAULT_FLAGS[$flag] ?? true;

        return (bool) env('FEATURE_' . $flag, $default);
    }

    /**
     * @return array<string, bool>
     */
    public static function all(): array
    {
        $result = [];
        foreach (self::DEFAULT_FLAGS as $flag => $default) {
            $result[$flag] = self::isEnabled($flag);
        }
        return $result;
    }

    /** @return array<string> */
    public static function knownFlags(): array
    {
        return array_keys(self::DEFAULT_FLAGS);
    }
}
