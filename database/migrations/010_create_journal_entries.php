<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

$pdo->exec("
CREATE TABLE IF NOT EXISTS journal_entries (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    signal_id  INT UNSIGNED NOT NULL,
    notes      TEXT         NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NULL,
    INDEX idx_journal_signal_id (signal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Migration 010: journal_entries table created.' . PHP_EOL;
