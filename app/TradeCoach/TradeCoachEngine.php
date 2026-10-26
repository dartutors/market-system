<?php
declare(strict_types=1);
namespace App\TradeCoach;

use DateTimeImmutable;

/**
 * Rule-based coaching layer over an already-computed decision. Deliberately
 * does not recompute DecisionEngine/ExplanationEngine logic — it consumes
 * their output (as persisted in the ai_decisions table: decision, confidence,
 * reasoning, conflicts, risk_level) and teaches the user what it means, per
 * App\TradeCoach\CoachContentLibrary. Works with zero AI configuration;
 * App\TradeCoach\TradeCoachAiService layers an optional narrative on top.
 */
final class TradeCoachEngine
{
    /**
     * @param array{market_label:string, decision:string, confidence:float|string, risk_level:string, reasoning:string, conflicts:?string} $decisionRow
     *        Shape matches a row from App\Models\AiDecision::latestForMarket().
     * @param array{provider:string, model:string, narrative:string, risk_note:string}|null $aiNarrative
     */
    public function explain(array $decisionRow, string $mode, ?array $aiNarrative = null): CoachExplanation
    {
        $decision  = CoachContentLibrary::normalizeDecision((string) ($decisionRow['decision'] ?? 'NO_TRADE'));
        $mode      = strtolower(trim($mode)) === 'professional' ? 'professional' : 'beginner';
        $riskLevel = (string) ($decisionRow['risk_level'] ?? 'medium');

        $reasoning = self::splitLines((string) ($decisionRow['reasoning'] ?? ''));
        $conflicts = self::splitLines((string) ($decisionRow['conflicts'] ?? ''));

        return new CoachExplanation(
            marketLabel: (string) ($decisionRow['market_label'] ?? ''),
            decision: $decision,
            confidence: (float) ($decisionRow['confidence'] ?? 0.0),
            riskLevel: $riskLevel,
            mode: $mode,
            reasoning: $reasoning,
            conflicts: $conflicts,
            headline: CoachContentLibrary::decisionHeadline($decision, $mode),
            riskExplanation: CoachContentLibrary::riskExplanation($riskLevel, $mode),
            educationalTips: CoachContentLibrary::educationalTips($decision, $mode),
            commonMistakes: CoachContentLibrary::commonMistakes($decision, $mode),
            psychologyGuidance: CoachContentLibrary::psychologyGuidance($decision, $mode),
            aiNarrative: $aiNarrative,
            generatedAt: new DateTimeImmutable('now'),
        );
    }

    /** @return array<string> */
    public static function splitLines(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode("\n", $text)), static fn(string $l): bool => $l !== ''));
    }
}
