<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

/**
 * Phase 20 certification finding: NewsIntelligenceReport/TradingPerformanceReport/
 * MarketScannerReport all filter on `created_at BETWEEN :from AND :to` against
 * news_items and signal_events, neither of which had a supporting index —
 * every report render was a full table scan. ai_provider_logs already has
 * this same index (migration 014), which is why AiUsageReport (identical
 * query shape) was never flagged. Currently low-impact at this app's actual
 * row counts, but cheap to fix now rather than as table size grows.
 */

$indexExists = $pdo->query("SHOW INDEX FROM news_items WHERE Key_name = 'idx_news_items_created_at'")->fetch();
if (!$indexExists) {
    $pdo->exec("ALTER TABLE news_items ADD INDEX idx_news_items_created_at (created_at)");
    echo 'Migration 039: news_items.created_at indexed.' . PHP_EOL;
} else {
    echo 'Migration 039: news_items.created_at index already present — skipped.' . PHP_EOL;
}

$indexExists = $pdo->query("SHOW INDEX FROM signal_events WHERE Key_name = 'idx_signal_events_created_at'")->fetch();
if (!$indexExists) {
    $pdo->exec("ALTER TABLE signal_events ADD INDEX idx_signal_events_created_at (created_at)");
    echo 'Migration 039: signal_events.created_at indexed.' . PHP_EOL;
} else {
    echo 'Migration 039: signal_events.created_at index already present — skipped.' . PHP_EOL;
}
