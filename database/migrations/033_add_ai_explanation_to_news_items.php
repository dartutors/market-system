<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

// Section 8 "click a headline for its AI-explained likely market impact" —
// cached on the row itself so a second click (by this user or anyone else)
// never re-bills the AI provider for the same headline. Nullable: most rows
// will never be clicked and stay unexplained forever, which is fine.
$pdo->exec("
    ALTER TABLE news_items
    ADD COLUMN IF NOT EXISTS ai_explanation             TEXT     NULL AFTER provider,
    ADD COLUMN IF NOT EXISTS ai_explanation_provider     VARCHAR(50) NULL AFTER ai_explanation,
    ADD COLUMN IF NOT EXISTS ai_explanation_generated_at DATETIME NULL AFTER ai_explanation_provider
");

echo 'Migration 033: ai_explanation columns added (or already present) on news_items.' . PHP_EOL;
