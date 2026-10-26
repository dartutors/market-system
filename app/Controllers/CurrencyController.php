<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\MarketIntelligence\MarketIntelligenceEngine;
use App\Models\CalendarEvent;
use App\Models\CurrencyStrengthSnapshot;
use App\Models\NewsItem;
use Throwable;

/**
 * Backs the heatmap tile click-through modal (Dashboard UX Module 4).
 * Every field is either a real per-currency computation or explicitly
 * marked unavailable — "AI Opinion" only exists for USD because that's
 * the only currency AiSummary's market_bias enum (bullish_usd/bearish_usd/
 * mixed/risk_on/risk_off/neutral) actually encodes an opinion about;
 * "Recommendation" from the original spec was dropped rather than backed
 * by an invented per-currency recommendation engine that doesn't exist
 * anywhere else in this app.
 */
final class CurrencyController extends Controller
{
    private const VALID_CURRENCIES = ['USD', 'EUR', 'GBP', 'JPY', 'CHF', 'AUD', 'CAD', 'NZD'];

    public function detail(): void
    {
        require_login();

        $currency = strtoupper(trim((string) ($_GET['currency'] ?? '')));
        if (!\in_array($currency, self::VALID_CURRENCIES, true)) {
            $this->json(['error' => 'Unknown currency.'], 422);
            return;
        }

        $markets = configured_markets();
        $relatedPairs = array_values(array_filter(
            $markets,
            static fn(array $m): bool => str_contains((string) $m['label'], $currency)
        ));
        $relatedLabels = array_column($relatedPairs, 'label');

        $history = CurrencyStrengthSnapshot::history($currency, 30);
        $trend = $this->deriveTrend($history);

        $volatility = $this->averageVolatilityForPairs($relatedLabels);

        $news = array_values(array_filter(
            NewsItem::latest(30),
            static fn(array $n): bool => str_contains(strtoupper((string) $n['title']), $currency)
                || \in_array(strtoupper((string) $n['title']), $relatedLabels, true)
        ));
        $news = array_slice($news, 0, 5);

        $events = array_values(array_filter(
            CalendarEvent::all(),
            static fn(array $e): bool => strtoupper((string) $e['currency']) === $currency && $e['scheduled_at'] >= now_eat()->format('Y-m-d H:i:s')
        ));
        $events = array_slice($events, 0, 5);

        $aiOpinion = $this->aiOpinionFor($currency);

        $this->json([
            'currency'      => $currency,
            'trend'         => $trend,
            'history'       => $history,
            'volatility'    => $volatility,
            'related_pairs' => $relatedLabels,
            'news'          => $news,
            'events'        => $events,
            'ai_opinion'    => $aiOpinion,
        ]);
    }

    private function deriveTrend(array $history): ?string
    {
        if (count($history) < 3) {
            return null;
        }
        $recent = array_slice($history, -3);
        $first = (float) $recent[0]['score'];
        $last  = (float) $recent[count($recent) - 1]['score'];
        if ($last > $first + 0.01) {
            return 'rising';
        }
        if ($last < $first - 0.01) {
            return 'falling';
        }
        return 'flat';
    }

    private function averageVolatilityForPairs(array $labels): ?array
    {
        if ($labels === []) {
            return null;
        }
        try {
            $snap = (new MarketIntelligenceEngine())->build();
            $matches = array_intersect_key($snap->volatilityIndex['by_pair'], array_flip($labels));
            if ($matches === []) {
                return null;
            }
            $avgAtr = array_sum(array_column($matches, 'atr_percent')) / count($matches);
            return ['atr_percent' => round($avgAtr, 4), 'sample_pairs' => array_keys($matches)];
        } catch (Throwable) {
            return null;
        }
    }

    private function aiOpinionFor(string $currency): ?string
    {
        if ($currency !== 'USD') {
            return null;
        }
        $latest = \App\Models\AiSummary::latest(1)[0] ?? null;
        if ($latest === null) {
            return null;
        }
        return match (strtolower((string) $latest['market_bias'])) {
            'bullish_usd' => 'Latest AI summary reads bullish USD.',
            'bearish_usd' => 'Latest AI summary reads bearish USD.',
            'risk_off'    => 'Latest AI summary reads risk-off (typically USD-supportive).',
            'risk_on'     => 'Latest AI summary reads risk-on (typically USD-negative).',
            default       => 'Latest AI summary reads mixed/neutral on USD.',
        };
    }
}
