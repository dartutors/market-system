<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

$pdo->exec("
CREATE TABLE IF NOT EXISTS strategy_statistics (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    market_label    VARCHAR(50)  NOT NULL,
    timeframe       VARCHAR(20)  NOT NULL,
    strategy_tag    VARCHAR(100) NOT NULL DEFAULT 'default',
    period_start    DATE         NOT NULL,
    period_end      DATE         NOT NULL,
    win_rate        DECIMAL(5,2) NOT NULL DEFAULT 0,
    avg_r           DECIMAL(8,2) NOT NULL DEFAULT 0,
    max_drawdown_r  DECIMAL(8,2) NOT NULL DEFAULT 0,
    sample_size     INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_strategy_statistics_market (market_label, timeframe),
    INDEX idx_strategy_statistics_period (period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Migration 020: strategy_statistics table created.' . PHP_EOL;
