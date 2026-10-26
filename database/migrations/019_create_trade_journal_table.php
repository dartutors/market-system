<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

// Distinct from the existing journal_entries table (kept untouched) —
// trade_journal is the richer Phase 2 shape (outcome, P&L, screenshot link,
// lessons learned). All reference columns are indexed, nullable, logical
// references (no hard FK constraints), matching this codebase's convention.
$pdo->exec("
CREATE TABLE IF NOT EXISTS trade_journal (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    signal_id        INT UNSIGNED NULL,
    decision_id      INT UNSIGNED NULL,
    image_id         INT UNSIGNED NULL,
    entry_price      DECIMAL(18,6) NULL,
    exit_price       DECIMAL(18,6) NULL,
    profit_loss      DECIMAL(18,6) NULL,
    outcome          VARCHAR(20)  NULL,
    notes            TEXT         NULL,
    lessons_learned  TEXT         NULL,
    closed_at        DATETIME     NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at       DATETIME     NULL,
    INDEX idx_trade_journal_signal   (signal_id),
    INDEX idx_trade_journal_decision (decision_id),
    INDEX idx_trade_journal_image    (image_id),
    INDEX idx_trade_journal_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Migration 019: trade_journal table created.' . PHP_EOL;
