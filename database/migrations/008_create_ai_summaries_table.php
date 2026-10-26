<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

$pdo->exec("
CREATE TABLE IF NOT EXISTS ai_summaries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider        VARCHAR(50)  NOT NULL,
    model           VARCHAR(100) NOT NULL,
    market_scope    VARCHAR(100) NOT NULL DEFAULT 'ALL',
    summary_text    TEXT         NOT NULL,
    market_bias     VARCHAR(50)  NOT NULL DEFAULT 'mixed',
    confidence      DECIMAL(8,2) NOT NULL DEFAULT 0,
    headline_hash   CHAR(64)     NOT NULL,
    pair_notes      TEXT         NULL DEFAULT NULL,
    risk_note       VARCHAR(500) NOT NULL DEFAULT '',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_summaries_hash       (headline_hash),
    INDEX idx_ai_summaries_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Migration 008: ai_summaries table created (or already present).' . PHP_EOL;
