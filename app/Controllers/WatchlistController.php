<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\MarketOverview\InstrumentCatalog;
use App\Models\WatchlistItem;

/**
 * Section 12 "Watchlist" — pin/unpin toggle, AJAX-triggered from the Live
 * Market Overview table's star icon. Only accepts symbols that exist in
 * InstrumentCatalog (the same 22-instrument list Section 3 already vets and
 * displays) — not an arbitrary free-text symbol, so nothing unverifiable
 * ever lands in the table.
 */
final class WatchlistController extends Controller
{
    public function toggle(): void
    {
        require_login();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $this->json(['error' => 'Invalid CSRF token.'], 419);
            return;
        }

        $symbol = trim((string) ($_POST['symbol'] ?? ''));
        $instrument = null;
        foreach (InstrumentCatalog::all() as $candidate) {
            if ($candidate['symbol'] === $symbol) {
                $instrument = $candidate;
                break;
            }
        }
        if ($instrument === null) {
            $this->json(['error' => 'Unknown instrument.'], 422);
            return;
        }

        if (WatchlistItem::isWatched($symbol)) {
            WatchlistItem::removeBySymbol($symbol);
            $this->json(['watching' => false]);
            return;
        }

        WatchlistItem::add($symbol, $instrument['label'], $instrument['category'], $_SESSION['user_id'] ?? null);
        $this->json(['watching' => true]);
    }
}
