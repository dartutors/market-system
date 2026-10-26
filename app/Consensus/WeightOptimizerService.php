<?php
declare(strict_types=1);
namespace App\Consensus;

use App\Models\ConsensusDecision;
use App\Models\EvidenceSourceWeight;
use App\Models\WeightChangeProposal;
use Throwable;

/**
 * "Should some evidence sources receive less weight over time?" — measured,
 * not assumed. This does NOT claim to know what would have happened to a
 * DIFFERENT hypothetical decision (we only ever resolved an outcome for the
 * trade actually recommended) — instead it asks a narrower, honestly
 * answerable question: of the trades this system actually recommended and
 * later resolved, how many would a reweighted vote have kept the SAME
 * direction on vs. changed? Of the ones that would change, how many were
 * winners we'd have missed vs. losers we'd have avoided? A reweighting only
 * becomes a proposal when that net effect is both consistently positive AND
 * large enough, across enough resolved decisions, not to be noise.
 *
 * Every proposal this generates is inert until an administrator approves it
 * — see App\Models\WeightChangeProposal and
 * App\Controllers\LearningController::approveWeightChange(). Nothing here
 * ever calls EvidenceSourceWeight::set() directly.
 */
final class WeightOptimizerService
{
    /** Below this many resolved win/loss decisions, any weight simulation is noise, not signal — refuse to propose anything. */
    private const MIN_SAMPLE_SIZE = 30;

    /** A net effect (avoided losses − lost wins) below this, even with enough samples, isn't worth an admin's attention. */
    private const MIN_MEANINGFUL_NET_EFFECT = 3;

    private const CANDIDATE_RELATIVE_ADJUSTMENTS = [-0.30, -0.20, -0.10, 0.10, 0.20, 0.30];

    private const DIRECTIONAL_SOURCES = [
        'Technical', 'Claude', 'OpenAI', 'Vision', 'TrendAlignment',
        'SmartMoney', 'CurrencyStrength', 'StrategyLab', 'News',
    ];

    public function __construct(private ConsensusScorer $scorer = new ConsensusScorer())
    {
    }

    /** @return array<string, array> keyed by source, each entry either an analysis result or an insufficient-data marker */
    public function analyzeAllSources(int $days = 90): array
    {
        $decisions = $this->resolvedDirectionalDecisions($days);
        $results = [];
        foreach (self::DIRECTIONAL_SOURCES as $source) {
            $results[$source] = $this->analyzeSource($source, $decisions);
        }
        return $results;
    }

    /**
     * @param array<int, array<string, mixed>>|null $decisions Pass a pre-fetched set to avoid re-querying per source.
     * @return array{source:string, sample_size:int, insufficient_data:bool, current_weight?:float, best_weight?:float, avoided_losses?:int, lost_wins?:int, net_effect?:int, recommendation?:?string}
     */
    public function analyzeSource(string $source, ?array $decisions = null, int $days = 90): array
    {
        $decisions ??= $this->resolvedDirectionalDecisions($days);
        $sampleSize = \count($decisions);

        if ($sampleSize < self::MIN_SAMPLE_SIZE) {
            return [
                'source'            => $source,
                'sample_size'       => $sampleSize,
                'insufficient_data' => true,
                'note'              => "Only {$sampleSize} resolved win/loss decisions in the last {$days} days — need at least " . self::MIN_SAMPLE_SIZE . ' for a reweighting simulation to mean anything.',
            ];
        }

        $currentWeight = EvidenceSourceWeight::all()[$source] ?? $this->defaultWeightFor($source);

        $best = null;
        foreach (self::CANDIDATE_RELATIVE_ADJUSTMENTS as $delta) {
            $candidateWeight = round(max(0.0, $currentWeight * (1 + $delta)), 3);
            $effect = $this->simulateNetEffect($decisions, $source, $currentWeight, $candidateWeight);

            if ($best === null || $effect['net_effect'] > $best['net_effect']) {
                $best = $effect + ['candidate_weight' => $candidateWeight, 'delta' => $delta];
            }
        }

        $recommendation = null;
        if ($best['net_effect'] >= self::MIN_MEANINGFUL_NET_EFFECT) {
            $direction = $best['delta'] > 0 ? 'increasing' : 'decreasing';
            $recommendation = sprintf(
                '%s %s weight from %.2f to %.2f (%+.0f%%) would have avoided %d loss(es) at the cost of %d missed win(s) — a net improvement of %d, across %d resolved decisions.',
                $direction === 'increasing' ? 'Increasing' : 'Decreasing',
                $source,
                $currentWeight,
                $best['candidate_weight'],
                $best['delta'] * 100,
                $best['avoided_losses'],
                $best['lost_wins'],
                $best['net_effect'],
                $sampleSize
            );
        }

        return [
            'source'            => $source,
            'sample_size'       => $sampleSize,
            'insufficient_data' => false,
            'current_weight'    => $currentWeight,
            'best_weight'       => $best['candidate_weight'],
            'avoided_losses'    => $best['avoided_losses'],
            'lost_wins'         => $best['lost_wins'],
            'net_effect'        => $best['net_effect'],
            'recommendation'    => $recommendation,
        ];
    }

