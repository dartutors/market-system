<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

// image_id is a logical reference to uploaded_charts.id (indexed, no hard FK
// constraint) — matching this codebase's existing convention (see
// journal_entries.signal_id, ai_summaries, etc.), which favors indexed
// reference columns over MySQL FOREIGN KEY constraints throughout.
$pdo->exec("
CREATE TABLE IF NOT EXISTS chart_analysis (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    image_id              INT UNSIGNED NOT NULL,
    provider              VARCHAR(50)  NOT NULL DEFAULT 'rule_based',
    model                 VARCHAR(100) NOT NULL DEFAULT '',
    support_levels_json   TEXT         NULL,
    resistance_levels_json TEXT        NULL,
    trendlines_json       TEXT         NULL,
    liquidity_zones_json  TEXT         NULL,
    confidence            DECIMAL(5,2) NOT NULL DEFAULT 0,
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chart_analysis_image   (image_id),
    INDEX idx_chart_analysis_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Migration 018: chart_analysis table created.' . PHP_EOL;
