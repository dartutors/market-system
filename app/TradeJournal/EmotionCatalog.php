<?php
declare(strict_types=1);
namespace App\TradeJournal;

/**
 * Fixed vocabulary of trade emotions, same shape/purpose as
 * MistakeTagCatalog — a single tag selected when closing a trade, tallied
 * across trades so a pattern (e.g. "most losses logged as 'anxious'") can
 * actually surface, rather than a free-text field nothing can aggregate.
 */
final class EmotionCatalog
{
    private const EMOTIONS = [
        'confident'  => 'Confident',
        'anxious'    => 'Anxious',
        'fomo'       => 'FOMO',
        'hesitant'   => 'Hesitant',
        'calm'       => 'Calm',
        'frustrated' => 'Frustrated',
        'greedy'     => 'Greedy',
        'disciplined'=> 'Disciplined',
    ];

    /** @return array<string,string> emotion key => human label, in a fixed display order. */
    public static function all(): array
    {
        return self::EMOTIONS;
    }

    public static function label(string $emotion): string
    {
        return self::EMOTIONS[$emotion] ?? $emotion;
    }

    public static function isValid(string $emotion): bool
    {
        return isset(self::EMOTIONS[$emotion]);
    }
}
