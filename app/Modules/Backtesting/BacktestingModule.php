<?php
declare(strict_types=1);
namespace App\Modules\Backtesting;

use App\Modules\Contracts\ModuleInterface;

final class BacktestingModule implements ModuleInterface
{
    public function name(): string { return 'backtesting'; }

    public function routes(): array { return ['/backtest', '/backtest/run']; }

    public function services(): array
    {
        return [\App\Services\BacktestService::class, \App\Services\AiBacktestSummaryService::class];
    }

    public function config(): array { return []; }

    public function events(): array { return []; }

    public function permissions(): array { return ['authenticated']; }
}
