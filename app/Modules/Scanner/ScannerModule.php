<?php
declare(strict_types=1);
namespace App\Modules\Scanner;

use App\Modules\Contracts\ModuleInterface;

final class ScannerModule implements ModuleInterface
{
    public function name(): string { return 'scanner'; }

    public function routes(): array { return ['/dashboard', '/admin/run-scan']; }

    public function services(): array
    {
        return [
            \App\Services\MarketScanService::class, \App\Services\SignalEngineService::class,
            \App\Services\EntrySignalService::class, \App\Services\IndicatorService::class,
            \App\Services\MarketDataService::class, \App\Services\RiskGuardService::class,
            \App\Services\TradeLimiterService::class, \App\Services\ScanLoggerService::class,
            \App\Strategies\StrategyManager::class,
        ];
    }

    public function config(): array
    {
        return ['MARKETS', 'MARKET_TIMEFRAME', 'MAX_SIGNALS_PER_DAY', 'MAX_SIGNALS_FRIDAY', 'MIN_CONFIDENCE_TO_ALERT', 'ACTIVE_STRATEGY'];
    }

    public function events(): array { return [\App\Events\MarketScanCompleted::class]; }

    public function permissions(): array { return ['authenticated']; }
}
