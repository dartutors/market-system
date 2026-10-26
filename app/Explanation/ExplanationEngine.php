<?php
declare(strict_types=1);
namespace App\Explanation;

use App\Decision\DecisionResult;

/**
 * Renders human-readable text from a DecisionResult's structured facts.
 * reasoning()/conflicts() reproduce the exact sentences that used to be
 * built inline inside DecisionEngine (moved, not reworded — verified by a
 * parity script against the pre-Phase-4 behavior). The other four fields
 * are net-new: confidence/risk/conflict/educational explanations that did
 * not exist before this sprint.
 */
final class ExplanationEngine
{
    public function explain(DecisionResult $result): ExplanationResult
    {
        $facts = $result->facts;

        if ($facts['branch'] === 'calendar_blocked') {
            $reasoning = ["Economic calendar blocks new entries: {$facts['event_title']}."];
            $conflicts = [];
        } else {
            $conflicts = [];
            if ($facts['momentum_aligned'] === false) {
                $conflicts[] = "Technical trend ({$facts['trend']}) is not confirmed by momentum ({$facts['momentum']}).";
            }

            $reasoning   = [];
            $reasoning[] = "Technical trend: {$facts['trend']}; momentum: {$facts['momentum']}.";
            $reasoning[] = 'News sentiment score: ' . round($facts['news_score'], 1) . '.';
            $reasoning[] = "AI bias: {$facts['ai_bias']} at {$facts['ai_confidence']}% confidence.";
            if ($facts['backtest_win_rate'] !== null) {
                $reasoning[] = "Backtest win rate: {$facts['backtest_win_rate']}%.";
            }

            $reasoning[] = match ($facts['branch']) {
                'no_direction'      => 'No directional trend — nothing to act on.',
                'momentum_conflict' => 'Conflicting signals — standing aside until momentum confirms.',
                'low_confidence'    => 'Combined confidence is below the action threshold — waiting for stronger confirmation.',
                'aligned'           => "All factors align for a {$facts['direction']} recommendation.",
                default             => 'No further detail available.',
            };
        }

        return new ExplanationResult(
            reasoning: $reasoning,
            conflicts: $conflicts,
            tradeExplanation: $this->buildTradeExplanation($result, $facts),
            confidenceExplanation: $this->buildConfidenceExplanation($result),
            riskExplanation: $this->buildRiskExplanation($result),
            conflictingSignalExplanation: $this->buildConflictExplanation($conflicts),
            educationalExplanation: $this->buildEducationalExplanation($result),
        );
    }

    private function buildTradeExplanation(DecisionResult $result, array $facts): string
    {
        if ($facts['branch'] === 'calendar_blocked') {
            return "No trade is recommended right now because a high-impact economic event "
                 . "({$facts['event_title']}) is close enough to distort price action.";
        }

        if ($result->decision === 'NO_TRADE') {
            return 'There is no directional trend on the current timeframe, so there is nothing '
                 . 'to trade — this is a "stay out" reading, not a missed opportunity.';
        }

        if ($result->decision === 'WAIT') {
            return $facts['momentum_aligned'] === false
                ? "A {$facts['trend']} trend exists, but momentum ({$facts['momentum']}) has not "
                  . 'confirmed it yet — waiting avoids entering before the move is real.'
                : 'The combined evidence is not strong enough yet to justify a position — '
                  . 'waiting costs nothing; a bad entry does.';
        }

        return "A {$result->decision} setup exists because the technical trend ({$facts['trend']}), "
             . "momentum ({$facts['momentum']}), and the other factors below are aligned with "
             . "{$result->confidence}% combined confidence.";
    }

    private function buildConfidenceExplanation(DecisionResult $result): string
    {
        $parts = [];
        foreach ($result->scoreBreakdown as $factor => $detail) {
            $weightPct = round(((float) $detail['weight']) * 100);
            $parts[]   = "{$factor}: {$detail['contribution']} pts (weight {$weightPct}%)";
        }

        return "Confidence of {$result->confidence}% is the weighted sum of: " . implode(', ', $parts) . '.';
    }

    private function buildRiskExplanation(DecisionResult $result): string
    {
        return match ($result->riskLevel) {
            'high'   => 'Risk is elevated — an economic event nearby can cause a volatility spike '
                      . 'that stops out an otherwise valid setup.',
            'medium' => 'Risk is moderate — normal market conditions apply; use standard position '
                      . 'sizing and a stop loss.',
            default  => 'Risk level could not be determined — treat with caution.',
        };
    }

    private function buildConflictExplanation(array $conflicts): string
    {
        if (empty($conflicts)) {
            return 'No conflicting signals were detected — the factors considered point in the same direction.';
        }

        return 'Conflicting signals detected: ' . implode(' ', $conflicts);
    }

    private function buildEducationalExplanation(DecisionResult $result): string
    {
        return match ($result->decision) {
            'BUY', 'SELL' => 'Educational note: even an aligned setup can fail. Never risk more '
                           . 'than 1–2% of your account on a single position, and always use a stop loss.',
            'WAIT'        => 'Educational note: waiting is a valid decision. Many losing trades '
                           . 'come from acting before a setup is actually confirmed.',
            default       => 'Educational note: not every day has a trade in it. Preserving '
                           . 'capital on a no-trade day is itself a form of discipline.',
        };
    }
}
