<?php
declare(strict_types=1);
namespace App\Api;

use App\Models\AiDecision;

/** Internal-only facade — see ScannerApi for the pattern/rationale. */
final class AiApi
{
    public function decisionsFor(string $marketLabel, int $limit = 5): array
    {
        return AiDecision::latestForMarket($marketLabel, $limit);
    }
}
