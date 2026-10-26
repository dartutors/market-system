<?php
declare(strict_types=1);
namespace App\Reports;

/**
 * The 8 report types this module supports. Single source of truth for
 * slug/label/description so the index page and the routing/validation in
 * ReportController never drift apart. Every slug here has a real,
 * non-fabricated data source behind it (see each App\Reports\*Report class)
 * — depth varies (Trading Performance / AI Usage / System Health are the
 * richest; the rest are honestly built on the raw tables that exist,
 * without invented aggregate fields those tables don't track).
 */
final class ReportCatalog
{
    private const REPORTS = [
        'trading-performance' => [
            'label'       => 'AI Trading Performance',
            'description' => 'Signals, win/loss rate, confidence, and best/worst pairs.',
        ],
        'ai-usage' => [
            'label'       => 'AI Usage & Cost',
            'description' => 'Claude/OpenAI calls, tokens, cost, latency, and cache efficiency.',
        ],
        'system-health' => [
            'label'       => 'System Health',
            'description' => 'Live status of every data pipeline: DB, AI providers, news, market data, calendar, backtesting, vision.',
        ],
        'scanner' => [
            'label'       => 'Market Scanner',
            'description' => 'Scan run history and per-pair signal rankings.',
        ],
        'news' => [
            'label'       => 'News Intelligence',
            'description' => 'Articles processed, sentiment split, and source breakdown.',
        ],
        'calendar' => [
            'label'       => 'Economic Calendar',
            'description' => 'Upcoming and past events, impact and currency breakdown.',
        ],
        'currency-strength' => [
            'label'       => 'Currency Strength',
            'description' => 'Current ranking plus history collected since this report went live.',
        ],
        'ai-vision' => [
            'label'       => 'AI Vision',
            'description' => 'Chart analyses, recommendation split, and detected pattern frequency.',
        ],
    ];

    /** @return array<string, array{label:string, description:string}> */
    public static function all(): array
    {
        return self::REPORTS;
    }

    public static function isValid(string $slug): bool
    {
        return isset(self::REPORTS[$slug]);
    }

    public static function label(string $slug): string
    {
        return self::REPORTS[$slug]['label'] ?? $slug;
    }
}
