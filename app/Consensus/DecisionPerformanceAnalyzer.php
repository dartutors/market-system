<?php
declare(strict_types=1);
namespace App\Consensus;

use App\Models\ConsensusDecision;

/**
 * Objective 9 — "which components improve or reduce performance", computed
 * honestly from resolved decisions rather than asserted. For each source
 * (Claude, OpenAI, Vision, Technical, SmartMoney, ...), looks at every
 * resolved decision where that source had an available, directional vote,
 * and checks: did that source's vote match the eventual outcome? A source
 * that voted BUY on a decision that resolved 'win' when the FINAL decision
 * was also BUY contributed correctly; voting BUY on a 'loss' did not.
 * Sources with too few resolved decisions to say anything real report as
 * such rather than a misleadingly precise-looking percentage — same
 * minimum-sample-size honesty as ConfidenceCalibrationService.
 */
final class DecisionPerformanceAnalyzer
{
    private const MIN_SAMPLE_SIZE = 5;

    /** A weekly bucket needs at least this many resolved decisions before a per-source accuracy number is shown for that week — lower than MIN_SAMPLE_SIZE because a trend chart needs SOME points to be a trend at all, but still refuses single-decision "100%" spikes. */
    private const MIN_TREND_BUCKET_SAMPLE = 3;

    /**
     * @return array<string, array{source:string, sample_size:int, correct:int, accuracy:?float, note:?string}>
     */
    public function bySource(int $days = 90): array
    {
        $decisions = ConsensusDecision::resolvedDecisions($days);

        $tally = []; // source => ['correct' => n, 'total' => n]

        foreach ($decisions as $row) {
            $outcome = (string) $row['outcome'];
            if ($outcome === 'timeout') {
                continue; // no directional resolution to score sources against
            }
            $finalDecision = (string) $row['overall_decision'];
            if (!\in_array($finalDecision, ['BUY', 'SELL'], true)) {
                continue;
            }

            $sources = json_decode((string) $row['sources_json'], true);
            if (!\is_array($sources)) {
                continue;
            }

            foreach ($sources as $s) {
                if (empty($s['is_directional']) || empty($s['available']) || ($s['direction'] ?? 'NEUTRAL') === 'NEUTRAL') {
                    continue;
                }
                $name = (string) $s['source'];
                $tally[$name] ??= ['correct' => 0, 'total' => 0];
                $tally[$name]['total']++;

                $sourceAgreedWithFinal = $s['direction'] === $finalDecision;
                $wasCorrect = ($sourceAgreedWithFinal && $outcome === 'win') || (!$sourceAgreedWithFinal && $outcome === 'loss');
                if ($wasCorrect) {
                    $tally[$name]['correct']++;
                }
            }
        }

        $result = [];
        foreach ($tally as $name => $counts) {
            $total = $counts['total'];
            $result[$name] = [
                'source'      => $name,
                'sample_size' => $total,
                'correct'     => $counts['correct'],
                'accuracy'    => $total >= self::MIN_SAMPLE_SIZE ? round($counts['correct'] / $total * 100, 1) : null,
                'note'        => $total >= self::MIN_SAMPLE_SIZE ? null : "Only {$total} resolved decisions — need " . self::MIN_SAMPLE_SIZE . ' for a reliable read.',
            ];
        }

        uasort($result, static fn(array $a, array $b): int => $b['sample_size'] <=> $a['sample_size']);
        return $result;
    }

    /** @return array{total:int, wins:int, losses:int, timeouts:int, win_rate:?float} Overall decision accuracy, same sample-size honesty. */
    public function overall(int $days = 90): array
    {
        $decisions = ConsensusDecision::resolvedDecisions($days);
        $wins = 0; $losses = 0; $timeouts = 0;
        foreach ($decisions as $row) {
            match ((string) $row['outcome']) {
                'win'  => $wins++,
                'loss' => $losses++,
                default => $timeouts++,
            };
        }
        $decided = $wins + $losses;
        return [
            'total'    => \count($decisions),
            'wins'     => $wins,
            'losses'   => $losses,
            'timeouts' => $timeouts,
            'win_rate' => $decided >= self::MIN_SAMPLE_SIZE ? round($wins / $decided * 100, 1) : null,
        ];
    }

    /**
     * "Are some strategies only effective during London sessions?" — the
     * session isn't a stored column on consensus_decisions, so it's read
     * back out of each decision's own frozen Session vote (SessionVoteAdapter
     * writes reasoning like "London session active." or "London session —
     * overlapping..."). Parsed from the historical snapshot, not the
     * CURRENT session, so this reflects what was actually live when each
     * decision was made.
     *
     * @return array<string, array{session:string, sample_size:int, wins:int, losses:int, win_rate:?float}>
     */
    public function overallBySession(int $days = 90): array
    {
        $decisions = ConsensusDecision::resolvedDecisions($days);
        $tally = [];

        foreach ($decisions as $row) {
            $outcome = (string) $row['outcome'];
            if ($outcome === 'timeout') {
                continue;
            }
            $session = $this->extractSession((string) $row['sources_json']);
            if ($session === null) {
                continue;
            }
            $tally[$session] ??= ['wins' => 0, 'losses' => 0];
            if ($outcome === 'win') {
                $tally[$session]['wins']++;
            } elseif ($outcome === 'loss') {
                $tally[$session]['losses']++;
            }
        }

        $result = [];
        foreach ($tally as $session => $counts) {
            $decided = $counts['wins'] + $counts['losses'];
            $result[$session] = [
                'session'     => $session,
                'sample_size' => $decided,
                'wins'        => $counts['wins'],
                'losses'      => $counts['losses'],
                'win_rate'    => $decided >= self::MIN_SAMPLE_SIZE ? round($counts['wins'] / $decided * 100, 1) : null,
            ];
        }

        uasort($result, static fn(array $a, array $b): int => $b['sample_size'] <=> $a['sample_size']);
        return $result;
    }

