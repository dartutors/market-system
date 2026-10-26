<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

$pdo->exec("
CREATE TABLE IF NOT EXISTS uploaded_charts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    market_label VARCHAR(50)  NOT NULL DEFAULT '',
    timeframe    VARCHAR(20)  NOT NULL DEFAULT '',
    file_path    VARCHAR(500) NOT NULL,
    mime_type    VARCHAR(100) NOT NULL DEFAULT '',
    width        INT UNSIGNED NOT NULL DEFAULT 0,
    height       INT UNSIGNED NOT NULL DEFAULT 0,
    source       VARCHAR(50)  NOT NULL DEFAULT 'manual_upload',
    uploaded_by  INT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at   DATETIME     NULL,
    INDEX idx_uploaded_charts_market   (market_label, timeframe),
    INDEX idx_uploaded_charts_uploader (uploaded_by),
    INDEX idx_uploaded_charts_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Migration 017: uploaded_charts table created.' . PHP_EOL;
