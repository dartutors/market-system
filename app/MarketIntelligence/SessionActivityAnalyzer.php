<?php
declare(strict_types=1);
namespace App\MarketIntelligence;

use App\MarketData\Manager\MarketDataManager;
use App\Services\TradingSessionService;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Genuine data-driven session analysis: buckets historical H1 candles (same
 * interval/range as App\MultiTimeframe\TimeframeRegistry's H1 spec) by which
 * trading session each candle's timestamp falls into
 * (TradingSessionService::currentSession() accepts any DateTimeImmutable,
 * not just "now" — reused here for historical classification), and averages
 * each candle's (high-low) range as a % of price per session. Distinct from
 * App\Models\TradeJournal::stats()['by_session'], which is outcome-based
 * (win/loss per session on logged trades), not raw market-condition-based —
 * no such market-condition-by-session computation existed before this class.
 * Phase 2.2A: fetches via App\MarketData\Manager\MarketDataManager (Deriv ->
 * Yahoo Finance -> cache) instead of talking to Yahoo Finance directly.
 */
final class SessionActivityAnalyzer
{
    private const SESSIONS = ['sydney', 'tokyo', 'london', 'new_york'];

    public function __construct(private MarketDataManager $manager = new MarketDataManager())
    {
    }

    /**
     * @return array{
     *   current_session:string, next_session:?string, minutes_until_next:?int,
     *   by_session:array<string,float>, by_pair:array<string,array<string,float>>,
     *   most_active_session:?string
     * }
     */
    public function analyze(): array
    {
        $sessionService = new TradingSessionService();
        $now            = now_eat();
        $countdown      = $sessionService->nextSessionCountdown($now);

        $tz = new DateTimeZone((string) env('APP_TIMEZONE', 'Africa/Dar_es_Salaam'));

        $sums   = array_fill_keys(self::SESSIONS, 0.0);
        $counts = array_fill_keys(self::SESSIONS, 0);
        $byPair = [];

        foreach (configured_markets() as $market) {
            try {
                $rows = $this->manager->fetchCandles($market['symbol'], '60m', '60d');
            } catch (Throwable $e) {
                error_log("[SessionActivityAnalyzer] {$market['label']} fetch failed: " . $e->getMessage());
                continue;
            }

            $pairSums   = array_fill_keys(self::SESSIONS, 0.0);
            $pairCounts = array_fill_keys(self::SESSIONS, 0);

            foreach ($rows as $row) {
                $close = (float) ($row['close'] ?? 0.0);
                if ($close <= 0.0) {
                    continue;
                }

                $rangePct = ((float) $row['high'] - (float) $row['low']) / $close * 100;
                $dt       = (new DateTimeImmutable('@' . (int) $row['timestamp']))->setTimezone($tz);
                $session  = $sessionService->currentSession($dt);

                if (!isset($sums[$session])) {
                    continue; // 'off' — not one of the 4 named sessions being ranked.
                }

                $sums[$session]   += $rangePct;
                $counts[$session] += 1;
                $pairSums[$session]   += $rangePct;
                $pairCounts[$session] += 1;
            }

            $byPair[$market['label']] = [];
            foreach (self::SESSIONS as $session) {
                $byPair[$market['label']][$session] = $pairCounts[$session] > 0
                    ? round($pairSums[$session] / $pairCounts[$session], 4)
                    : 0.0;
            }
        }

        $bySession = [];
        foreach (self::SESSIONS as $session) {
            $bySession[$session] = $counts[$session] > 0 ? round($sums[$session] / $counts[$session], 4) : 0.0;
        }

        $mostActive = null;
        $mostActiveValue = -1.0;
        foreach ($bySession as $session => $avgRange) {
            if ($avgRange > $mostActiveValue) {
                $mostActiveValue = $avgRange;
                $mostActive      = $session;
            }
        }

        return [
            'current_session'      => $sessionService->currentSession($now),
            'next_session'         => $countdown['session'],
            'minutes_until_next'   => $countdown['minutes_until'],
            'by_session'           => $bySession,
            'by_pair'               => $byPair,
            'most_active_session'  => $mostActiveValue > 0.0 ? $mostActive : null,
        ];
    }
}
