<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

// Phase 2.2A: structurally mirrors ai_provider_logs (migration 014) — same
// "one row per external call, cheap rollup query" shape — but for market
// data provider calls (Deriv/Yahoo/cache) instead of AI calls. Nothing
// equivalent existed before this; App\MarketData\Manager\MarketDataManager
// is this table's only writer.
$pdo->exec("
CREATE TABLE IF NOT EXISTS market_data_provider_logs (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operation        VARCHAR(20)  NOT NULL,
    symbol           VARCHAR(50)  NOT NULL,
    timeframe        VARCHAR(20)  NULL,
    provider_used    VARCHAR(50)  NOT NULL,
    primary_provider VARCHAR(50)  NOT NULL,
    was_fallback     TINYINT(1)   NOT NULL DEFAULT 0,
    was_cache        TINYINT(1)   NOT NULL DEFAULT 0,
    success          TINYINT(1)   NOT NULL DEFAULT 1,
    latency_ms       INT UNSIGNED NOT NULL DEFAULT 0,
    error_message    TEXT         NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mdpl_provider (provider_used),
    INDEX idx_mdpl_symbol   (symbol),
    INDEX idx_mdpl_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Migration 028: market_data_provider_logs table created.' . PHP_EOL;
