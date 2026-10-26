<?php
declare(strict_types=1);
namespace App\Learning;

/**
 * Fixed vocabulary of trade-setup patterns — mirrors
 * App\TradeJournal\MistakeTagCatalog's design exactly. Chosen over trying to
 * cross-reference Vision's separately-uploaded chart screenshots (there is
 * no reliable link between a trade_journal entry's own screenshot and a
 * chart_analysis row — they're two independent upload flows), so
 * pattern_statistics is instead populated from what a trader actually
 * selects when logging/closing a real trade.
 */
final class SetupPatternCatalog
{
    private const PATTERNS = [
        'trend_continuation'       => 'Trend continuation',
        'pullback_entry'           => 'Pullback to moving average',
        'breakout'                 => 'Breakout',
        'reversal'                 => 'Reversal',
        'support_resistance_bounce'=> 'Support/resistance bounce',
        'range_bound'              => 'Range-bound',
        'news_driven'              => 'News-driven move',
        'other'                    => 'Other / unclassified',
    ];

    /** @return array<string,string> tag key => human label, in a fixed display order. */
    public static function all(): array
    {
        return self::PATTERNS;
    }

    public static function label(string $pattern): string
    {
        return self::PATTERNS[$pattern] ?? $pattern;
    }

    public static function isValid(string $pattern): bool
    {
        return isset(self::PATTERNS[$pattern]);
    }
}
