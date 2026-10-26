<?php
declare(strict_types=1);
namespace App\Modules\Reports;

use App\Modules\Contracts\ModuleInterface;

final class ReportsModule implements ModuleInterface
{
    public function name(): string { return 'reports'; }

    /** No dedicated Reports route yet — SignalEvent::stats() is currently surfaced on /dashboard. */
    public function routes(): array { return ['/dashboard']; }

    public function services(): array
    {
        return [\App\Models\SignalEvent::class, \App\Models\BacktestSummary::class, \App\Models\StrategyStatistic::class, \App\Models\PatternStatistic::class];
    }

    public function config(): array { return []; }

    public function events(): array { return []; }

    public function permissions(): array { return ['authenticated']; }
}
