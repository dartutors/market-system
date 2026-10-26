<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

// The unique key on signal_events did not include `timeframe`, which blocks
// Multi-Timeframe Analysis: two different timeframes producing the same
// action/session/day for the same market would collide on INSERT.
//
// Pre-flight guard: if any environment already has duplicate rows under the
// new, wider key, abort instead of silently corrupting/dropping data. (In
// this environment, verified live before writing this migration: 0 rows in
// signal_events today, so this is a pure schema change here — but the guard
// makes the migration safe to hand to any environment that already has data.)
$dupeCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM (
        SELECT signal_date, market_label, timeframe, session_name, action
        FROM signal_events
        GROUP BY signal_date, market_label, timeframe, session_name, action
        HAVING COUNT(*) > 1
     ) AS dupes"
)->fetchColumn();

if ($dupeCount > 0) {
    echo "Migration 022: ABORTED — {$dupeCount} duplicate group(s) found under the "
       . "new key (signal_date, market_label, timeframe, session_name, action). "
       . "Resolve duplicates manually before widening the unique constraint." . PHP_EOL;
} else {
    // Single ALTER, atomic, and naturally re-runnable: every subsequent run
    // drops-then-recreates the same named key, so this migration is safe to
    // execute again (this project's migrate.php re-runs every file every time).
    $pdo->exec(
        'ALTER TABLE signal_events
         DROP INDEX uq_signal_daily_unique,
         ADD UNIQUE KEY uq_signal_daily_unique (signal_date, market_label, timeframe, session_name, action)'
    );

    // Supplementary index for future multi-timeframe queries (market +
    // timeframe lookups independent of the unique key's column order).
    try {
        $pdo->exec('CREATE INDEX idx_signal_market_timeframe ON signal_events (market_label, timeframe)');
    } catch (\Throwable $e) {
        // Already exists from a prior run — ignore, matches this codebase's
        // existing compatibility pattern (see migration 007).
    }

    echo 'Migration 022: signal_events unique key widened to include timeframe.' . PHP_EOL;
}
