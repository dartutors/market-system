<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

/**
 * Phase 20 certification finding: JournalEntry::save() was a check-then-act
 * upsert (findBySignalId() then UPDATE-or-INSERT) with only a plain, non-
 * unique index on signal_id — a double form-submit could create two rows
 * for the same signal_id, and findBySignalId()'s LIMIT 1 would then
 * non-deterministically hide one of them. App\Models\Setting::set() already
 * does this correctly elsewhere in this codebase via a real
 * INSERT ... ON DUPLICATE KEY UPDATE against a unique key — this brings
 * journal_entries in line with that proven pattern. Pre-flight-checked live:
 * zero duplicate signal_id rows existed, so this is purely additive.
 */

$existing = $pdo->query("SHOW INDEX FROM journal_entries WHERE Key_name = 'uq_journal_entries_signal_id'")->fetch();
if (!$existing) {
    $pdo->exec("ALTER TABLE journal_entries ADD UNIQUE KEY uq_journal_entries_signal_id (signal_id)");
    echo 'Migration 040: journal_entries.signal_id uniqueness added.' . PHP_EOL;
} else {
    echo 'Migration 040: journal_entries unique key already present — skipped.' . PHP_EOL;
}
