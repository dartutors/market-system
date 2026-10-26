<?php
declare(strict_types=1);
namespace App\Services;

use App\Config\EconomicCalendar;
use App\Models\CalendarEvent;
use DateTimeImmutable;
use DateTimeZone;

final class EconomicCalendarService
{
    private const MARKET_CURRENCIES = [
        'EUR/USD' => ['EUR', 'USD'],
        'GBP/USD' => ['GBP', 'USD'],
        'USD/JPY' => ['USD', 'JPY'],
    ];

    /**
     * Loads events: DB first, static config as fallback if DB unavailable.
     */
    private function loadEvents(): array
    {
        try {
            $rows = CalendarEvent::all();
            if (!empty($rows)) {
                return array_map(static fn(array $r): array => [
                    'title'        => $r['title'],
                    'currency'     => strtoupper($r['currency']),
                    'impact'       => $r['impact'],
                    'scheduled_at' => $r['scheduled_at'],
                ], $rows);
            }
        } catch (\Throwable) {
            // DB table not yet created — fall through to static config
        }

        return EconomicCalendar::events();
    }

    public function getActiveRiskWindow(string $marketLabel, DateTimeImmutable $now): ?array
    {
        if (!(bool) env('USE_ECONOMIC_CALENDAR', true)) {
            return null;
        }

        $beforeMin = max(0, (int) env('HIGH_IMPACT_BLOCK_MINUTES_BEFORE', 45));
        $afterMin  = max(0, (int) env('HIGH_IMPACT_BLOCK_MINUTES_AFTER',  30));
        $tz        = new DateTimeZone((string) env('APP_TIMEZONE', 'Africa/Dar_es_Salaam'));

        foreach ($this->loadEvents() as $event) {
            if (($event['impact'] ?? '') !== 'high') {
                continue;
            }

            $currency = strtoupper((string) ($event['currency'] ?? ''));
            if (!$this->affectsMarket($currency, $marketLabel)) {
                continue;
            }

            try {
                $eventTime = new DateTimeImmutable((string) $event['scheduled_at'], $tz);
            } catch (\Throwable) {
                continue;
            }

            $windowStart = $eventTime->modify("-{$beforeMin} minutes");
            $windowEnd   = $eventTime->modify("+{$afterMin} minutes");

            if ($now >= $windowStart && $now <= $windowEnd) {
                $title = (string) ($event['title'] ?? 'Unknown Event');
                return [
                    'blocked'      => true,
                    'event_title'  => $title,
                    'currency'     => $currency,
                    'scheduled_at' => (string) $event['scheduled_at'],
                    'reason'       => "High-impact {$currency} event nearby: {$title}. Avoid entries within {$beforeMin}min before / {$afterMin}min after release.",
                ];
            }
        }

        return null;
    }

    public function getUpcomingEvents(DateTimeImmutable $now, int $days = 7): array
    {
        if (!(bool) env('USE_ECONOMIC_CALENDAR', true)) {
            return [];
        }

        $tz       = new DateTimeZone((string) env('APP_TIMEZONE', 'Africa/Dar_es_Salaam'));
        $cutoff   = $now->modify("+{$days} days");
        $lookback = $now->modify('-2 hours');
        $output   = [];

        foreach ($this->loadEvents() as $event) {
            try {
                $eventTime = new DateTimeImmutable((string) $event['scheduled_at'], $tz);
            } catch (\Throwable) {
                continue;
            }

            if ($eventTime < $lookback || $eventTime > $cutoff) {
                continue;
            }

            $currency = strtoupper((string) ($event['currency'] ?? ''));
            $markets  = $this->affectedMarkets($currency);
            $isActive = $this->getActiveRiskWindow(
                \count($markets) > 0 ? $markets[0] : 'EUR/USD',
                $now
            ) !== null;

            $output[] = [
                'title'        => (string) ($event['title'] ?? ''),
                'currency'     => $currency,
                'impact'       => (string) ($event['impact'] ?? 'high'),
                'scheduled_at' => (string) $event['scheduled_at'],
                'markets'      => $markets,
                'is_active'    => $isActive,
            ];
        }

        usort($output, static fn(array $a, array $b): int =>
            strcmp($a['scheduled_at'], $b['scheduled_at'])
        );

        return $output;
    }

    private function affectsMarket(string $currency, string $marketLabel): bool
    {
        $currencies = self::MARKET_CURRENCIES[$marketLabel] ?? [];
        return \in_array($currency, $currencies, true);
    }

    private function affectedMarkets(string $currency): array
    {
        $markets = [];
        foreach (self::MARKET_CURRENCIES as $label => $currencies) {
            if (\in_array($currency, $currencies, true)) {
                $markets[] = $label;
            }
        }
        return $markets;
    }
}
