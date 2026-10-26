<?php
declare(strict_types=1);
namespace App\Modules\AdvancedBacktesting;

use App\Modules\Contracts\ModuleInterface;

/**
 * Active as of Phase 3.1 — claims the ADVANCED_BACKTESTING flag (default
 * off; ADVANCED_ANALYTICS was already claimed by Phase 2.7's Market
 * Intelligence module). Separate from BacktestingModule/BacktestService
 * (the original /backtest quick-simulate page, left untouched) — this is
 * the multi-strategy comparison + walk-forward + Monte Carlo + parameter
 * optimization layer on top of the real App\Strategies system. No new
 * table: it reuses backtest_summaries (widened by migration 030) rather
 * than a parallel schema.
 */
final class AdvancedBacktestingModule implements ModuleInterface
{
    public function name(): string
    {
        return 'advanced_backtesting';
    }

    public function routes(): array
    {
        return ['/backtest/advanced', '/backtest/advanced/run'];
    }

    public function services(): array
    {
        return [
            \App\Controllers\AdvancedBacktestController::class,
            \App\Backtesting\BacktestRunner::class,
            \App\Backtesting\StrategyComparator::class,
            \App\Backtesting\WalkForwardTester::class,
            \App\Backtesting\ParameterOptimizer::class,
            \App\Backtesting\MonteCarloSimulator::class,
        ];
    }

    public function config(): array
    {
        return ['FEATURE_ADVANCED_BACKTESTING'];
    }

    public function events(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return ['authenticated'];
    }
}
