<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

// Additive only — chart_analysis was created last sprint with zero real
// consumers yet, so widening it here carries no migration risk.
// uploaded_charts.market_label/timeframe already exist and hold the
// detected (or user-hinted) pair/timeframe; no new columns needed there.
$pdo->exec("
    ALTER TABLE chart_analysis
    ADD COLUMN IF NOT EXISTS trend                     VARCHAR(20)  NOT NULL DEFAULT 'neutral'  AFTER model,
    ADD COLUMN IF NOT EXISTS indicators_observed_json   TEXT         NULL                        AFTER trend,
    ADD COLUMN IF NOT EXISTS candlestick_patterns_json  TEXT         NULL                        AFTER liquidity_zones_json,
    ADD COLUMN IF NOT EXISTS chart_patterns_json        TEXT         NULL                        AFTER candlestick_patterns_json,
    ADD COLUMN IF NOT EXISTS summary                    TEXT         NULL                        AFTER chart_patterns_json,
    ADD COLUMN IF NOT EXISTS recommendation_decision    VARCHAR(20)  NOT NULL DEFAULT 'NO_TRADE'  AFTER summary,
    ADD COLUMN IF NOT EXISTS recommendation_confidence  DECIMAL(5,2) NOT NULL DEFAULT 0           AFTER recommendation_decision,
    ADD COLUMN IF NOT EXISTS recommendation_reasoning   TEXT         NULL                        AFTER recommendation_confidence
");

echo 'Migration 023: chart_analysis widened with detection/recommendation fields.' . PHP_EOL;
