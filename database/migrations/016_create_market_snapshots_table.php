<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

$pdo->exec("
CREATE TABLE IF NOT EXISTS market_snapshots (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    symbol              VARCHAR(50)  NOT NULL,
    timeframe           VARCHAR(20)  NOT NULL,
    ohlc_json           TEXT         NOT NULL,
    indicators_json     TEXT         NOT NULL,
    trend               VARCHAR(20)  NOT NULL DEFAULT 'neutral',
    momentum            VARCHAR(20)  NOT NULL DEFAULT 'neutral',
    session_name        VARCHAR(20)  NOT NULL DEFAULT 'off',
    sentiment_score     DECIMAL(8,2) NOT NULL DEFAULT 0,
    economic_risk_json  TEXT         NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_market_snapshots_symbol   (symbol, timeframe),
    INDEX idx_market_snapshots_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Migration 016: market_snapshots table created.' . PHP_EOL;
