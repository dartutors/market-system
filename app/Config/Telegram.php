<?php
declare(strict_types=1);
namespace App\Config;

final class Telegram {
    public static function enabled(): bool { return (bool) env('TELEGRAM_ENABLED', false); }
    public static function botToken(): string { return (string) env('TELEGRAM_BOT_TOKEN', ''); }
    public static function adminChatId(): string { return (string) env('TELEGRAM_ADMIN_CHAT_ID', ''); }
    public static function communityLink(): string { return (string) env('TELEGRAM_COMMUNITY_LINK', ''); }
    public static function communityChatId(): string { return (string) env('TELEGRAM_COMMUNITY_CHAT_ID', ''); }
    public static function webhookSecret(): string { return (string) env('TELEGRAM_WEBHOOK_SECRET', ''); }
    public static function timeoutSeconds(): int { return max(1, (int) env('TELEGRAM_TIMEOUT_SECONDS', 10)); }
    public static function botMode(): string { return (string) env('TELEGRAM_BOT_MODE', 'webhook'); }

    public static function isConfigured(): bool
    {
        return self::enabled() && self::botToken() !== '' && self::adminChatId() !== '';
    }
}
