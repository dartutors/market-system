<?php
declare(strict_types=1);
namespace App\Api;

use App\Services\MarketScanService;

/**
 * Internal-only facade. No HTTP route exposes this yet — it's the seam a
 * future desktop/mobile/Telegram client or public API would use instead of
 * instantiating MarketScanService directly.
 */
final class ScannerApi
{
    public function runScan(): array
    {
        return (new MarketScanService())->run();
    }
}
