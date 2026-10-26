<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Models\JournalEntry;
use App\Models\SignalEvent;

final class SignalController extends Controller
{
    /** POST /signal/outcome — marks a signal WIN / LOSS / BE / SKIP */
    public function updateOutcome(): void
    {
        require_login();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        $id      = (int) ($_POST['signal_id'] ?? 0);
        $outcome = strtolower(trim((string) ($_POST['outcome'] ?? '')));
        $notes   = trim((string) ($_POST['notes'] ?? ''));

        if ($id <= 0 || !\in_array($outcome, SignalEvent::validOutcomes(), true)) {
            $_SESSION['flash_error'] = 'Invalid signal or outcome value.';
            redirect(url('dashboard'));
        }

        $signal = SignalEvent::findById($id);
        if ($signal === null) {
            $_SESSION['flash_error'] = 'Signal not found.';
            redirect(url('dashboard'));
        }

        SignalEvent::markOutcome($id, $outcome, $notes);

        $_SESSION['flash_success'] = "Signal #{$id} marked as " . strtoupper($outcome) . '.';
        redirect(url('dashboard'));
    }

    /** POST /signal/journal — saves a free-text journal note for a signal */
    public function saveJournal(): void
    {
        require_login();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        $id    = (int) ($_POST['signal_id'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($id <= 0) {
            $_SESSION['flash_error'] = 'Invalid signal ID.';
            redirect(url('dashboard'));
        }

        if ($notes === '') {
            redirect(url('dashboard'));
        }

        JournalEntry::save($id, $notes);
        $_SESSION['flash_success'] = 'Journal note saved.';
        redirect(url('dashboard'));
    }
}
