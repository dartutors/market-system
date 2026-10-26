<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Models\CalendarEvent;
use App\Services\CalendarRefreshService;

final class CalendarController extends Controller
{
    public function index(): void
    {
        require_login();
        $this->view('calendar/index', [
            'title'      => 'Economic Calendar',
            'csrf'       => Csrf::token(),
            'events'     => CalendarEvent::all(),
            'currencies' => CalendarEvent::validCurrencies(),
            'impacts'    => CalendarEvent::validImpacts(),
        ]);
    }

    public function store(): void
    {
        require_login();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        // strip_tags() as a server-side belt for what escaping the view/JS
        // layers is already responsible for — this title is stored verbatim
        // and rendered in more than one place (calendar list, reports,
        // dashboard's currency-detail modal), so it shouldn't depend on
        // every future render site remembering to escape it correctly.
        $title   = strip_tags(trim((string) ($_POST['title'] ?? '')));
        $currency = strtoupper(trim((string) ($_POST['currency'] ?? '')));
        $impact   = strtolower(trim((string) ($_POST['impact']   ?? 'high')));
        $rawDt    = trim((string) ($_POST['scheduled_at']  ?? ''));

        if ($title === '' || $currency === '' || $rawDt === '') {
            $_SESSION['flash_error'] = 'Title, currency, and date/time are required.';
            redirect(url('calendar'));
        }

        try {
            $tz          = new \DateTimeZone((string) env('APP_TIMEZONE', 'Africa/Dar_es_Salaam'));
            $dt          = new \DateTimeImmutable($rawDt, $tz);
            $scheduledAt = $dt->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            $_SESSION['flash_error'] = 'Invalid date/time format. Use YYYY-MM-DD HH:MM.';
            redirect(url('calendar'));
        }

        try {
            CalendarEvent::create([
                'title'        => $title,
                'currency'     => $currency,
                'impact'       => $impact,
                'scheduled_at' => $scheduledAt,
            ]);
            $_SESSION['flash_success'] = "Event \"{$title}\" added.";
        } catch (\InvalidArgumentException $e) {
            $_SESSION['flash_error'] = 'Validation error: ' . $e->getMessage();
        } catch (\Throwable $e) {
            error_log('[CalendarController] store failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Could not save event.';
        }

        redirect(url('calendar'));
    }

    /**
     * POST /calendar/refresh
     * Re-seeds the calendar_events table from EconomicCalendar config.
     * Inserts missing events, skips duplicates, removes events >30 days old.
     */
    public function refresh(): void
    {
        require_login();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        try {
            $result = (new CalendarRefreshService())->refresh(30);

            $inserted = (int) ($result['inserted']        ?? 0);
            $skipped  = (int) ($result['skipped']         ?? 0);
            $deleted  = (int) ($result['deleted_expired'] ?? 0);
            $errors   = (array) ($result['errors']        ?? []);

            $msg = "Calendar refreshed — {$inserted} added, {$skipped} already existed, {$deleted} expired events removed.";
            if (!empty($errors)) {
                $msg .= ' Warnings: ' . \count($errors);
            }

            $_SESSION['flash_success'] = $msg;

        } catch (\Throwable $e) {
            error_log('[CalendarController] refresh failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Calendar refresh failed. Check server logs.';
        }

        redirect(url('calendar'));
    }

    public function destroy(): void
    {
        require_login();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        $id = (int) ($_POST['event_id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['flash_error'] = 'Invalid event ID.';
            redirect(url('calendar'));
        }

        CalendarEvent::delete($id);
        $_SESSION['flash_success'] = 'Event deleted.';
        redirect(url('calendar'));
    }
}
