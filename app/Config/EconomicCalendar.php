<?php
declare(strict_types=1);

namespace App\Config;

/**
 * Manual high-impact economic event calendar.
 *
 * All times are in Africa/Dar_es_Salaam (EAT = UTC+3).
 *
 * HOW TO UPDATE:
 *   1. Add new events to the array below.
 *   2. Run: php cron/calendar_refresh.php
 *      OR click "Refresh Calendar" on the /calendar page.
 *   3. Expired events (>30 days past) are deleted automatically on refresh.
 *
 * Time conversion reference (EAT = UTC+3):
 *   US data (EST/EDT):   15:30 EAT  (08:30 ET)  | 21:00 EAT (14:00 ET)
 *   UK data (BST):       09:00 EAT  (07:00 BST)
 *   Eurozone (CEST):     12:00 EAT  (11:00 CEST) | 16:15 EAT (15:15 CEST)
 *   BOJ (JST = UTC+9):   06:00 EAT  (09:00 JST)
 *
 * Impact levels: 'high' = blocks signals, 'medium'/'low' = informational only.
 * Only include HIGH-impact events that move markets significantly.
 */
final class EconomicCalendar
{
    public static function events(): array
    {
        return [

            // ══════════════════════════════════════════════════
            // APRIL 2026 (remaining)
            // ══════════════════════════════════════════════════

            [
                'title'        => 'BOJ Monetary Policy Statement',
                'currency'     => 'JPY',
                'impact'       => 'high',
                'scheduled_at' => '2026-04-30 07:00:00',
            ],
            [
                'title'        => 'US GDP (Advance Estimate Q1 2026)',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-04-30 15:30:00',
            ],

            // ══════════════════════════════════════════════════
            // MAY 2026
            // ══════════════════════════════════════════════════

            [
                'title'        => 'US Non-Farm Payrolls (NFP)',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-05-01 15:30:00',
            ],
            [
                'title'        => 'US Unemployment Rate',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-05-01 15:30:00',
            ],
            [
                'title'        => 'FOMC Interest Rate Decision',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-05-06 21:00:00',
            ],
            [
                'title'        => 'FOMC Press Conference',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-05-06 21:30:00',
            ],
            [
                'title'        => 'BOE Interest Rate Decision',
                'currency'     => 'GBP',
                'impact'       => 'high',
                'scheduled_at' => '2026-05-08 15:00:00',
            ],
            [
                'title'        => 'UK GDP (Monthly)',
                'currency'     => 'GBP',
                'impact'       => 'high',
                'scheduled_at' => '2026-05-13 09:00:00',
            ],
            [
                'title'        => 'US CPI (Consumer Price Index)',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-05-13 15:30:00',
            ],
            [
                'title'        => 'US Retail Sales',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-05-15 15:30:00',
            ],
            [
                'title'        => 'UK CPI (Consumer Price Index)',
                'currency'     => 'GBP',
                'impact'       => 'high',
                'scheduled_at' => '2026-05-21 09:00:00',
            ],
            [
                'title'        => 'ECB Interest Rate Decision',
                'currency'     => 'EUR',
                'impact'       => 'high',
                'scheduled_at' => '2026-05-21 16:15:00',
            ],
            [
                'title'        => 'ECB Press Conference',
                'currency'     => 'EUR',
                'impact'       => 'high',
                'scheduled_at' => '2026-05-21 16:45:00',
            ],
            [
                'title'        => 'BOJ Rate Decision',
                'currency'     => 'JPY',
                'impact'       => 'high',
                'scheduled_at' => '2026-05-22 06:00:00',
            ],
            [
                'title'        => 'Eurozone CPI Flash Estimate',
                'currency'     => 'EUR',
                'impact'       => 'high',
                'scheduled_at' => '2026-05-29 12:00:00',
            ],
            [
                'title'        => 'US Core PCE Price Index',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-05-29 15:30:00',
            ],

            // ══════════════════════════════════════════════════
            // JUNE 2026
            // ══════════════════════════════════════════════════

            [
                'title'        => 'US Non-Farm Payrolls (NFP)',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-06-05 15:30:00',
            ],
            [
                'title'        => 'US Unemployment Rate',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-06-05 15:30:00',
            ],
            [
                'title'        => 'UK GDP (Monthly)',
                'currency'     => 'GBP',
                'impact'       => 'high',
                'scheduled_at' => '2026-06-12 09:00:00',
            ],
            [
                'title'        => 'US CPI (Consumer Price Index)',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-06-10 15:30:00',
            ],
            [
                'title'        => 'UK CPI (Consumer Price Index)',
                'currency'     => 'GBP',
                'impact'       => 'high',
                'scheduled_at' => '2026-06-17 09:00:00',
            ],
            [
                'title'        => 'FOMC Interest Rate Decision',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-06-17 21:00:00',
            ],
            [
                'title'        => 'FOMC Press Conference',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-06-17 21:30:00',
            ],
            [
                'title'        => 'BOE Interest Rate Decision',
                'currency'     => 'GBP',
                'impact'       => 'high',
                'scheduled_at' => '2026-06-18 15:00:00',
            ],
            [
                'title'        => 'BOJ Rate Statement',
                'currency'     => 'JPY',
                'impact'       => 'high',
                'scheduled_at' => '2026-06-19 07:00:00',
            ],
            [
                'title'        => 'Eurozone CPI Flash Estimate',
                'currency'     => 'EUR',
                'impact'       => 'high',
                'scheduled_at' => '2026-06-30 12:00:00',
            ],
            [
                'title'        => 'US Core PCE Price Index',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-06-26 15:30:00',
            ],

            // ══════════════════════════════════════════════════
            // JULY 2026
            // ══════════════════════════════════════════════════

            [
                'title'        => 'US Non-Farm Payrolls (NFP)',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-07-02 15:30:00',
            ],
            [
                'title'        => 'US Unemployment Rate',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-07-02 15:30:00',
            ],
            [
                'title'        => 'ECB Interest Rate Decision',
                'currency'     => 'EUR',
                'impact'       => 'high',
                'scheduled_at' => '2026-07-16 16:15:00',
            ],
            [
                'title'        => 'ECB Press Conference',
                'currency'     => 'EUR',
                'impact'       => 'high',
                'scheduled_at' => '2026-07-16 16:45:00',
            ],
            [
                'title'        => 'US CPI (Consumer Price Index)',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-07-15 15:30:00',
            ],
            [
                'title'        => 'UK CPI (Consumer Price Index)',
                'currency'     => 'GBP',
                'impact'       => 'high',
                'scheduled_at' => '2026-07-15 09:00:00',
            ],
            [
                'title'        => 'BOJ Rate Decision',
                'currency'     => 'JPY',
                'impact'       => 'high',
                'scheduled_at' => '2026-07-28 07:00:00',
            ],
            [
                'title'        => 'FOMC Interest Rate Decision',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-07-29 21:00:00',
            ],
            [
                'title'        => 'FOMC Press Conference',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-07-29 21:30:00',
            ],
            [
                'title'        => 'US Core PCE Price Index',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-07-31 15:30:00',
            ],
            [
                'title'        => 'US GDP (Advance Estimate Q2 2026)',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-07-30 15:30:00',
            ],

            // ══════════════════════════════════════════════════
            // AUGUST 2026
            // ══════════════════════════════════════════════════

            [
                'title'        => 'US Non-Farm Payrolls (NFP)',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-08-07 15:30:00',
            ],
            [
                'title'        => 'US Unemployment Rate',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-08-07 15:30:00',
            ],
            [
                'title'        => 'BOE Interest Rate Decision',
                'currency'     => 'GBP',
                'impact'       => 'high',
                'scheduled_at' => '2026-08-06 15:00:00',
            ],
            [
                'title'        => 'US CPI (Consumer Price Index)',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-08-12 15:30:00',
            ],
            [
                'title'        => 'UK CPI (Consumer Price Index)',
                'currency'     => 'GBP',
                'impact'       => 'high',
                'scheduled_at' => '2026-08-19 09:00:00',
            ],
            [
                'title'        => 'Eurozone CPI Flash Estimate',
                'currency'     => 'EUR',
                'impact'       => 'high',
                'scheduled_at' => '2026-08-31 12:00:00',
            ],
            [
                'title'        => 'US Core PCE Price Index',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-08-28 15:30:00',
            ],
            [
                'title'        => 'Jackson Hole Symposium — Fed Chair Speech',
                'currency'     => 'USD',
                'impact'       => 'high',
                'scheduled_at' => '2026-08-27 18:00:00',
            ],
        ];
    }
}
