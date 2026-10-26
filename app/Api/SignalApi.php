<?php
declare(strict_types=1);
namespace App\Api;

use App\Models\SignalEvent;

/** Internal-only facade — see ScannerApi for the pattern/rationale. */
final class SignalApi
{
    public function latest(int $limit = 20): array
    {
        return SignalEvent::latest($limit);
    }

    public function stats(): array
    {
        return SignalEvent::stats();
    }

    public function markOutcome(int $id, string $outcome, string $notes = ''): void
    {
        SignalEvent::markOutcome($id, $outcome, $notes);
    }
}
