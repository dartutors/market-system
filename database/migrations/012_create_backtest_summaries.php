<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

$pdo->exec("
CREATE TABLE IF NOT EXISTS backtest_summaries (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    symbol       VARCHAR(20)  NOT NULL,
    timeframe    VARCHAR(20)  NOT NULL,
    period_days  INT          NOT NULL,
    decision     VARCHAR(20)  NOT NULL,
    confidence   DECIMAL(5,2) NOT NULL DEFAULT 0,
    summary_text TEXT         NOT NULL,
    stats_json   TEXT         NOT NULL,
    provider     VARCHAR(50)  NOT NULL DEFAULT 'rule_based',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bt_summaries_symbol    (symbol),
    INDEX idx_bt_summaries_created   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Migration 012: backtest_summaries table created.' . PHP_EOL;
