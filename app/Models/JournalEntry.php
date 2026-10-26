<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

final class JournalEntry
{
    /**
     * Inserts or replaces the journal note for a signal — atomic against
     * uq_journal_entries_signal_id (migration 040) instead of the previous
     * check-then-act (findBySignalId() then UPDATE-or-INSERT), which could
     * produce two rows for the same signal_id on a double form-submit.
     */
    public static function save(int $signalId, string $notes): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO journal_entries (signal_id, notes, created_at)
             VALUES (:signal_id, :notes, NOW())
             ON DUPLICATE KEY UPDATE notes = VALUES(notes), updated_at = NOW()'
        );
        $stmt->execute(['signal_id' => $signalId, 'notes' => $notes]);
    }

    public static function findBySignalId(int $signalId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM journal_entries WHERE signal_id = :signal_id LIMIT 1'
        );
        $stmt->bindValue(':signal_id', $signalId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Returns all journal entries keyed by signal_id for efficient lookup. */
    public static function indexedBySignalId(array $signalIds): array
    {
        if (empty($signalIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($signalIds), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT * FROM journal_entries WHERE signal_id IN ({$placeholders})"
        );
        $stmt->execute(array_values($signalIds));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row['signal_id']] = $row;
        }
        return $indexed;
    }
}
