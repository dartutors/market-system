<?php
declare(strict_types=1);
namespace App\Api;

use App\Services\BacktestService;

/** Internal-only facade — see ScannerApi for the pattern/rationale. */
final class BacktestApi
{
    public function run(string $symbol, string $timeframe, int $days): array
    {
        return (new BacktestService())->run($symbol, $timeframe, $days);
    }
}
