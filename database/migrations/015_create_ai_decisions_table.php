<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

// snapshot_id is a soft reference (no FK constraint) to the future
// market_snapshots table (added later in this same migration batch) —
// nothing persists market_snapshots rows yet, so the FK is deferred rather
// than forced into a specific migration order.
$pdo->exec("
CREATE TABLE IF NOT EXISTS ai_decisions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    market_label VARCHAR(50)  NOT NULL,
    timeframe    VARCHAR(20)  NOT NULL,
    decision     ENUM('BUY','SELL','WAIT','NO_TRADE') NOT NULL DEFAULT 'NO_TRADE',
    confidence   DECIMAL(5,2) NOT NULL DEFAULT 0,
    reasoning    TEXT         NOT NULL,
    conflicts    TEXT         NULL,
    risk_level   VARCHAR(20)  NOT NULL DEFAULT 'medium',
    provider     VARCHAR(50)  NOT NULL DEFAULT 'decision_engine',
    snapshot_id  INT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_decisions_market   (market_label, timeframe),
    INDEX idx_ai_decisions_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Migration 015: ai_decisions table created.' . PHP_EOL;
