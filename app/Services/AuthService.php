<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Security;
use App\Core\Session;
use App\Models\User;

final class AuthService {
    // A precomputed valid bcrypt hash with no matching password — verified
    // against on the "no such user" branch below purely to burn the same
    // CPU time password_verify() would spend on a real account, so response
    // timing doesn't reveal whether an email is registered.
    private const DUMMY_HASH = '$2y$10$C6UzMDM.H6dfI/f/IKcEeOWL7WHz6QQ4yZTG6oh8IJTG6f2f4gK2u';

    public function attempt(string $email, string $password, string $ip): bool {
        $throttle = new LoginThrottleService();
        if (!$throttle->allow($email, $ip)) return false;

        $user = User::findByEmail($email);
        if (!$user || !(bool) $user['is_active']) {
            Security::verifyPassword($password, self::DUMMY_HASH);
            $throttle->recordFailure($email, $ip);
            return false;
        }
        if (!Security::verifyPassword($password, (string) $user['password_hash'])) {
            $throttle->recordFailure($email, $ip);
            return false;
        }
        $throttle->clear($email, $ip);
        Session::regenerate();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_email'] = (string) $user['email'];
        $_SESSION['user_name'] = (string) $user['full_name'];
        $_SESSION['is_admin'] = (bool) $user['is_admin'];
        return true;
    }
    public function logout(): void {
        Session::destroy();
    }
}
