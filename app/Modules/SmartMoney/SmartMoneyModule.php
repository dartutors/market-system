<?php
declare(strict_types=1);
namespace App\Modules\SmartMoney;

use App\Modules\Contracts\ModuleInterface;

/**
 * Active as of Phase 3.0 — claims the previously reserved-but-unclaimed
 * SMART_MONEY feature flag, default off (same "activate a reserved flag"
 * precedent as TradeJournalModule/MarketIntelligenceModule before it).
 * On-demand only, no new tables: every structure (Order Blocks, Fair Value
 * Gaps, Liquidity Pools, Equal Highs/Lows, BOS, CHoCH, Premium/Discount
 * Zones, Liquidity Sweeps, Mitigation Blocks) is computed fresh per request
 * from live candle data (App\MarketData\Manager\MarketDataManager) — the
 * same "no new tables, compute fresh" pattern MarketIntelligenceModule
 * established in Phase 2.7. Reuses App\Patterns\Support\SwingPointDetector
 * (Phase 2.9) as its swing-point primitive.
 */
final class SmartMoneyModule implements ModuleInterface
{
    public function name(): string
    {
        return 'smart_money';
    }

    public function routes(): array
    {
        return ['/smart-money', '/smart-money/analyze'];
    }

    public function services(): array
    {
        return [
            \App\Controllers\SmartMoneyController::class,
            \App\SmartMoney\MarketStructureEngine::class,
            \App\SmartMoney\StructureDetectorRegistry::class,
        ];
    }

    public function config(): array
    {
        return ['FEATURE_SMART_MONEY'];
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
