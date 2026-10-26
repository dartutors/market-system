<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Services\AuthService;
use App\Services\PasswordResetService;

final class AuthController extends Controller
{
    public function loginForm(): void
    {
        if (is_logged_in()) redirect(url('dashboard'));

        $this->view('auth/login', [
            'title' => 'Login',
            'csrf'  => Csrf::token(),
        ]);
    }

    public function login(): void
    {
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $auth = new AuthService();
        if (!$auth->attempt($email, $password, $ip)) {
            $_SESSION['flash_error'] = 'Invalid credentials or too many attempts.';
            redirect(url('login'));
        }

        redirect(url('dashboard'));
    }

    public function logout(): void
    {
        (new AuthService())->logout();
        redirect(url('login'));
    }

    public function forgotPasswordForm(): void
    {
        if (is_logged_in()) redirect(url('dashboard'));

        $this->view('auth/forgot-password', [
            'title' => 'Forgot Password',
            'csrf'  => Csrf::token(),
        ]);
    }

    public function forgotPassword(): void
    {
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        $email = (string) ($_POST['email'] ?? '');
        (new PasswordResetService())->requestReset($email);

        // Same message whether or not the email is registered — never confirm/deny an account's existence.
        $_SESSION['flash_success'] = 'If that email is registered, a reset link has been sent — check your inbox (and spam folder). The link expires in 60 minutes.';
        redirect(url('forgot-password'));
    }

    public function resetPasswordForm(): void
    {
        if (is_logged_in()) redirect(url('dashboard'));

        $token = trim((string) ($_GET['token'] ?? ''));
        if ($token === '') {
            $_SESSION['flash_error'] = 'Missing or invalid reset link.';
            redirect(url('forgot-password'));
        }

        $this->view('auth/reset-password', [
            'title' => 'Reset Password',
            'csrf'  => Csrf::token(),
            'token' => $token,
        ]);
    }

    public function resetPassword(): void
    {
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        $token    = trim((string) ($_POST['token'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');

        if (strlen($password) < 8) {
            $_SESSION['flash_error'] = 'Password must be at least 8 characters.';
            redirect(url('reset-password') . '?token=' . urlencode($token));
        }
        if ($password !== $confirm) {
            $_SESSION['flash_error'] = 'Passwords do not match.';
            redirect(url('reset-password') . '?token=' . urlencode($token));
        }

        $ok = (new PasswordResetService())->completeReset($token, $password);
        if (!$ok) {
            $_SESSION['flash_error'] = 'This reset link is invalid or has expired. Request a new one.';
            redirect(url('forgot-password'));
        }

        $_SESSION['flash_success'] = 'Password updated — sign in with your new password.';
        redirect(url('login'));
    }
}
