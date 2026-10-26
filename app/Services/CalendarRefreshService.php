<?php
declare(strict_types=1);
namespace App\Services;

use App\Config\EconomicCalendar;
use App\Models\CalendarEvent;

/**
 * Refreshes the calendar_events DB table from the static EconomicCalendar config.
 *
 * Logic:
 *   1. Delete events older than $expiredDays days (default 30).
 *   2. For each event in EconomicCalendar::events():
 *      - Skip if already in DB (matched by title + currency + scheduled_at).
 *      - Insert if missing.
 *
 * Returns a summary array used by both the cron script and the web controller.
 */
final class CalendarRefreshService
{
    public function refresh(int $expiredDays = 30): array
    {
        $inserted       = 0;
        $skipped        = 0;
        $deletedExpired = 0;
        $errors         = [];

        // ── Step 1: Remove expired events ─────────────────────────────────────
        try {
            $deletedExpired = CalendarEvent::deleteExpired($expiredDays);
        } catch (\Throwable $e) {
            $errors[] = 'Delete-expired failed: ' . $e->getMessage();
            error_log('[CalendarRefreshService] ' . $e->getMessage());
        }

        // ── Step 2: Seed from static config ───────────────────────────────────
        foreach (EconomicCalendar::events() as $ev) {
            $title       = trim((string) ($ev['title']        ?? ''));
            $currency    = strtoupper(trim((string) ($ev['currency'] ?? '')));
            $scheduledAt = (string) ($ev['scheduled_at'] ?? '');
            $impact      = strtolower(trim((string) ($ev['impact'] ?? 'high')));

            if ($title === '' || $currency === '' || $scheduledAt === '') {
                $errors[] = "Skipped malformed event: " . json_encode($ev);
                continue;
            }

            try {
                if (CalendarEvent::exists($title, $currency, $scheduledAt)) {
                    $skipped++;
                } else {
                    CalendarEvent::create([
                        'title'        => $title,
                        'currency'     => $currency,
                        'impact'       => $impact,
                        'scheduled_at' => $scheduledAt,
                    ]);
                    $inserted++;
                }
            } catch (\Throwable $e) {
                $errors[] = "Failed to insert [{$title}]: " . $e->getMessage();
                error_log('[CalendarRefreshService] Insert failed: ' . $e->getMessage());
            }
        }

        return [
            'status'          => empty($errors) ? 'ok' : 'partial',
            'inserted'        => $inserted,
            'skipped'         => $skipped,
            'deleted_expired' => $deletedExpired,
            'errors'          => $errors,
        ];
    }
}
