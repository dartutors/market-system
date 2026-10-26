<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

// Additive only. Phase 2.9's PatternRecognitionEngine result, run against
// live candle data for the hinted (or AI-detected) pair/timeframe, stored
// separately from chart_patterns_json (the LLM's own screenshot-based read)
// so the two are never conflated — one is an algorithmic geometry check on
// real OHLC data, the other is a vision-model read of the uploaded image.
$pdo->exec("
    ALTER TABLE chart_analysis
    ADD COLUMN IF NOT EXISTS chart_patterns_algorithmic_json TEXT NULL AFTER chart_patterns_json
");

echo 'Migration 029: chart_analysis widened with chart_patterns_algorithmic_json.' . PHP_EOL;
