<?php
declare(strict_types=1);
namespace App\TradeCoach;

/**
 * Static educational content bank for the AI Trade Coach. Deliberately not
 * AI-generated and not randomized — every field here is deterministic per
 * (decision, mode) so the coaching experience works identically whether or
 * not an AI provider is configured (mirrors ExplanationEngine's rule-based
 * reliability). TradeCoachAiService layers an optional natural-language
 * narrative on top of this; it never replaces it.
 *
 * "Beginner" mode spells out terms and leans on analogy; "professional"
 * mode assumes the reader already knows the vocabulary and gets to the
 * point. Content itself (the risk warnings, the mistakes, the psychology)
 * is the same substance in both modes — only the framing changes.
 */
final class CoachContentLibrary
{
    private const VALID_DECISIONS = ['BUY', 'SELL', 'WAIT', 'NO_TRADE'];
    private const VALID_MODES     = ['beginner', 'professional'];

    /** @return array<string> */
    public static function decisionHeadline(string $decision, string $mode): string
    {
        $decision = self::normalizeDecision($decision);
        $mode     = self::normalizeMode($mode);

        $headlines = [
            'BUY' => [
                'beginner'     => 'BUY means the system sees enough evidence to expect the price to rise — it is a signal to consider opening a long position, never a guarantee.',
                'professional' => 'BUY: directional trend, momentum, and the weighted confidence score are aligned long. Treat as a probability edge, not a certainty.',
            ],
            'SELL' => [
                'beginner'     => 'SELL means the system sees enough evidence to expect the price to fall — it is a signal to consider a short position, never a guarantee.',
                'professional' => 'SELL: directional trend, momentum, and the weighted confidence score are aligned short. Treat as a probability edge, not a certainty.',
            ],
            'WAIT' => [
                'beginner'     => 'WAIT means there might be an opportunity forming, but something important has not confirmed yet — patience protects you here.',
                'professional' => 'WAIT: a partial setup exists, but one or more confirmation conditions (momentum, confidence threshold, or calendar risk) have not cleared.',
            ],
            'NO_TRADE' => [
                'beginner'     => 'NO TRADE means there is no clear direction at all right now — this is not a missed opportunity, it is the market telling you to sit this one out.',
                'professional' => 'NO_TRADE: no directional trend is present on this timeframe. Standing aside preserves capital for a setup with actual edge.',
            ],
        ];

        return $headlines[$decision][$mode];
    }

    public static function riskExplanation(string $riskLevel, string $mode): string
    {
        $riskLevel = \in_array($riskLevel, ['high', 'medium', 'low'], true) ? $riskLevel : 'medium';
        $mode      = self::normalizeMode($mode);

        $risk = [
            'high' => [
                'beginner'     => 'Risk is HIGH right now, usually because a major news event is close by. News releases can send price sharply in either direction within seconds — even a technically "correct" setup can get stopped out by the noise.',
                'professional' => 'HIGH risk — active or imminent high-impact calendar event. Expect spread widening and slippage; size down or stand aside until the event window passes.',
            ],
            'medium' => [
                'beginner'     => 'Risk is at a NORMAL level. That still means real money can be lost — always use a stop loss and never risk more than 1-2% of your account on one trade.',
                'professional' => 'MEDIUM (baseline) risk — no active calendar block. Standard position sizing and stop placement apply; this is not a green light to oversize.',
            ],
            'low' => [
                'beginner'     => 'Risk reads as LOW relative to normal conditions, but "low" is not "zero" — every trade can still lose. Keep your stop loss in place regardless.',
                'professional' => 'LOW relative risk reading. Still subject to normal market risk — do not let a favorable reading justify skipping a stop loss.',
            ],
        ];

        return $risk[$riskLevel][$mode];
    }

    /** @return array<string> Two curated tips. */
    public static function educationalTips(string $decision, string $mode): array
    {
        $decision = self::normalizeDecision($decision);
        $mode     = self::normalizeMode($mode);

        $tips = [
            'BUY' => [
                'beginner' => [
                    'A "confirmed" trend just means several indicators agree on direction right now — it can still change. Re-check before you act if time has passed since this was generated.',
                    'Decide your stop loss and take-profit levels before you enter, not after. Deciding under pressure while the trade is open leads to worse decisions.',
                ],
                'professional' => [
                    'Confluence across timeframes reduces false positives but does not eliminate them — confirm the setup is still valid at execution time.',
                    'Pre-define invalidation and target levels; in-trade discretionary adjustment is where most edge gets given back.',
                ],
            ],
            'SELL' => [
                'beginner' => [
                    'Shorting (selling first, buying back later) works the same way as buying in reverse — the same discipline about stop losses applies.',
                    'A strong bearish signal after a long uptrend can still be a temporary pullback, not a reversal — check the higher timeframe context if you have it.',
                ],
                'professional' => [
                    'Bearish confluence following an extended uptrend warrants checking higher-timeframe structure before assuming reversal over retracement.',
                    'Short setups often carry faster, sharper moves against you on a squeeze — respect the stop, do not widen it mid-trade.',
                ],
            ],
            'WAIT' => [
                'beginner' => [
                    'Waiting is a decision too, not a failure to decide. Most beginners lose money by acting during the "wait" phase out of impatience.',
                    'Set an alert instead of watching the screen. Staring at price while waiting for confirmation tempts you into entering early.',
                ],
                'professional' => [
                    'A WAIT state has positive expected value versus forcing an unconfirmed entry — the cost of missing a move is smaller than the cost of a bad one.',
                    'Use this window to review your process (risk sizing, invalidation levels) rather than to search for a reason to enter anyway.',
                ],
            ],
            'NO_TRADE' => [
                'beginner' => [
                    'Not every day has a trade in it, and that is fine. Professional traders often trade less often than beginners expect.',
                    'Use a no-trade day to review your journal instead of forcing a position out of boredom.',
                ],
                'professional' => [
                    'Sitting out a directionless session is itself risk management — overtrading in chop is a leading cause of avoidable drawdown.',
                    'Absence of a signal is information: it tells you conditions do not currently favor your strategy, not that your strategy failed.',
                ],
            ],
        ];

        return $tips[$decision][$mode];
    }

