<?php
declare(strict_types=1);
namespace App\Consensus\Adapters;

use App\AI\AIManager;
use App\AI\PromptLoader;
use App\Consensus\ConsensusVote;
use App\Consensus\EvidenceWeights;
use Throwable;

/**
 * Shared base for the Claude and OpenAI consensus voters. Unlike every
 * other AI call site in this app (which chains primary->fallback via
 * AIManager::complete()), these two are deliberately independent, parallel
 * "voters" — Claude and OpenAI each need to answer for themselves so the
 * consensus panel can show them as two separate rows, not one call with a
 * silent fallback. Both call AIManager::generateWith() directly instead.
 *
 * Needs pre-computed context from the cheap, non-AI voters (Technical,
 * News, Calendar, CurrencyStrength, Session, Volatility) — the engine
 * gathers those first and passes them in, rather than this adapter
 * re-deriving them (would mean re-running the same fetches twice).
 */
abstract class AbstractAiOpinionAdapter
{
    abstract protected function providerName(): string;
    abstract protected function sourceLabel(): string;

    public function voteWithContext(string $label, array $contextVotes): ConsensusVote
    {
        try {
            $technical = $contextVotes['Technical'] ?? null;
            $news      = $contextVotes['News'] ?? null;
            $calendar  = $contextVotes['Calendar'] ?? null;
            $strength  = $contextVotes['CurrencyStrength'] ?? null;
            $session   = $contextVotes['Session'] ?? null;
            $volatility = $contextVotes['Volatility'] ?? null;

            $prompt = PromptLoader::load('consensus_opinion', [
                'market_label'           => $label,
                'technical_direction'    => $technical?->direction ?? 'UNKNOWN',
                'technical_confidence'   => $technical !== null ? (string) round($technical->confidence) : '0',
                'technical_reasons'      => $technical?->reasoning ?? 'not available',
                'news_label'             => $news?->direction ?? 'NEUTRAL',
                'news_score'             => $news?->reasoning ?? 'no recent headlines',
                'calendar_status'        => $calendar?->reasoning ?? 'no active calendar risk',
                'currency_strength_note' => $strength?->reasoning ?? 'not available',
                'session_name'           => $session?->reasoning ?? 'unknown session',
                'session_note'           => $session?->direction ?? '',
                'volatility_level'       => $volatility?->reasoning ?? 'unknown',
            ]);

            $response = (new AIManager())->generateWith(
                $this->providerName(),
                $prompt['system'],
                $prompt['user'],
                ['service' => 'consensus_opinion_' . $this->providerName()]
            );

            if ($response === null) {
                return $this->unavailable();
            }

            $parsed = $this->parseResponse($response->text);
            if ($parsed === null) {
                return $this->unavailable();
            }

            return new ConsensusVote(
                source: $this->sourceLabel(),
                direction: $parsed['direction'],
                confidence: $parsed['confidence'],
                weight: EvidenceWeights::for($this->sourceLabel(), 1.5),
                reasoning: $parsed['reasoning'],
                available: true,
            );
        } catch (Throwable $e) {
            error_log("[{$this->sourceLabel()}OpinionAdapter] {$label} failed: " . $e->getMessage());
            return $this->unavailable();
        }
    }

    /** @return array{direction:string, confidence:float, reasoning:string}|null */
    private function parseResponse(string $text): ?array
    {
        $text = trim($text);
        $text = (string) preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = (string) preg_replace('/\s*```\s*$/i', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);
        if (!\is_array($data)) {
            error_log("[{$this->sourceLabel()}OpinionAdapter] Unparseable JSON: " . mb_substr($text, 0, 300));
            return null;
        }

        $direction = strtoupper(trim((string) ($data['direction'] ?? '')));
        if (!\in_array($direction, ['BUY', 'SELL', 'WAIT'], true)) {
            return null;
        }

        $reasoning = trim((string) ($data['reasoning'] ?? ''));
        if ($reasoning === '') {
            return null;
        }

        return [
            'direction'  => $direction === 'WAIT' ? ConsensusVote::DIRECTION_NEUTRAL : $direction,
            'confidence' => max(0.0, min(100.0, (float) ($data['confidence'] ?? 0))),
            'reasoning'  => $reasoning,
        ];
    }

    private function unavailable(): ConsensusVote
    {
        return new ConsensusVote($this->sourceLabel(), ConsensusVote::DIRECTION_NEUTRAL, 0.0, 1.5, 'AI opinion unavailable right now.', false);
    }
}
