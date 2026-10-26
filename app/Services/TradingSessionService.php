<?php
declare(strict_types=1);
namespace App\Services;

use DateTimeImmutable;

/**
 * Centralizes session/hours logic. legacySessionName() and maxSignalsForDay()
 * reproduce current_session_name()/max_signals_for_day() from
 * app/Helpers/time.php exactly (same windows, same Friday rule) — those
 * global functions now delegate here instead of duplicating the logic, so
 * existing callers (SignalEngineService, EntrySignalService, TradeLimiterService)
 * needed no changes.
 *
 * currentSession()/isOverlap()/isFridayRestricted()/isHoliday()/
 * nextSessionCountdown() are new capabilities — additive only, not consumed
 * by existing gating yet. Tokyo/Sydney windows below are approximate
 * placeholders (converted to APP_TIMEZONE); refine if a future feature
 * needs precise session boundaries.
 */
final class TradingSessionService
{
    // Minute-of-day windows, in APP_TIMEZONE (default Africa/Dar_es_Salaam / EAT)
    private const LONDON_START   = 600;  // 10:00
    private const LONDON_END     = 780;  // 13:00
    private const NEW_YORK_START = 930;  // 15:30
    private const NEW_YORK_END   = 1080; // 18:00
    private const TOKYO_START    = 180;  // 03:00 (approximate)
    private const TOKYO_END      = 540;  // 09:00 (approximate)
    private const SYDNEY_START   = 60;   // 01:00 (approximate)
    private const SYDNEY_END     = 300;  // 05:00 (approximate)

    /** Holiday dates (Y-m-d). Empty by default — behavior is unchanged until populated. */
    private const HOLIDAYS = [];

    /** Exact legacy behavior of current_session_name() — only ever london/new_york/off. */
    public function legacySessionName(?DateTimeImmutable $dt = null): string
    {
        $dt      ??= now_eat();
        $minutes  = $this->minuteOfDay($dt);

        if ($minutes >= self::LONDON_START   && $minutes <= self::LONDON_END)   return 'london';
        if ($minutes >= self::NEW_YORK_START && $minutes <= self::NEW_YORK_END) return 'new_york';

        return 'off';
    }

    /** Exact legacy behavior of max_signals_for_day(). */
    public function maxSignalsForDay(?DateTimeImmutable $dt = null): int
    {
        $dt ??= now_eat();

        return ((int) $dt->format('N') === 5)
            ? (int) env('MAX_SIGNALS_FRIDAY', 1)
            : (int) env('MAX_SIGNALS_PER_DAY', 2);
    }

    /** New: also detects Tokyo/Sydney, for future consumers that want the finer-grained read. */
    public function currentSession(?DateTimeImmutable $dt = null): string
    {
        $dt      ??= now_eat();
        $minutes  = $this->minuteOfDay($dt);

        if ($minutes >= self::LONDON_START   && $minutes <= self::LONDON_END)   return 'london';
        if ($minutes >= self::NEW_YORK_START && $minutes <= self::NEW_YORK_END) return 'new_york';
        if ($minutes >= self::TOKYO_START    && $minutes <= self::TOKYO_END)    return 'tokyo';
        if ($minutes >= self::SYDNEY_START   && $minutes <= self::SYDNEY_END)   return 'sydney';

        return 'off';
    }

    public function isOverlap(?DateTimeImmutable $dt = null): bool
    {
        $dt      ??= now_eat();
        $minutes  = $this->minuteOfDay($dt);

        $active = array_filter([
            'london'   => $minutes >= self::LONDON_START   && $minutes <= self::LONDON_END,
            'new_york' => $minutes >= self::NEW_YORK_START && $minutes <= self::NEW_YORK_END,
            'tokyo'    => $minutes >= self::TOKYO_START    && $minutes <= self::TOKYO_END,
            'sydney'   => $minutes >= self::SYDNEY_START   && $minutes <= self::SYDNEY_END,
        ]);

        return \count($active) >= 2;
    }

    /** Same Friday-afternoon rule SignalEngineService checks inline — made reusable, not yet wired in there. */
    public function isFridayRestricted(?DateTimeImmutable $dt = null): bool
    {
        $dt ??= now_eat();

        return (int) $dt->format('N') === 5 && $this->currentSession($dt) === 'new_york';
    }

    public function isHoliday(?DateTimeImmutable $dt = null): bool
    {
        $dt ??= now_eat();

        return \in_array($dt->format('Y-m-d'), self::HOLIDAYS, true);
    }

    /** @return array{session:?string, minutes_until:?int} */
    public function nextSessionCountdown(?DateTimeImmutable $dt = null): array
    {
        $dt      ??= now_eat();
        $minutes  = $this->minuteOfDay($dt);

        $sessionStarts = [
            'sydney'   => self::SYDNEY_START,
            'tokyo'    => self::TOKYO_START,
            'london'   => self::LONDON_START,
            'new_york' => self::NEW_YORK_START,
        ];

        $nextSession = null;
        $soonest     = null;

        foreach ($sessionStarts as $name => $startMinute) {
            $delta = $startMinute - $minutes;
            if ($delta <= 0) {
                $delta += 24 * 60;
            }
            if ($soonest === null || $delta < $soonest) {
                $soonest     = $delta;
                $nextSession = $name;
            }
        }

        return ['session' => $nextSession, 'minutes_until' => $soonest];
    }

    private function minuteOfDay(DateTimeImmutable $dt): int
    {
        return ((int) $dt->format('H')) * 60 + (int) $dt->format('i');
    }
}
