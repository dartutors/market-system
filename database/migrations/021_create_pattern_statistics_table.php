<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

$pdo->exec("
CREATE TABLE IF NOT EXISTS pattern_statistics (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pattern_name VARCHAR(100) NOT NULL,
    market_label VARCHAR(50)  NOT NULL,
    timeframe    VARCHAR(20)  NOT NULL,
    occurrences  INT UNSIGNED NOT NULL DEFAULT 0,
    win_rate     DECIMAL(5,2) NOT NULL DEFAULT 0,
    avg_r        DECIMAL(8,2) NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pattern_statistics_pattern (pattern_name),
    INDEX idx_pattern_statistics_market  (market_label, timeframe)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Migration 021: pattern_statistics table created.' . PHP_EOL;
