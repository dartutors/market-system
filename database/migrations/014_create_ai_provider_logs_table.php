<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

$pdo->exec("
CREATE TABLE IF NOT EXISTS ai_provider_logs (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_name       VARCHAR(100) NOT NULL,
    provider           VARCHAR(50)  NOT NULL,
    model              VARCHAR(100) NOT NULL DEFAULT '',
    prompt_tokens      INT UNSIGNED NOT NULL DEFAULT 0,
    completion_tokens  INT UNSIGNED NOT NULL DEFAULT 0,
    cost_usd           DECIMAL(10,6) NOT NULL DEFAULT 0,
    latency_ms         INT UNSIGNED NOT NULL DEFAULT 0,
    http_status        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    was_fallback_used  TINYINT(1)   NOT NULL DEFAULT 0,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_provider_logs_service  (service_name),
    INDEX idx_ai_provider_logs_provider (provider),
    INDEX idx_ai_provider_logs_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Migration 014: ai_provider_logs table created.' . PHP_EOL;
