<?php
declare(strict_types=1);
namespace App\TradeJournal;

/**
 * Fixed vocabulary of loggable trade mistakes — distinct from
 * App\TradeCoach\CoachContentLibrary::commonMistakes(), which is static
 * advisory copy shown alongside a decision type. This catalog is a tag set
 * the user actually selects when closing a real trade, so its frequency can
 * be tallied across trades (TradeJournal::stats()['mistake_tag_counts']).
 */
final class MistakeTagCatalog
{
    private const TAGS = [
        'entered_early'      => 'Entered before confirmation',
        'no_stop_loss'       => 'No stop loss set',
        'moved_stop_loss'    => 'Moved stop loss against the plan',
        'oversized_position' => 'Position size too large for the risk plan',
        'fomo_entry'         => 'FOMO entry — chased the move',
        'revenge_trade'      => 'Revenge trade after a loss',
        'ignored_calendar'   => 'Ignored a high-impact news event',
        'no_exit_plan'       => 'No clear exit/target plan',
        'overtraded'         => 'Overtraded — too many positions today',
        'ignored_signal'     => 'Ignored the system signal/bias',
    ];

    /** @return array<string,string> tag key => human label, in a fixed display order. */
    public static function all(): array
    {
        return self::TAGS;
    }

    public static function label(string $tag): string
    {
        return self::TAGS[$tag] ?? $tag;
    }

    public static function isValid(string $tag): bool
    {
        return isset(self::TAGS[$tag]);
    }

    /** @param array<mixed> $tags @return array<string> validated, deduplicated tag keys */
    public static function sanitize(array $tags): array
    {
        $clean = array_map(static fn($t): string => trim((string) $t), $tags);
        return array_values(array_unique(array_filter($clean, [self::class, 'isValid'])));
    }
}
