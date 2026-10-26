<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\SignalEvent;
use DateTimeImmutable;

final class RiskGuardService
{
    /**
     * Determines whether a new signal may be emitted for the given market.
     * The daily cap is enforced PER MARKET (not globally).
     *
     * @return array{0: bool, 1: string}
     */
    public function canEmit(string $marketLabel, string $signalDate, DateTimeImmutable $now): array
    {
        $count = SignalEvent::countByDateAndMarket($signalDate, $marketLabel);
        $max   = max_signals_for_day($now);

        if ($count >= $max) {
            return [false, 'Daily signal cap reached for ' . $marketLabel . '.'];
        }

        return [true, 'ok'];
    }
}
