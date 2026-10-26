<?php
declare(strict_types=1);
namespace App\Strategies\Contracts;

/**
 * Common contract every strategy implements. StrategyManager resolves one
 * of these and delegates — adding a new strategy means adding a class and
 * registering it, not editing SignalEngineService or MarketScanService.
 */
interface StrategyInterface
{
    public function name(): string;

    /**
     * @param array<int,array<string,mixed>> $rows Enriched OHLC rows (with indicator columns)
     * @param array<string,mixed> $sentimentSummary ['score' => float, ...]
     * @return array{action:string, confidence:float, reasons:array<string>}
     */
    public function evaluate(array $rows, array $sentimentSummary): array;
}
