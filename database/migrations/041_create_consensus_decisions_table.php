<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

/**
 * AI Consensus Engine (Objective 2): one row per computed consensus decision
 * for a configured market — fuses Technical/Claude/OpenAI/Vision/News/
 * Calendar/CurrencyStrength/Session/Volatility/TrendAlignment into one
 * scored decision. Computed by App\Consensus\ConsensusEngine, persisted here
 * (not recomputed live on dashboard load — same "cron computes, dashboard
 * reads" convention as signal_events/ai_summaries/vision_sessions), read by
 * DashboardController for the Market Quality Score / Trade Today / Best
 * Opportunity widgets. JSON payloads stored as TEXT, matching every other
 * table in this schema (no real MySQL JSON column type used anywhere here).
 */
$pdo->exec("
CREATE TABLE IF NOT EXISTS consensus_decisions (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    market_label          VARCHAR(40)   NOT NULL,
    market_symbol         VARCHAR(50)   NOT NULL,
    overall_decision      VARCHAR(20)   NOT NULL DEFAULT 'WAIT',
    consensus_score       DECIMAL(5,2)  NOT NULL DEFAULT 0,
    agreement_percentage  DECIMAL(5,2)  NOT NULL DEFAULT 0,
    market_quality_score  DECIMAL(5,2)  NOT NULL DEFAULT 0,
    risk_level            VARCHAR(20)   NOT NULL DEFAULT 'MEDIUM',
    sources_json          TEXT          NOT NULL,
    disagreement_json     TEXT          NOT NULL,
    trade_plan_json       TEXT          NOT NULL,
    generated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_consensus_decisions_market  (market_label),
    INDEX idx_consensus_decisions_created (generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Migration 041: consensus_decisions table created.' . PHP_EOL;
