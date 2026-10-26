<?php
declare(strict_types=1);
namespace App\Core;

final class Security {
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    public static function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }
    public static function randomToken(int $length = 16): string {
        return bin2hex(random_bytes($length));
    }
}
