<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

try {
    // MariaDB 10.0.2+ / MySQL 8.0+ support IF NOT EXISTS for ADD COLUMN.
    // Wrapped in try/catch as a universal fallback for older engines.
    $pdo->exec(
        "ALTER TABLE news_items
         ADD COLUMN IF NOT EXISTS provider VARCHAR(50) NOT NULL DEFAULT 'unknown'
         AFTER market_bias"
    );
    echo 'Migration 007: provider column added (or already present) in news_items.' . PHP_EOL;
} catch (\PDOException $e) {
    // Older MySQL engines that do not support IF NOT EXISTS
    if (str_contains($e->getMessage(), 'Duplicate column')) {
        echo 'Migration 007: provider column already exists — skipped.' . PHP_EOL;
    } else {
        throw $e;
    }
}
