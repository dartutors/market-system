<?php
declare(strict_types=1);

use App\Core\Database;

$pdo = Database::connection();

// Only the token HASH is ever stored — the raw token exists only in the
// emailed link, never at rest, so a database compromise alone can't yield
// a usable reset token.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS password_resets (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        email       VARCHAR(255) NOT NULL,
        token_hash  CHAR(64)     NOT NULL,
        expires_at  DATETIME     NOT NULL,
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_password_resets_token_hash (token_hash),
        KEY idx_password_resets_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

echo 'Migration 037: password_resets table created (or already present).' . PHP_EOL;
