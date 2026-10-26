<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

/**
 * Phase 20 certification finding: news_items and calendar_events are each
 * written from two independent, unlocked entry points (cron AND an admin
 * "run now" button/endpoint) using a check-then-insert pattern
 * (existsByTitle()/exists() then create()) with no DB-level uniqueness
 * backing it — two near-simultaneous writers can both pass the existence
 * check before either commits, producing duplicate rows that then
 * double-count in NewsIntelligenceReport/MarketScannerReport. signal_events
 * already has a real UNIQUE KEY for exactly this reason (migration 022);
 * this closes the same gap here. Pre-flight-checked live before writing
 * this migration — zero duplicate titles/events existed, so this is a
 * pure additive safety net, not a data-loss risk.
 */

$existing = $pdo->query("SHOW INDEX FROM news_items WHERE Key_name = 'uq_news_items_title'")->fetch();
if (!$existing) {
    $pdo->exec("ALTER TABLE news_items ADD UNIQUE KEY uq_news_items_title (title(191))");
    echo 'Migration 038: news_items.title uniqueness added.' . PHP_EOL;
} else {
    echo 'Migration 038: news_items unique key already present — skipped.' . PHP_EOL;
}

$existing = $pdo->query("SHOW INDEX FROM calendar_events WHERE Key_name = 'uq_calendar_events_identity'")->fetch();
if (!$existing) {
    $pdo->exec("ALTER TABLE calendar_events ADD UNIQUE KEY uq_calendar_events_identity (title(191), currency, scheduled_at)");
    echo 'Migration 038: calendar_events (title, currency, scheduled_at) uniqueness added.' . PHP_EOL;
} else {
    echo 'Migration 038: calendar_events unique key already present — skipped.' . PHP_EOL;
}
