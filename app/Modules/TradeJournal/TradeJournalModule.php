<?php
declare(strict_types=1);
namespace App\Modules\TradeJournal;

use App\Modules\Contracts\ModuleInterface;

/**
 * Active as of Phase 2.6 — gated behind the (previously reserved but unused)
 * TRADE_JOURNAL feature flag, default off. Activates the pre-existing
 * TradeJournal model/table (built in an earlier sprint, never wired to a
 * controller until now). On-demand only, same pattern as the other Phase 2
 * modules: computes analytics fresh per request, no cron wiring.
 *
 * Distinct from App\Modules\Learning\LearningModule (AI_LEARNING flag) —
 * Learning is reserved for aggregate strategy/pattern statistics; this
 * module is the trader's own manual + AI-logged trade history and review.
 */
final class TradeJournalModule implements ModuleInterface
{
    public function name(): string { return 'trade_journal'; }

    public function routes(): array
    {
        return ['/trade-journal', '/trade-journal/store', '/trade-journal/update', '/trade-journal/coaching-report', '/trade-journal/archive'];
    }

    public function services(): array
    {
        return [
            \App\Controllers\TradeJournalController::class,
            \App\Models\TradeJournal::class,
            \App\TradeJournal\MistakeTagCatalog::class,
            \App\TradeJournal\JournalCoachingService::class,
        ];
    }

    public function config(): array { return ['FEATURE_TRADE_JOURNAL']; }

    public function events(): array { return []; }

    public function permissions(): array { return ['authenticated']; }
}
