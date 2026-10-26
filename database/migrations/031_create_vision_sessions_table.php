<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

// Phase 3.2 — AI Vision Multi-Timeframe Workspace. One row per multi-timeframe
// analysis run, mirroring backtest_summaries'/multi_timeframe_analyses' shape
// (one row = one combined-decision record with a JSON payload). Individual
// per-timeframe uploads/analyses still live in uploaded_charts/chart_analysis
// exactly as before — this table only stores the COMBINED top-down result.
$pdo->exec("
CREATE TABLE IF NOT EXISTS vision_sessions (
    id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    market_label             VARCHAR(40)  NOT NULL,
    timeframes_uploaded_json TEXT         NOT NULL,
    completeness_score       DECIMAL(5,2) NOT NULL DEFAULT 0,
    overall_trend            VARCHAR(20)  NOT NULL DEFAULT 'neutral',
    decision                 VARCHAR(20)  NOT NULL DEFAULT 'NO_TRADE',
    confidence                DECIMAL(5,2) NOT NULL DEFAULT 0,
    risk_level               VARCHAR(20)  NOT NULL DEFAULT 'medium',
    synthesis_json           TEXT         NOT NULL,
    provider                 VARCHAR(50)  NOT NULL DEFAULT 'rule_based',
    created_by               INT UNSIGNED NULL,
    created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vision_sessions_market  (market_label),
    INDEX idx_vision_sessions_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Migration 031: vision_sessions table created.' . PHP_EOL;