    /**
     * Creates a pending proposal (Objective: "transparent, reviewable,
     * requires administrator approval") for any source whose analysis
     * crossed the meaningful-effect threshold and doesn't already have one
     * pending. Never applies anything itself.
     *
     * @return int Number of new proposals created.
     */
    public function generateProposals(int $days = 90): int
    {
        $created = 0;
        foreach ($this->analyzeAllSources($days) as $source => $analysis) {
            if ($analysis['insufficient_data'] || $analysis['recommendation'] === null) {
                continue;
            }
            try {
                if (WeightChangeProposal::pendingForSource($source) !== null) {
                    continue; // don't pile up duplicate proposals for the same source
                }
                WeightChangeProposal::create([
                    'source_key'      => $source,
                    'current_weight'  => $analysis['current_weight'],
                    'proposed_weight' => $analysis['best_weight'],
                    'rationale'       => $analysis['recommendation'],
                    'evidence_json'   => json_encode($analysis, JSON_UNESCAPED_UNICODE),
                    'sample_size'     => $analysis['sample_size'],
                ]);
                $created++;
            } catch (Throwable $e) {
                error_log("[WeightOptimizerService] Failed to create proposal for {$source}: " . $e->getMessage());
            }
        }
        return $created;
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     * @return array{avoided_losses:int, lost_wins:int, net_effect:int}
     */
    private function simulateNetEffect(array $decisions, string $targetSource, float $currentWeight, float $candidateWeight): array
    {
        $avoidedLosses = 0;
        $lostWins = 0;

        foreach ($decisions as $row) {
            $sources = json_decode((string) $row['sources_json'], true);
            if (!\is_array($sources)) {
                continue;
            }

            $actualDirection    = (string) $row['overall_decision'];
            $simulatedDirection = $this->simulateDecision($sources, $targetSource, $candidateWeight);

            if ($simulatedDirection === $actualDirection) {
                continue; // no change under the candidate weight — doesn't affect the comparison either way
            }

            if ($row['outcome'] === 'win') {
                $lostWins++;
            } elseif ($row['outcome'] === 'loss') {
                $avoidedLosses++;
            }
        }

        return ['avoided_losses' => $avoidedLosses, 'lost_wins' => $lostWins, 'net_effect' => $avoidedLosses - $lostWins];
    }

    /** Rebuilds ConsensusVote objects from a persisted sources_json snapshot, substituting one source's weight, and re-runs the real scoring algorithm — the same ConsensusScorer used live, not a re-implementation that could drift from it. */
    private function simulateDecision(array $sourcesSnapshot, string $targetSource, float $candidateWeight): string
    {
        $votes = [];
        foreach ($sourcesSnapshot as $s) {
            if (empty($s['is_directional'])) {
                continue; // context/modifier sources never entered the tally to begin with
            }
            $weight = $s['source'] === $targetSource ? $candidateWeight : (float) ($s['weight'] ?? 0.0);
            $votes[] = new ConsensusVote(
                source: (string) $s['source'],
                direction: (string) $s['direction'],
                confidence: (float) $s['confidence'],
                weight: $weight,
                reasoning: (string) ($s['reasoning'] ?? ''),
                available: (bool) ($s['available'] ?? false),
                isDirectional: true,
                isBlocking: (bool) ($s['is_blocking'] ?? false),
            );
        }

        $result = $this->scorer->score($votes);
        return $result['overall_decision'] === ConsensusVote::DIRECTION_NEUTRAL ? 'WAIT' : $result['overall_decision'];
    }

    /** @return array<int, array<string, mixed>> */
    private function resolvedDirectionalDecisions(int $days): array
    {
        $decisions = ConsensusDecision::resolvedDecisions($days);
        return array_values(array_filter(
            $decisions,
            static fn(array $d): bool => \in_array($d['outcome'], ['win', 'loss'], true)
                && \in_array($d['overall_decision'], ['BUY', 'SELL'], true)
        ));
    }

    private function defaultWeightFor(string $source): float
    {
        return match ($source) {
            'Technical' => 2.0,
            'Claude', 'OpenAI', 'Vision' => 1.5,
            'TrendAlignment', 'SmartMoney' => 1.25,
            'CurrencyStrength', 'StrategyLab' => 1.0,
            'News' => 0.75,
            default => 1.0,
        };
    }
}
