<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;

final class User {
    public static function findByEmail(string $email): ?array {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => strtolower(trim($email))]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function updatePassword(int $id, string $passwordHash): void {
        $stmt = Database::connection()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute(['hash' => $passwordHash, 'id' => $id]);
    }
}
