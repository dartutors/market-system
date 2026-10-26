<?php
declare(strict_types=1);
namespace App\Learning;

use App\Models\PatternStatistic;
use App\Models\TradeJournal;

/**
 * Activates the previously-unused pattern_statistics table by populating it
 * from real logged trade outcomes (TradeJournal::statsBySetupPattern()),
 * rather than a fragile cross-reference to Vision's separately-uploaded
 * chart screenshots — see App\Learning\SetupPatternCatalog's doc comment.
 * market_label/timeframe are stored as 'ALL' (matching App\Models\AiSummary's
 * existing market_scope='ALL' convention) since a personal trading journal
 * won't have enough per-pattern-per-market volume to slice further yet.
 */
final class PatternLearningService
{
    /** @return array<int, array{setup_pattern:string, occurrences:int, wins:int, win_rate:float, avg_profit_loss:float}> */
    public function rebuild(): array
    {
        $grouped = TradeJournal::statsBySetupPattern();

        foreach ($grouped as $row) {
            PatternStatistic::create([
                'pattern_name' => $row['setup_pattern'], // raw catalog key — App\Learning\SetupPatternCatalog::label() renders it for display
                'market_label' => 'ALL',
                'timeframe'    => 'ALL',
                'occurrences'  => $row['occurrences'],
                'win_rate'     => $row['win_rate'],
                'avg_r'        => $row['avg_profit_loss'], // $ P/L average, not a true R-multiple — see TradeJournal::statsBySetupPattern()
            ]);
        }

        return $grouped;
    }
}
