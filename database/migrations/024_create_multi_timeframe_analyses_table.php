<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

$pdo->exec("
CREATE TABLE IF NOT EXISTS multi_timeframe_analyses (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    market_label   VARCHAR(50)  NOT NULL,
    overall_bias   VARCHAR(20)  NOT NULL DEFAULT 'neutral',
    confidence     DECIMAL(5,2) NOT NULL DEFAULT 0,
    breakdown_json TEXT         NOT NULL,
    conflicts_json TEXT         NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mta_market  (market_label),
    INDEX idx_mta_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Migration 024: multi_timeframe_analyses table created.' . PHP_EOL;
