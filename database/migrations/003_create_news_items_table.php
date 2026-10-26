<?php
declare(strict_types=1);
use App\Core\Database;
$pdo = Database::connection();
$pdo->exec("CREATE TABLE IF NOT EXISTS news_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(500) NOT NULL,
    source VARCHAR(255) NOT NULL DEFAULT 'unknown',
    url VARCHAR(1000) NOT NULL DEFAULT '',
    published_at DATETIME NULL,
    raw_sentiment DECIMAL(8,2) NOT NULL DEFAULT 0,
    impact_score DECIMAL(8,2) NOT NULL DEFAULT 0,
    market_bias VARCHAR(50) NOT NULL DEFAULT 'neutral',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_news_title (title(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
