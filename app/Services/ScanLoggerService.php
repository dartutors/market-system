<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\ScanRun;

final class ScanLoggerService
{
    /**
     * @param string $symbol  Market symbol to log against (e.g. EURUSD=X).
     *                        Defaults to the legacy MARKET_SYMBOL env value.
     */
    public function log(string $status, string $details, string $symbol = ''): void
    {
        $sym = $symbol !== ''
               ? $symbol
               : (string) env('MARKET_SYMBOL', 'EURUSD=X');

        ScanRun::create($sym, $status, $details);
    }
}
