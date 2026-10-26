<?php
declare(strict_types=1);
namespace App\Reports;

use App\Core\Database;
use App\Services\EconomicCalendarService;
use DateTimeImmutable;
use PDO;

/**
 * "Economic Calendar" report. "Historical Calendar Impact" from the
 * original spec (i.e. what actually happened to price after each event)
 * has no data source anywhere in this schema — calendar_events has no
 * actual/outcome column — and is honestly omitted.
 */
final class EconomicCalendarReport
{
    public static function build(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        try {
            return self::buildFromDatabase($from, $to);
        } catch (\Throwable $e) {
            error_log('[EconomicCalendarReport] build failed: ' . $e->getMessage());
            return [
                'by_impact' => ['high' => 0, 'medium' => 0, 'low' => 0],
                'by_currency' => [], 'past_events' => [], 'upcoming_events' => [], 'blocking_sessions' => [],
                'unavailable' => true,
            ];
        }
    }

    private static function buildFromDatabase(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $pdo = Database::connection();
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];

        $byImpact = $pdo->prepare(
            "SELECT impact, COUNT(*) AS total FROM calendar_events
             WHERE scheduled_at BETWEEN :from AND :to GROUP BY impact"
        );
        $byImpact->execute($params);
        $impactCounts = ['high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($byImpact->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = strtolower((string) $row['impact']);
            if (isset($impactCounts[$key])) {
                $impactCounts[$key] = (int) $row['total'];
            }
        }

        $byCurrency = $pdo->prepare(
            "SELECT currency, COUNT(*) AS total FROM calendar_events
             WHERE scheduled_at BETWEEN :from AND :to GROUP BY currency ORDER BY total DESC"
        );
        $byCurrency->execute($params);

        $past = $pdo->prepare(
            "SELECT title, currency, impact, scheduled_at FROM calendar_events
             WHERE scheduled_at < NOW() AND scheduled_at BETWEEN :from AND :to
             ORDER BY scheduled_at DESC LIMIT 50"
        );
        $past->execute($params);

        $upcoming = (new EconomicCalendarService())->getUpcomingEvents(new \DateTimeImmutable('now'), 14);
        $blocking = array_values(array_filter($upcoming, static fn(array $e): bool => (bool) ($e['is_active'] ?? false)));

        return [
            'by_impact'         => $impactCounts,
            'by_currency'       => $byCurrency->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'past_events'       => $past->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'upcoming_events'   => $upcoming,
            'blocking_sessions' => $blocking,
        ];
    }

    public static function toTable(array $report): array
    {
        return [
            'headers' => ['Event', 'Currency', 'Impact', 'Scheduled'],
            'rows' => array_map(
                static fn(array $r): array => [$r['title'], $r['currency'], strtoupper((string) $r['impact']), $r['scheduled_at']],
                $report['past_events']
            ),
        ];
    }
}
