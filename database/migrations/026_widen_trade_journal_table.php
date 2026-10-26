<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

// Additive only — Phase 2.6 activates the previously-unused trade_journal
// table/model. Widened so a journal entry can stand entirely on its own
// (manual logging, no signal_id/decision_id required) while still carrying
// enough context (market_label, direction, session_name) for the
// pair/session analytics the phase calls for, plus mistake tagging and a
// source marker distinguishing manual entries from ones logged off an
// existing signal or AI decision.
$pdo->exec("
    ALTER TABLE trade_journal
    ADD COLUMN IF NOT EXISTS market_label       VARCHAR(50)  NULL                      AFTER image_id,
    ADD COLUMN IF NOT EXISTS direction          VARCHAR(10)  NULL                      AFTER market_label,
    ADD COLUMN IF NOT EXISTS session_name       VARCHAR(20)  NULL                      AFTER direction,
    ADD COLUMN IF NOT EXISTS source             VARCHAR(20)  NOT NULL DEFAULT 'manual' AFTER direction,
    ADD COLUMN IF NOT EXISTS pips               DECIMAL(10,2) NULL                     AFTER profit_loss,
    ADD COLUMN IF NOT EXISTS mistake_tags_json  TEXT         NULL                      AFTER lessons_learned,
    ADD COLUMN IF NOT EXISTS updated_at         DATETIME     NULL                      AFTER created_at
");

// MariaDB (this project's DB engine) has no ADD INDEX IF NOT EXISTS — same
// try/catch-per-index pattern as migration 022's idx_signal_market_timeframe.
foreach ([
    'idx_trade_journal_market'  => 'market_label',
    'idx_trade_journal_session' => 'session_name',
    'idx_trade_journal_outcome' => 'outcome',
] as $indexName => $column) {
    try {
        $pdo->exec("CREATE INDEX {$indexName} ON trade_journal ({$column})");
    } catch (\Throwable $e) {
        // Already exists from a prior run — ignore.
    }
}

echo 'Migration 026: trade_journal widened with market/direction/session/source/pips/mistake-tags/updated_at fields.' . PHP_EOL;
