<?php
declare(strict_types=1);

namespace App\Services\News;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class RssNewsProvider implements NewsProviderInterface
{
    /**
     * Publicly accessible forex/finance RSS feeds.
     * Each entry: [ url, source_name ]
     * Add or remove feeds as needed. All failures are silent.
     */
    private const FEEDS = [
        ['https://www.fxstreet.com/rss/news',                         'FXStreet'],
        ['https://finance.yahoo.com/rss/topstories',                  'Yahoo Finance'],
        ['https://feeds.a.dj.com/rss/RSSMarketsMain.xml',             'WSJ Markets'],
        ['https://feeds.content.dowjones.io/public/rss/mw_topstories','MarketWatch'],
    ];

    private const REQUIRED_TERMS = [
        'eur/usd', 'eurusd', 'gbp/usd', 'gbpusd', 'usd/jpy', 'usdjpy',
        'euro', 'pound', 'sterling', 'yen', 'dollar', 'forex', 'foreign exchange',
        'fed', 'federal reserve', 'fomc', 'ecb', 'european central bank',
        'boe', 'bank of england', 'boj', 'bank of japan',
        'inflation', 'cpi', 'interest rate', 'rate decision', 'monetary policy',
        'nfp', 'non-farm', 'payrolls', 'gdp', 'hawkish', 'dovish',
    ];

    private const BLOCKED_TERMS = [
        'sport', 'football', 'soccer', 'tennis', 'basketball',
        'celebrity', 'movie', 'music', 'entertainment', 'flooring',
        'lifestyle', 'fashion', 'gaming', 'nfl', 'nba', 'nhl',
    ];

    public function fetchHeadlines(array $markets = []): array
    {
        if (!(bool) env('USE_RSS_FALLBACK', true)) {
            return [];
        }

        $limit  = max(1, min(30, (int) env('RSS_MAX_ARTICLES', 8)));
        $client = new Client([
            'timeout'         => 15,
            'connect_timeout' => 8,
            'http_errors'     => false,
            'headers'         => ['User-Agent' => 'MarketSignalSystem/1.0 RSS Reader'],
        ]);

        $collected  = [];
        $seenTitles = [];

        foreach (self::FEEDS as [$feedUrl, $sourceName]) {
            if (count($collected) >= $limit) {
                break;
            }

            try {
                $items = $this->parseFeed($client, $feedUrl, $sourceName, $seenTitles);
                foreach ($items as $item) {
                    if (count($collected) >= $limit) {
                        break;
                    }
                    $normalized = mb_strtolower($item['title']);
                    if (!isset($seenTitles[$normalized])) {
                        $seenTitles[$normalized] = true;
                        $collected[]             = $item;
                    }
                }
            } catch (\Throwable) {
                // One bad feed must not kill the others
            }
        }

        return $collected;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** @param array<string, true> $seenTitles */
    private function parseFeed(Client $client, string $url, string $sourceName, array &$seenTitles): array
    {
        try {
            $response = $client->get($url);
        } catch (GuzzleException) {
            return [];
        }

        if ($response->getStatusCode() !== 200) {
            return [];
        }

        $body = (string) $response->getBody();
        if (trim($body) === '') {
            return [];
        }

        // Suppress XML warnings — feeds are often slightly malformed
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();

        if ($xml === false) {
            return [];
        }

        $items = [];

        // Support both RSS 2.0 (<channel><item>) and Atom (<entry>)
        $entries = $xml->channel->item ?? $xml->entry ?? [];

        foreach ($entries as $entry) {
            $title = $this->cleanText((string) ($entry->title ?? ''));
            if ($title === '') {
                continue;
            }

            $desc = $this->cleanText((string) ($entry->description ?? $entry->summary ?? ''));
            if (!$this->isRelevant($title, $desc)) {
                continue;
            }

            $normalized = mb_strtolower($title);
            if (isset($seenTitles[$normalized])) {
                continue;
            }

            $pubRaw = (string) ($entry->pubDate ?? $entry->published ?? $entry->updated ?? '');
            $url    = $this->sanitizeUrl((string) ($entry->link ?? ''));

            // Atom <link> is an element with href attribute
            if ($url === '' && isset($entry->link['href'])) {
                $url = $this->sanitizeUrl((string) $entry->link['href']);
            }

            $items[] = [
                'title'        => $title,
                'source'       => $sourceName,
                'url'          => $url,
                'published_at' => $this->normalizeDate($pubRaw),
                'provider'     => 'rss',
                'symbols'      => $this->guessSymbols($title . ' ' . $desc),
            ];
        }

        return $items;
    }

    private function isRelevant(string $title, string $description): bool
    {
        $haystack = mb_strtolower($title . ' ' . $description);

        $matched = false;
        foreach (self::REQUIRED_TERMS as $term) {
            if (str_contains($haystack, $term)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            return false;
        }

        foreach (self::BLOCKED_TERMS as $term) {
            if (str_contains($haystack, $term)) {
                return false;
            }
        }

        return true;
    }

    private function guessSymbols(string $text): array
    {
        $text    = mb_strtolower($text);
        $symbols = [];
        if (str_contains($text, 'eur') || str_contains($text, 'ecb') || str_contains($text, 'euro')) {
            $symbols[] = 'EUR/USD';
        }
        if (str_contains($text, 'gbp') || str_contains($text, 'boe') || str_contains($text, 'sterling') || str_contains($text, 'pound')) {
            $symbols[] = 'GBP/USD';
        }
        if (str_contains($text, 'jpy') || str_contains($text, 'boj') || str_contains($text, 'yen')) {
            $symbols[] = 'USD/JPY';
        }
        return $symbols;
    }

    private function cleanText(string $value): string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $value = strip_tags($value);
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        return ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) ? $url : '';
    }

    private function normalizeDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return '';
        }
    }
}