    private function extractSession(string $sourcesJson): ?string
    {
        $sources = json_decode($sourcesJson, true);
        if (!\is_array($sources)) {
            return null;
        }
        foreach ($sources as $s) {
            if (($s['source'] ?? null) !== 'Session' || empty($s['available'])) {
                continue;
            }
            $reasoning = (string) ($s['reasoning'] ?? '');
            if (preg_match('/^([A-Za-z ]+?) session/', $reasoning, $m) === 1) {
                return strtolower(trim($m[1]));
            }
            return null;
        }
        return null;
    }

    /**
     * "Does AI Vision improve results for H4 but not M5?" — deliberately
     * NOT implemented as a metric. VisionVoteAdapter's frozen snapshot only
     * records that a multi-timeframe chart analysis existed and its overall
     * risk level (see VisionVoteAdapter::vote()) — it never persists which
     * individual timeframe slot (M5/M15/H1/H4/...) drove the read, because
     * AI Vision analyzes several timeframes together as one composite
     * judgment, not one vote per timeframe. Answering this question for
     * real would require Vision to persist a per-timeframe breakdown at
     * analysis time — a schema change to App\Models\VisionSession, not
     * something derivable from data already collected. Surfaced as an
     * explicit disclosure on the Institutional Accuracy Dashboard rather
     * than silently omitted or answered with a fabricated number.
     */
    public function visionTimeframeAccuracyNote(): string
    {
        return 'Not currently computable: AI Vision records one composite decision per analysis, not a separate vote per timeframe, so accuracy cannot be split out by individual timeframe (e.g. H4 vs M5) from existing data.';
    }

    /**
     * "With trend charts over time" — buckets resolved decisions by ISO week
     * and computes overall win rate plus per-source accuracy for each week,
     * so the Institutional Accuracy Dashboard can plot a real trajectory
     * instead of one static snapshot number. Each bucket independently
     * applies MIN_TREND_BUCKET_SAMPLE — early/sparse weeks correctly show
     * gaps (null) rather than a misleading 100%-from-one-decision spike.
     *
     * @return array<int, array{week:string, sample_size:int, win_rate:?float, by_source:array<string,?float>}>
     */
    public function weeklyTrend(int $weeks = 12): array
    {
        $decisions = ConsensusDecision::resolvedDecisions($weeks * 7);

        $buckets = []; // ISO week key => ['wins'=>,'losses'=>,'timeouts'=>,'sources'=>[name=>['correct'=>,'total'=>]]]
        foreach ($decisions as $row) {
            $timestamp = strtotime((string) $row['generated_at']);
            if ($timestamp === false) {
                continue;
            }
            $weekKey = date('o-\WW', $timestamp);
            $buckets[$weekKey] ??= ['wins' => 0, 'losses' => 0, 'timeouts' => 0, 'sources' => []];

            $outcome = (string) $row['outcome'];
            match ($outcome) {
                'win'   => $buckets[$weekKey]['wins']++,
                'loss'  => $buckets[$weekKey]['losses']++,
                default => $buckets[$weekKey]['timeouts']++,
            };

            $finalDecision = (string) $row['overall_decision'];
            if ($outcome === 'timeout' || !\in_array($finalDecision, ['BUY', 'SELL'], true)) {
                continue;
            }
            $sources = json_decode((string) $row['sources_json'], true);
            if (!\is_array($sources)) {
                continue;
            }
            foreach ($sources as $s) {
                if (empty($s['is_directional']) || empty($s['available']) || ($s['direction'] ?? 'NEUTRAL') === 'NEUTRAL') {
                    continue;
                }
                $name = (string) $s['source'];
                $buckets[$weekKey]['sources'][$name] ??= ['correct' => 0, 'total' => 0];
                $buckets[$weekKey]['sources'][$name]['total']++;
                $agreed = $s['direction'] === $finalDecision;
                $wasCorrect = ($agreed && $outcome === 'win') || (!$agreed && $outcome === 'loss');
                if ($wasCorrect) {
                    $buckets[$weekKey]['sources'][$name]['correct']++;
                }
            }
        }

        ksort($buckets);

        $result = [];
        foreach ($buckets as $week => $b) {
            $decided = $b['wins'] + $b['losses'];
            $bySource = [];
            foreach ($b['sources'] as $name => $c) {
                $bySource[$name] = $c['total'] >= self::MIN_TREND_BUCKET_SAMPLE ? round($c['correct'] / $c['total'] * 100, 1) : null;
            }
            $result[] = [
                'week'        => $week,
                'sample_size' => $b['wins'] + $b['losses'] + $b['timeouts'],
                'win_rate'    => $decided >= self::MIN_TREND_BUCKET_SAMPLE ? round($b['wins'] / $decided * 100, 1) : null,
                'by_source'   => $bySource,
            ];
        }
        return $result;
    }
}
