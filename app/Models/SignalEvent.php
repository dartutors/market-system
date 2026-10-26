<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

final class SignalEvent
{
    private const VALID_OUTCOMES = ['win', 'loss', 'breakeven', 'skipped'];

    public static function countByDate(string $signalDate): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS total FROM signal_events WHERE signal_date = :signal_date'
        );
        $stmt->execute(['signal_date' => $signalDate]);
        $row = $stmt->fetch();
        return (int) ($row['total'] ?? 0);
    }

    public static function countByDateAndMarket(string $signalDate, string $marketLabel): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS total
             FROM signal_events
             WHERE signal_date  = :signal_date
               AND market_label = :market_label'
        );
        $stmt->execute(['signal_date' => $signalDate, 'market_label' => $marketLabel]);
        $row = $stmt->fetch();
        return (int) ($row['total'] ?? 0);
    }

    /** Inclusive date range count, used for weekly (not just daily) risk-limit checks. */
    public static function countByDateRange(string $fromDate, string $toDate): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS total FROM signal_events
             WHERE signal_date BETWEEN :from_date AND :to_date'
        );
        $stmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
        $row = $stmt->fetch();
        return (int) ($row['total'] ?? 0);
    }

    /**
     * Most-recent-first outcomes for closed BUY/SELL signals — used by
     * DrawdownGuard to detect a consecutive-loss streak. Only counts
     * outcomes that have actually been recorded (win/loss/breakeven), not
     * pending signals.
     *
     * @return array<string> e.g. ['loss', 'loss', 'win', ...]
     */
    public static function recentOutcomes(int $limit = 10): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT outcome FROM signal_events
             WHERE action IN ('BUY','SELL') AND outcome IS NOT NULL
             ORDER BY COALESCE(closed_at, created_at) DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_column($stmt->fetchAll() ?: [], 'outcome');
    }

    /**
     * BUY/SELL signals with no recorded outcome yet — this system's only
     * signal of "still open" (a user marks outcome once a trade closes, via
     * SignalController::updateOutcome()). Used for exposure/correlation
     * tracking in the Risk Management Suite.
     *
     * @return array<array{market_label:string, action:string, signal_date:string}>
     */
    public static function openPositions(): array
    {
        $stmt = Database::connection()->query(
            "SELECT market_label, action, signal_date FROM signal_events
             WHERE action IN ('BUY','SELL') AND outcome IS NULL
             ORDER BY created_at DESC"
        );
        return $stmt->fetchAll() ?: [];
    }

    public static function create(array $data): int
    {
        $sql  = 'INSERT INTO signal_events
                    (market_symbol, market_label, timeframe, session_name, action,
                     confidence, entry_window_start, entry_window_end,
                     hold_hours_min, hold_hours_max, stop_loss_hint, take_profit_hint,
                     reasons, risk_warning, status, signal_date, was_alert_sent, created_at)
                 VALUES
                    (:market_symbol, :market_label, :timeframe, :session_name, :action,
                     :confidence, :entry_window_start, :entry_window_end,
                     :hold_hours_min, :hold_hours_max, :stop_loss_hint, :take_profit_hint,
                     :reasons, :risk_warning, :status, :signal_date, :was_alert_sent, NOW())';
        $pdo  = Database::connection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
        return (int) $pdo->lastInsertId();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM signal_events WHERE id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function markAlertSent(int $id): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE signal_events SET was_alert_sent = 1, status = :status WHERE id = :id'
        );
        $stmt->execute(['status' => 'alerted', 'id' => $id]);
    }

    /**
     * Records the real-world outcome of a signal.
     * Outcome must be one of: win | loss | breakeven | skipped
     */
    public static function markOutcome(int $id, string $outcome, string $notes = ''): void
    {
        if (!\in_array($outcome, self::VALID_OUTCOMES, true)) {
            return;
        }
        $stmt = Database::connection()->prepare(
            'UPDATE signal_events
             SET outcome = :outcome, outcome_notes = :notes, closed_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['outcome' => $outcome, 'notes' => $notes, 'id' => $id]);
    }

    public static function latest(int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM signal_events ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public static function todaySignals(): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM signal_events
             WHERE signal_date = CURDATE()
             ORDER BY created_at DESC'
        );
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Returns comprehensive performance statistics.
     * Only counts BUY/SELL actions (not WAIT/AVOID/HOLD/WATCH).
     */
    public static function stats(): array
    {
        $pdo = Database::connection();

        // ── Overall totals ────────────────────────────────────────────────────
        $row = $pdo->query(
            "SELECT
                COUNT(*)                                                        AS total,
                SUM(CASE WHEN outcome IS NOT NULL        THEN 1 ELSE 0 END)   AS with_outcome,
                SUM(CASE WHEN outcome = 'win'            THEN 1 ELSE 0 END)   AS wins,
                SUM(CASE WHEN outcome = 'loss'           THEN 1 ELSE 0 END)   AS losses,
                SUM(CASE WHEN outcome = 'breakeven'      THEN 1 ELSE 0 END)   AS breakevens,
                SUM(CASE WHEN outcome = 'skipped'        THEN 1 ELSE 0 END)   AS skipped
             FROM signal_events
             WHERE action IN ('BUY','SELL')"
        )->fetch(PDO::FETCH_ASSOC);

        $total       = (int) ($row['total']       ?? 0);
        $withOutcome = (int) ($row['with_outcome'] ?? 0);
        $wins        = (int) ($row['wins']         ?? 0);
        $losses      = (int) ($row['losses']       ?? 0);
        $breakevens  = (int) ($row['breakevens']   ?? 0);

        $base     = max(1, $withOutcome);
        $winRate  = $withOutcome > 0 ? round($wins  / $base * 100, 1) : 0.0;
        $lossRate = $withOutcome > 0 ? round($losses / $base * 100, 1) : 0.0;
        $beRate   = $withOutcome > 0 ? round($breakevens / $base * 100, 1) : 0.0;

        // ── Per-market breakdown ──────────────────────────────────────────────
        $mkStmt = $pdo->query(
            "SELECT
                market_label,
                COUNT(*) AS total,
                SUM(CASE WHEN outcome = 'win'  THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END) AS losses
             FROM signal_events
             WHERE action IN ('BUY','SELL') AND outcome IS NOT NULL
             GROUP BY market_label"
        );
        $markets    = $mkStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $bestMarket = null;
        $worstMarket = null;
        $bestRate   = -1.0;
        $worstRate  = 999.0;
        foreach ($markets as $m) {
            $mt = max(1, (int) $m['total']);
            $wr = (int) $m['wins'] / $mt * 100;
            if ($wr > $bestRate)  { $bestRate   = $wr; $bestMarket  = $m['market_label']; }
            if ($wr < $worstRate) { $worstRate  = $wr; $worstMarket = $m['market_label']; }
        }

        // ── Last 7 days ───────────────────────────────────────────────────────
        $d7Stmt = $pdo->query(
            "SELECT
                DATE(created_at) AS day,
                COUNT(*) AS total,
                SUM(CASE WHEN outcome = 'win'  THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END) AS losses
             FROM signal_events
             WHERE action IN ('BUY','SELL')
               AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY DATE(created_at)
             ORDER BY day DESC"
        );
        $last7Days = $d7Stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // ── Confidence calibration ────────────────────────────────────────────
        $cfStmt = $pdo->query(
            "SELECT
                CASE
                    WHEN confidence >= 80 THEN '80%+'
                    WHEN confidence >= 70 THEN '70-79%'
                    WHEN confidence >= 60 THEN '60-69%'
                    ELSE 'Below 60%'
                END AS band,
                COUNT(*) AS total,
                SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) AS wins
             FROM signal_events
             WHERE action IN ('BUY','SELL') AND outcome IS NOT NULL
             GROUP BY band
             ORDER BY MIN(confidence) DESC"
        );
        $byConfidence = $cfStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'total'        => $total,
            'with_outcome' => $withOutcome,
            'wins'         => $wins,
            'losses'       => $losses,
            'breakevens'   => $breakevens,
            'win_rate'     => $winRate,
            'loss_rate'    => $lossRate,
            'be_rate'      => $beRate,
            'best_market'  => $bestMarket ?? '—',
            'worst_market' => $worstMarket ?? '—',
            'last_7_days'  => $last7Days,
            'by_confidence'=> $byConfidence,
        ];
    }

    public static function validOutcomes(): array
    {
        return self::VALID_OUTCOMES;
    }
}
