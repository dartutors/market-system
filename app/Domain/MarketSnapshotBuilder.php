<?php
declare(strict_types=1);
namespace App\Domain;

use DateTimeImmutable;

/**
 * Assembles a MarketSnapshot purely from data MarketScanService already
 * computes during a scan cycle — no new indicator math, no new external
 * calls, nothing that changes what gets persisted to signal_events.
 */
final class MarketSnapshotBuilder
{
    private const PRICE_KEYS = ['timestamp', 'open', 'high', 'low', 'close', 'volume'];

    public static function build(
        string $symbol,
        string $timeframe,
        array $enrichedRows,
        array $signal,
        array $sentimentSummary,
        ?array $calendarRisk,
        string $aiBias,
        float $aiConfidence
    ): MarketSnapshot {
        $latest = $enrichedRows[\count($enrichedRows) - 1] ?? [];

        $priceKeyMap = array_flip(self::PRICE_KEYS);
        $ohlc        = array_intersect_key($latest, $priceKeyMap);
        $indicators  = array_diff_key($latest, $priceKeyMap);

        return new MarketSnapshot(
            symbol: $symbol,
            timeframe: $timeframe,
            ohlc: $ohlc,
            indicators: $indicators,
            trend: TrendClassifier::classifyTrend($latest),
            momentum: TrendClassifier::classifyMomentum($latest),
            session: (string) ($signal['session_name'] ?? 'off'),
            sentiment: ['score' => (float) ($sentimentSummary['score'] ?? 0.0)],
            economicEvents: $calendarRisk,
            news: (array) ($sentimentSummary['articles'] ?? []),
            aiSummary: ['bias' => $aiBias, 'confidence' => $aiConfidence],
            confidence: (float) ($signal['confidence'] ?? 0.0),
            generatedAt: new DateTimeImmutable('now'),
        );
    }
}
