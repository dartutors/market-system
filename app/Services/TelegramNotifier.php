<?php
declare(strict_types=1);
namespace App\Services;

use App\Config\Telegram as TelegramConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Outbound Telegram alerts via the Bot API's sendMessage method — same
 * "advisory notification only" boundary as NotificationService (email):
 * this never places, confirms, or references executing a trade, only
 * informs. Same fail-soft convention as every other outbound integration
 * in this app (Deriv, Claude, OpenAI, SMTP): a failure logs and returns
 * false, it never throws out to a caller that didn't ask for that.
 */
final class TelegramNotifier
{
    public function sendToAdmin(string $message): bool
    {
        $chatId = TelegramConfig::adminChatId();
        if ($chatId === '') {
            return false;
        }
        return $this->send($chatId, $message);
    }

    public function send(string $chatId, string $message): bool
    {
        if (!TelegramConfig::isConfigured()) {
            return false;
        }

        $client = new Client([
            'timeout'     => TelegramConfig::timeoutSeconds(),
            'http_errors' => false,
        ]);

        try {
            $response = $client->post(
                'https://api.telegram.org/bot' . TelegramConfig::botToken() . '/sendMessage',
                [
                    // Plain text, no parse_mode — alert content includes
                    // AI-generated reasoning text that could contain
                    // '<'/'>'/'&', which would otherwise need HTML-escaping
                    // or risk a 400 from Telegram's HTML parser. Matches
                    // NotificationService::sendSignalEmail()'s plain-text
                    // convention.
                    'json' => [
                        'chat_id'                  => $chatId,
                        'text'                      => $message,
                        'disable_web_page_preview'  => true,
                    ],
                ]
            );
        } catch (GuzzleException $e) {
            error_log('[TelegramNotifier] Network error: ' . $e->getMessage());
            return false;
        }

        $status = $response->getStatusCode();
        if ($status !== 200) {
            error_log('[TelegramNotifier] HTTP ' . $status . ': ' . mb_substr((string) $response->getBody(), 0, 200));
            return false;
        }

        $body = json_decode((string) $response->getBody(), true);
        if (!\is_array($body) || ($body['ok'] ?? false) !== true) {
            error_log('[TelegramNotifier] API returned ok=false: ' . mb_substr((string) $response->getBody(), 0, 200));
            return false;
        }

        return true;
    }
}
