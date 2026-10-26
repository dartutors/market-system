<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Distinct from the existing JournalEntry model (journal_entries table,
 * unchanged) — this is the richer Phase 2.6 shape: outcome, P&L, screenshot
 * link, lessons learned, mistake tags, and (as of Phase 2.6's widening
 * migration 026) enough standalone context — market_label, direction,
 * session_name, source — that an entry no longer needs a signal_id or
 * decision_id to exist. A row with closed_at IS NULL is "open"; setting it
 * (via close()) is what records the outcome.
 */
final class TradeJournal
{
    private const VALID_OUTCOMES = ['win', 'loss', 'breakeven', 'skipped'];
    private const VALID_SOURCES  = ['manual', 'ai_signal', 'ai_decision'];

    public static function create(array $data): int
    {
        $sql  = 'INSERT INTO trade_journal
                    (signal_id, decision_id, image_id, market_label, direction, emotion, setup_pattern, source,
                     session_name, entry_price, notes, lessons_learned, mistake_tags_json, created_at)
                 VALUES
                    (:signal_id, :decision_id, :image_id, :market_label, :direction, :emotion, :setup_pattern, :source,
                     :session_name, :entry_price, :notes, :lessons_learned, :mistake_tags_json, NOW())';
        $pdo  = Database::connection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data + [
            'signal_id'          => null,
            'decision_id'        => null,
            'image_id'           => null,
            'market_label'       => null,
            'direction'          => null,
            'emotion'            => null,
            'setup_pattern'      => null,
            'source'             => 'manual',
            'session_name'       => null,
            'entry_price'        => null,
            'notes'              => null,
            'lessons_learned'    => null,
            'mistake_tags_json'  => null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM trade_journal WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch() ?: null;
    }

    public static function latest(int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM trade_journal WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Edits an open entry's review fields — notes, lessons learned, mistake
     * tags. Does not touch outcome/P&L; use close() for that.
     */
    public static function update(int $id, array $data): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE trade_journal
             SET notes = :notes, lessons_learned = :lessons_learned, mistake_tags_json = :mistake_tags_json,
                 setup_pattern = COALESCE(:setup_pattern, setup_pattern),
                 emotion = COALESCE(:emotion, emotion), updated_at = NOW()
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([
            'notes'             => $data['notes']             ?? null,
            'lessons_learned'   => $data['lessons_learned']   ?? null,
            'mistake_tags_json' => $data['mistake_tags_json'] ?? null,
            'setup_pattern'     => $data['setup_pattern']     ?? null,
            'emotion'           => $data['emotion']           ?? null,
            'id'                => $id,
        ]);
    }

    /** Set once, after close(), by the AI per-entry feedback generator — kept separate since it needs the closed row's own data as its input. */
    public static function saveAiFeedback(int $id, string $feedback): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE trade_journal SET ai_feedback = :feedback WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['feedback' => $feedback, 'id' => $id]);
    }

    /** Set once, after close(), by App\Services\AiTradingCoachService::reviewClosedTrade() — a structured, rule-based scored breakdown, distinct from the freeform ai_feedback paragraph above. */
    public static function saveReportCard(int $id, string $reportCardJson, string $grade): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE trade_journal SET report_card_json = :report_card, report_card_grade = :grade WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['report_card' => $reportCardJson, 'grade' => $grade, 'id' => $id]);
    }

    /**
     * Every closed trade in the window, oldest first — the dataset
     * AiTradingCoachService::personalInsights() and the Learning Dashboard's
     * "improvement trend" read, distinct from stats()'s aggregate counts
     * (this returns the actual rows, needed to compute a trend over time
     * and per-trade report-card grade distribution).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function closedTradesChronological(int $limit = 200): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM trade_journal
             WHERE deleted_at IS NULL AND closed_at IS NOT NULL
             ORDER BY closed_at ASC LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Records the real-world outcome of a trade: exit price, P&L, pips,
     * outcome, and optionally the mistake tags observed — same "outcome"
     * vocabulary as SignalEvent::markOutcome() (win|loss|breakeven|skipped).
     */
    public static function close(int $id, array $data): void
    {
        if (isset($data['outcome']) && !\in_array($data['outcome'], self::VALID_OUTCOMES, true)) {
            return;
        }

        $stmt = Database::connection()->prepare(
            'UPDATE trade_journal
             SET exit_price = :exit_price, profit_loss = :profit_loss, pips = :pips,
                 outcome = :outcome, mistake_tags_json = COALESCE(:mistake_tags_json, mistake_tags_json),
                 lessons_learned = COALESCE(:lessons_learned, lessons_learned),
                 setup_pattern = COALESCE(:setup_pattern, setup_pattern),
                 closed_at = NOW(), updated_at = NOW()
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([
            'exit_price'        => $data['exit_price']        ?? null,
            'profit_loss'       => $data['profit_loss']        ?? null,
            'pips'              => $data['pips']               ?? null,
            'outcome'           => $data['outcome']            ?? 'skipped',
            'mistake_tags_json' => $data['mistake_tags_json']  ?? null,
            'lessons_learned'   => $data['lessons_learned']    ?? null,
            'setup_pattern'     => $data['setup_pattern']      ?? null,
            'id'                => $id,
        ]);
    }

    /**
     * @param array{market_label?:string, session_name?:string, outcome?:string} $filters
     */
    public static function findAll(array $filters = [], int $limit = 50): array
    {
        $where  = ['deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['market_label'])) {
            $where[]                 = 'market_label = :market_label';
            $params['market_label']  = $filters['market_label'];
        }
        if (!empty($filters['session_name'])) {
            $where[]                 = 'session_name = :session_name';
            $params['session_name']  = $filters['session_name'];
        }
        if (!empty($filters['outcome'])) {
            $where[]           = 'outcome = :outcome';
            $params['outcome'] = $filters['outcome'];
        }

        $sql = 'SELECT * FROM trade_journal WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT :limit';

        $pdo  = Database::connection();
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":{$key}", $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Performance analytics: overall + pair (market_label) + session
     * breakdowns, a daily win-rate trend, and mistake-tag frequency —
     * the data behind Phase 2.6's "session analysis / pair analysis /
     * win-rate trends / mistake tracking" deliverables.
     *
     * @return array{total:int, open_count:int, closed_count:int, wins:int,
     *   losses:int, breakevens:int, win_rate:float, total_profit_loss:float,
     *   avg_profit_loss:float, by_market:array, by_session:array,
     *   daily_trend:array, mistake_tag_counts:array<string,int>}
     */
    public static function stats(int $trendDays = 30): array
    {
        $pdo = Database::connection();

        $overall = $pdo->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN closed_at IS NULL     THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN closed_at IS NOT NULL THEN 1 ELSE 0 END) AS closed_count,
                SUM(CASE WHEN outcome = 'win'       THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN outcome = 'loss'      THEN 1 ELSE 0 END) AS losses,
                SUM(CASE WHEN outcome = 'breakeven' THEN 1 ELSE 0 END) AS breakevens,
                COALESCE(SUM(profit_loss), 0) AS total_profit_loss,
                COALESCE(AVG(profit_loss), 0) AS avg_profit_loss
             FROM trade_journal WHERE deleted_at IS NULL"
        )->fetch(PDO::FETCH_ASSOC);

        $wins        = (int) ($overall['wins']   ?? 0);
        $losses      = (int) ($overall['losses'] ?? 0);
        $breakevens  = (int) ($overall['breakevens'] ?? 0);
        $decided     = max(1, $wins + $losses + $breakevens);
        $winRate     = ($wins + $losses + $breakevens) > 0 ? round($wins / $decided * 100, 1) : 0.0;

        $byMarket = $pdo->query(
            "SELECT market_label,
                COUNT(*) AS total,
                SUM(CASE WHEN outcome = 'win'  THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END) AS losses,
                COALESCE(SUM(profit_loss), 0) AS total_profit_loss
             FROM trade_journal
             WHERE deleted_at IS NULL AND market_label IS NOT NULL AND closed_at IS NOT NULL
             GROUP BY market_label
             ORDER BY total DESC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $bySession = $pdo->query(
            "SELECT session_name,
                COUNT(*) AS total,
                SUM(CASE WHEN outcome = 'win'  THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END) AS losses,
                COALESCE(SUM(profit_loss), 0) AS total_profit_loss
             FROM trade_journal
             WHERE deleted_at IS NULL AND session_name IS NOT NULL AND closed_at IS NOT NULL
             GROUP BY session_name
             ORDER BY total DESC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $trendDays = max(1, $trendDays);
        $dailyTrend = $pdo->prepare(
            "SELECT
                DATE(COALESCE(closed_at, created_at)) AS day,
                COUNT(*) AS total,
                SUM(CASE WHEN outcome = 'win'  THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END) AS losses
             FROM trade_journal
             WHERE deleted_at IS NULL AND closed_at IS NOT NULL
               AND closed_at >= DATE_SUB(NOW(), INTERVAL :trend_days DAY)
             GROUP BY DATE(COALESCE(closed_at, created_at))
             ORDER BY day DESC"
        );
        $dailyTrend->bindValue(':trend_days', $trendDays, PDO::PARAM_INT);
        $dailyTrend->execute();
        $dailyTrend = $dailyTrend->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $mistakeRows = $pdo->query(
            "SELECT mistake_tags_json FROM trade_journal WHERE deleted_at IS NULL AND mistake_tags_json IS NOT NULL"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $mistakeTagCounts = [];
        foreach ($mistakeRows as $row) {
            $tags = json_decode((string) $row['mistake_tags_json'], true);
            if (!\is_array($tags)) {
                continue;
            }
            foreach ($tags as $tag) {
                $tag = (string) $tag;
                $mistakeTagCounts[$tag] = ($mistakeTagCounts[$tag] ?? 0) + 1;
            }
        }
        arsort($mistakeTagCounts);

        return [
            'total'              => (int) ($overall['total'] ?? 0),
            'open_count'         => (int) ($overall['open_count'] ?? 0),
            'closed_count'       => (int) ($overall['closed_count'] ?? 0),
            'wins'               => $wins,
            'losses'             => $losses,
            'breakevens'         => $breakevens,
            'win_rate'           => $winRate,
            'total_profit_loss'  => round((float) ($overall['total_profit_loss'] ?? 0), 2),
            'avg_profit_loss'    => round((float) ($overall['avg_profit_loss']   ?? 0), 2),
            'by_market'          => $byMarket,
            'by_session'         => $bySession,
            'daily_trend'        => $dailyTrend,
            'mistake_tag_counts' => $mistakeTagCounts,
        ];
    }

    /**
     * Groups closed entries by setup_pattern — the data source for
     * App\Learning\PatternLearningService::rebuild(). avg_profit_loss here
     * is an average $ P/L per trade, not a true R-multiple: trade_journal
     * never captures the initial risk unit (stop distance) at entry time,
     * so a retroactive R-multiple can't be computed honestly.
     *
     * @return array<int, array{setup_pattern:string, occurrences:int, wins:int, win_rate:float, avg_profit_loss:float}>
     */
    public static function statsBySetupPattern(): array
    {
        $rows = Database::connection()->query(
            "SELECT setup_pattern,
                COUNT(*) AS occurrences,
                SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) AS wins,
                COALESCE(AVG(profit_loss), 0) AS avg_profit_loss
             FROM trade_journal
             WHERE deleted_at IS NULL AND setup_pattern IS NOT NULL AND closed_at IS NOT NULL
             GROUP BY setup_pattern
             ORDER BY occurrences DESC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            $occurrences = (int) $row['occurrences'];
            return [
                'setup_pattern'    => (string) $row['setup_pattern'],
                'occurrences'      => $occurrences,
                'wins'             => (int) $row['wins'],
                'win_rate'         => $occurrences > 0 ? round(((int) $row['wins']) / $occurrences * 100, 1) : 0.0,
                'avg_profit_loss'  => round((float) $row['avg_profit_loss'], 2),
            ];
        }, $rows);
    }

    /**
     * Bulk-indexed by decision_id, mirroring App\Models\JournalEntry::
     * indexedBySignalId()'s exact pattern — used by
     * App\Learning\RecommendationHistoryService to cross-reference historical
     * AiDecision rows against whatever outcome (if any) a user logged for them.
     *
     * @param array<int> $decisionIds
     * @return array<int, array> Indexed by decision_id.
     */
    public static function indexedByDecisionId(array $decisionIds): array
    {
        if (empty($decisionIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($decisionIds), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT * FROM trade_journal WHERE decision_id IN ({$placeholders}) AND deleted_at IS NULL"
        );
        $stmt->execute(array_values($decisionIds));

        $indexed = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $indexed[(int) $row['decision_id']] = $row;
        }
        return $indexed;
    }

    public static function softDelete(int $id): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE trade_journal SET deleted_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public static function validOutcomes(): array
    {
        return self::VALID_OUTCOMES;
    }

    public static function validSources(): array
    {
        return self::VALID_SOURCES;
    }
}
