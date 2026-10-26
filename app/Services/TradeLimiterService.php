<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\SignalEvent;
use DateTimeImmutable;

/**
 * Enforces the daily trade limit across all markets.
 *
 * max_signals_for_day() already returns 2 (weekdays) or 1 (Friday),
 * configurable via MAX_SIGNALS_PER_DAY / MAX_SIGNALS_FRIDAY env vars.
 * This service queries the actual count and exposes a simple API.
 *
 * Counts only persisted BUY/SELL events (WAIT/WATCH never reach the DB),
 * so countByDate() always reflects real actionable trades taken today.
 */
final class TradeLimiterService
{
    public function canEnter(string $signalDate, DateTimeImmutable $now): bool
    {
        return $this->todayCount($signalDate) < max_signals_for_day($now);
    }

    public function todayCount(string $signalDate): int
    {
        return SignalEvent::countByDate($signalDate);
    }

    public function remainingToday(string $signalDate, DateTimeImmutable $now): int
    {
        $max = max_signals_for_day($now);
        return max(0, $max - $this->todayCount($signalDate));
    }

    public function maxToday(DateTimeImmutable $now): int
    {
        return max_signals_for_day($now);
    }
}
