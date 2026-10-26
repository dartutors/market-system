<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Models\NewsItem;
use App\News\NewsImpactAiService;

/**
 * Section 8 "clicking an article should generate an AI explanation of its
 * likely market impact" — JSON endpoint, AJAX-triggered from the dashboard's
 * Market News list. Cache-first: a headline is only ever sent to an AI
 * provider once (see NewsItem::saveExplanation()), so repeat clicks by any
 * user are free and instant.
 */
final class NewsController extends Controller
{
    public function explain(): void
    {
        require_login();

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $this->json(['error' => 'Invalid CSRF token.'], 419);
            return;
        }

        $id = (int) ($_POST['news_id'] ?? 0);
        $item = $id > 0 ? NewsItem::findById($id) : null;
        if ($item === null) {
            $this->json(['error' => 'News item not found.'], 404);
            return;
        }

        $cached = trim((string) ($item['ai_explanation'] ?? ''));
        if ($cached !== '') {
            $this->json([
                'explanation' => $cached,
                'provider'    => (string) ($item['ai_explanation_provider'] ?? ''),
                'cached'      => true,
            ]);
            return;
        }

        if (!RateLimiter::allow('news_explain', 10, 60)) {
            $this->json(['error' => 'Too many AI explanation requests — please wait a moment and try again.'], 429);
            return;
        }

        $result = (new NewsImpactAiService())->explain(
            (string) $item['title'],
            (string) $item['source'],
            $item['published_at'] !== null ? (string) $item['published_at'] : null,
            (string) ($item['market_bias'] ?? 'neutral')
        );

        if ($result === null) {
            $this->json(['error' => 'AI explanations are not available right now.'], 503);
            return;
        }

        NewsItem::saveExplanation($id, $result['explanation'], $result['provider']);

        $this->json([
            'explanation' => $result['explanation'],
            'provider'    => $result['provider'],
            'cached'      => false,
        ]);
    }

}