    /** @return array<string> Two curated common mistakes tied to this decision. */
    public static function commonMistakes(string $decision, string $mode): array
    {
        $decision = self::normalizeDecision($decision);
        $mode     = self::normalizeMode($mode);

        $mistakes = [
            'BUY' => [
                'beginner' => [
                    'Entering late after most of the move already happened, chasing the price instead of the setup.',
                    'Skipping the stop loss because "it looks so strong it can\'t go wrong" — it can.',
                ],
                'professional' => [
                    'Chasing extended moves rather than waiting for a retracement into value.',
                    'Removing or widening stops mid-trade in response to unrealized drawdown.',
                ],
            ],
            'SELL' => [
                'beginner' => [
                    'Shorting purely because price "feels high," without the technical signal actually confirming it.',
                    'Doubling position size to "make back" a previous loss on the next short.',
                ],
                'professional' => [
                    'Fading strength without a confirmed technical trigger, on the assumption that "it has to reverse eventually."',
                    'Revenge-sizing after a prior loss instead of sticking to the predefined risk unit.',
                ],
            ],
            'WAIT' => [
                'beginner' => [
                    'Entering anyway because "it looks like it\'s about to confirm" — that is a guess, not a confirmed setup.',
                    'Checking the chart every few minutes, which increases the temptation to jump in early.',
                ],
                'professional' => [
                    'Front-running the confirmation condition instead of letting it actually trigger.',
                    'Treating a WAIT state as a countdown to a guaranteed entry rather than a conditional one.',
                ],
            ],
            'NO_TRADE' => [
                'beginner' => [
                    'Forcing a trade out of boredom or a feeling that you "should" be doing something.',
                    'Switching to a much lower timeframe just to find any signal, however weak.',
                ],
                'professional' => [
                    'Lowering your own confluence bar on a slow day rather than accepting no edge is present.',
                    'Rotating into unfamiliar pairs or timeframes purely to manufacture activity.',
                ],
            ],
        ];

        return $mistakes[$decision][$mode];
    }

    public static function psychologyGuidance(string $decision, string $mode): string
    {
        $decision = self::normalizeDecision($decision);
        $mode     = self::normalizeMode($mode);

        $psychology = [
            'BUY' => [
                'beginner'     => 'Watch for FOMO (fear of missing out) — the excitement of a fresh BUY signal is exactly when discipline matters most. Follow your plan, not your adrenaline.',
                'professional' => 'FOMO risk is highest immediately after signal generation. Execute against your pre-defined plan, not the emotional charge of a fresh confluence read.',
            ],
            'SELL' => [
                'beginner'     => 'Shorting can feel emotionally uncomfortable ("betting against the market") — that discomfort is normal and is not a signal that the setup is wrong.',
                'professional' => 'Directional bias against a broader uptrend narrative can trigger self-doubt independent of setup quality — separate narrative discomfort from technical invalidation.',
            ],
            'WAIT' => [
                'beginner'     => 'Impatience is the main emotional risk here. Boredom can masquerade as intuition ("I just feel like it will move now") — that feeling is not data.',
                'professional' => 'Impatience-driven early entries are the dominant failure mode in a WAIT state — track how often you override a WAIT and what it costs you.',
            ],
            'NO_TRADE' => [
                'beginner'     => 'This is where discipline is hardest to see but most valuable: doing nothing well is still doing something right.',
                'professional' => 'No-trade discipline compounds over a career; the visible cost (a missed session) is smaller than the invisible cost of low-edge overtrading.',
            ],
        ];

        return $psychology[$decision][$mode];
    }

    public static function normalizeDecision(string $decision): string
    {
        $decision = strtoupper(trim($decision));
        return \in_array($decision, self::VALID_DECISIONS, true) ? $decision : 'NO_TRADE';
    }

    private static function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return \in_array($mode, self::VALID_MODES, true) ? $mode : 'beginner';
    }
}
