<?php
declare(strict_types=1);
use App\Core\Database;
use App\Core\Security;
$pdo = Database::connection();
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$stmt->execute(['email' => strtolower(trim((string) env('ADMIN_EMAIL', 'admin@example.com')))]);
$existing = $stmt->fetch();
if (!$existing) {
    $insert = $pdo->prepare('INSERT INTO users (email, password_hash, full_name, is_admin, is_active) VALUES (:email, :password_hash, :full_name, 1, 1)');
    $insert->execute([
        'email' => strtolower(trim((string) env('ADMIN_EMAIL', 'admin@example.com'))),
        'password_hash' => Security::hashPassword((string) env('ADMIN_PASSWORD', 'ChangeMe123!Strong')),
        'full_name' => (string) env('ADMIN_NAME', 'Private Admin'),
    ]);
}
