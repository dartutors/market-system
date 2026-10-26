<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Security;

/**
 * Ensures the admin user configured in .env exists and is active.
 * Idempotent: creates if missing, updates password if present.
 * This fixes the admin email mismatch between .env and the DB seed.
 */
$pdo = Database::connection();

$envEmail    = strtolower(trim((string) env('ADMIN_EMAIL',    '')));
$envPassword = trim((string) env('ADMIN_PASSWORD', ''));
$envName     = trim((string) env('ADMIN_NAME',     'Private Admin'));

if ($envEmail === '' || $envPassword === '') {
    echo 'Migration 013: ADMIN_EMAIL or ADMIN_PASSWORD not set in .env — skipped.' . PHP_EOL;
    return;
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$stmt->execute(['email' => $envEmail]);
$existing = $stmt->fetch();

if ($existing) {
    // Admin already exists — refresh password in case .env changed
    $update = $pdo->prepare(
        'UPDATE users SET password_hash = :hash, full_name = :name, is_admin = 1, is_active = 1 WHERE email = :email'
    );
    $update->execute([
        'hash'  => Security::hashPassword($envPassword),
        'name'  => $envName,
        'email' => $envEmail,
    ]);
    echo "Migration 013: Admin credentials refreshed for {$envEmail}." . PHP_EOL;
} else {
    $insert = $pdo->prepare(
        'INSERT INTO users (email, password_hash, full_name, is_admin, is_active, created_at)
         VALUES (:email, :hash, :name, 1, 1, NOW())'
    );
    $insert->execute([
        'email' => $envEmail,
        'hash'  => Security::hashPassword($envPassword),
        'name'  => $envName,
    ]);
    echo "Migration 013: Admin user created for {$envEmail}." . PHP_EOL;
}
