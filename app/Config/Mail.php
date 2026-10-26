<?php
declare(strict_types=1);
namespace App\Config;

final class Mail {
    public static function host(): string { return (string) env('MAIL_HOST', ''); }
    public static function port(): int { return (int) env('MAIL_PORT', 587); }
    public static function username(): string { return (string) env('MAIL_USERNAME', ''); }
    public static function password(): string { return (string) env('MAIL_PASSWORD', ''); }
    public static function encryption(): string { return (string) env('MAIL_ENCRYPTION', 'tls'); }
    public static function fromAddress(): string { return (string) env('MAIL_FROM_ADDRESS', ''); }
    public static function fromName(): string { return (string) env('MAIL_FROM_NAME', 'Market Alerts'); }
    public static function alertTo(): string { return (string) env('ALERT_EMAIL_TO', ''); }
}
