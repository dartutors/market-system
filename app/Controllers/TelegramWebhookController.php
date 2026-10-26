<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Config\Telegram as TelegramConfig;
use App\Core\Controller;

/**
 * Inbound receiver for Telegram's webhook mode (TELEGRAM_BOT_MODE=webhook).
 * This app only ever pushes outbound alerts (App\Services\TelegramNotifier)
 * — there is no command bot / interactive-reply feature built on top of this
 * yet, so every inbound update is just logged and acknowledged. Telegram
 * requires a 200 response within a few seconds regardless of what a bot
 * does with the update, or it will retry redelivery — this always returns
 * 200 once the request is authenticated, even though nothing acts on the
 * payload today.
 *
 * Security: Telegram calls this endpoint from the public internet, so
 * there's no session/CSRF to check — instead, every request must carry the
 * secret token Telegram was configured (via setWebhook's secret_token
 * param, done once outside this app) to send back on every call, compared
 * with hash_equals() the same way the existing /cron/* web-cron token check
 * already does in public/index.php.
 *
 * Not testable end-to-end from local dev: Telegram's servers need a public
 * HTTPS URL to deliver updates to, which a localhost XAMPP install doesn't
 * have. This receiver, and its route registration, are ready for
 * production — registering the webhook itself (one-time
 * https://api.telegram.org/bot<token>/setWebhook call with this route's
 * production URL + TELEGRAM_WEBHOOK_SECRET) still needs to happen once the
 * app is actually deployed.
 */
final class TelegramWebhookController extends Controller
{
    public function receive(): void
    {
        if (!TelegramConfig::isConfigured()) {
            http_response_code(404);
            echo json_encode(['error' => 'Telegram integration is not enabled.']);
            return;
        }

        $submitted = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
        $expected  = TelegramConfig::webhookSecret();
        if ($expected === '' || !hash_equals($expected, $submitted)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid webhook secret.']);
            return;
        }

        $raw    = file_get_contents('php://input') ?: '';
        $update = json_decode($raw, true);

        if (\is_array($update)) {
            $updateId = $update['update_id'] ?? 'unknown';
            $chatId   = $update['message']['chat']['id'] ?? null;
            error_log("[TelegramWebhookController] Received update_id={$updateId}" . ($chatId !== null ? " chat_id={$chatId}" : '') . ' (no inbound command handling is implemented — logged only).');
        } else {
            error_log('[TelegramWebhookController] Received non-JSON or malformed payload.');
        }

        $this->json(['ok' => true]);
    }
}
