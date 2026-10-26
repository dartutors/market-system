<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

// Additive only — Phase 2.3 Sprint B widens multi_timeframe_analyses with the
// pullback/entry-confirmation/AI-summary/recommendation fields produced by
// the new MultiTimeframeDecisionEngine. Existing rows get safe defaults;
// nothing that already reads breakdown_json/conflicts_json changes shape.
$pdo->exec("
    ALTER TABLE multi_timeframe_analyses
    ADD COLUMN IF NOT EXISTS pullback_json             TEXT         NULL                        AFTER conflicts_json,
    ADD COLUMN IF NOT EXISTS entry_confirmation_json    TEXT         NULL                        AFTER pullback_json,
    ADD COLUMN IF NOT EXISTS ai_summary_json            TEXT         NULL                        AFTER entry_confirmation_json,
    ADD COLUMN IF NOT EXISTS recommendation             VARCHAR(20)  NOT NULL DEFAULT 'NO_TRADE'  AFTER ai_summary_json,
    ADD COLUMN IF NOT EXISTS recommendation_confidence  DECIMAL(5,2) NOT NULL DEFAULT 0           AFTER recommendation
");

echo 'Migration 025: multi_timeframe_analyses widened with pullback/entry/AI-summary/recommendation fields.' . PHP_EOL;
