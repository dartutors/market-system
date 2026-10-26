<?php
declare(strict_types=1);

/**
 * Deterministic regression checks for the Phase 2.4 AI Trade Coach
 * (CoachContentLibrary, TradeCoachEngine, TradeCoachAiService). Uses a
 * synthetic ai_decisions-shaped row fixture — no network calls needed for
 * the rule-based checks. The AI narrative check tolerates either a live
 * provider response or null (USE_AI_SUMMARY may be on or off depending on
 * environment — see bin/verify-phase2-3-mtf.php for the same convention).
 * Run with:
 *
 *   php bin/verify-phase2-4-trade-coach.php
 *
 * Exits 0 if all checks pass, 1 otherwise.
 */

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\Services\FeatureFlagService;
use App\TradeCoach\CoachContentLibrary;
use App\TradeCoach\TradeCoachAiService;
use App\TradeCoach\TradeCoachEngine;

$failures = 0;

function checkTrue(string $label, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "PASS  {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

// ── 1. CoachContentLibrary covers all decisions x modes with non-empty content ─
$decisions = ['BUY', 'SELL', 'WAIT', 'NO_TRADE'];
$modes     = ['beginner', 'professional'];

foreach ($decisions as $decision) {
    foreach ($modes as $mode) {
        checkTrue(
            "CoachContentLibrary::decisionHeadline({$decision}, {$mode}) is non-empty",
            trim(CoachContentLibrary::decisionHeadline($decision, $mode)) !== ''
        );

        $tips = CoachContentLibrary::educationalTips($decision, $mode);
        checkTrue("CoachContentLibrary::educationalTips({$decision}, {$mode}) returns 2 tips", count($tips) === 2, (string) count($tips));

        $mistakes = CoachContentLibrary::commonMistakes($decision, $mode);
        checkTrue("CoachContentLibrary::commonMistakes({$decision}, {$mode}) returns 2 mistakes", count($mistakes) === 2, (string) count($mistakes));

        checkTrue(
            "CoachContentLibrary::psychologyGuidance({$decision}, {$mode}) is non-empty",
            trim(CoachContentLibrary::psychologyGuidance($decision, $mode)) !== ''
        );
    }
}

foreach (['high', 'medium', 'low'] as $riskLevel) {
    foreach ($modes as $mode) {
        checkTrue(
            "CoachContentLibrary::riskExplanation({$riskLevel}, {$mode}) is non-empty",
            trim(CoachContentLibrary::riskExplanation($riskLevel, $mode)) !== ''
        );
    }
}

checkTrue(
    'CoachContentLibrary normalizes an unknown decision to NO_TRADE content',
    CoachContentLibrary::decisionHeadline('BOGUS', 'beginner') === CoachContentLibrary::decisionHeadline('NO_TRADE', 'beginner')
);
checkTrue(
    'CoachContentLibrary normalizes an unknown mode to beginner content',
    CoachContentLibrary::decisionHeadline('BUY', 'bogus_mode') === CoachContentLibrary::decisionHeadline('BUY', 'beginner')
);

// ── 2. TradeCoachEngine::splitLines ─────────────────────────────────────────
checkTrue(
    'TradeCoachEngine::splitLines() splits on newlines and drops blanks',
    TradeCoachEngine::splitLines("Line one.\n\nLine two.\n") === ['Line one.', 'Line two.']
);
checkTrue('TradeCoachEngine::splitLines() returns [] for empty input', TradeCoachEngine::splitLines('') === []);

// ── 3. TradeCoachEngine::explain() from a synthetic ai_decisions row ────────
$decisionRow = [
    'market_label' => 'EUR/USD',
    'decision'     => 'BUY',
    'confidence'   => '72.50',
    'risk_level'   => 'medium',
    'reasoning'    => "Technical trend: bullish; momentum: strengthening.\nAI bias: bullish_usd at 65% confidence.\nAll factors align for a BUY recommendation.",
    'conflicts'    => '',
];

$explanation = (new TradeCoachEngine())->explain($decisionRow, 'beginner');
checkTrue('TradeCoachEngine::explain() carries through the decision unchanged', $explanation->decision === 'BUY');
checkTrue('TradeCoachEngine::explain() carries through confidence as float', abs($explanation->confidence - 72.5) < 0.01, (string) $explanation->confidence);
checkTrue('TradeCoachEngine::explain() parses 3 reasoning lines', count($explanation->reasoning) === 3, (string) count($explanation->reasoning));
checkTrue('TradeCoachEngine::explain() parses 0 conflict lines from an empty string', $explanation->conflicts === []);
checkTrue('TradeCoachEngine::explain() defaults to beginner mode content', $explanation->headline === CoachContentLibrary::decisionHeadline('BUY', 'beginner'));
checkTrue('TradeCoachEngine::explain() aiNarrative is null when none is passed', $explanation->aiNarrative === null);

$professionalExplanation = (new TradeCoachEngine())->explain($decisionRow, 'professional');
checkTrue(
    'TradeCoachEngine::explain() switches content when mode=professional',
    $professionalExplanation->headline === CoachContentLibrary::decisionHeadline('BUY', 'professional')
    && $professionalExplanation->headline !== $explanation->headline
);

$explanationArray = $explanation->toArray();
checkTrue(
    'CoachExplanation::toArray() contains all Trade Coach keys',
    array_diff(
        ['market_label', 'decision', 'confidence', 'risk_level', 'mode', 'reasoning', 'conflicts',
         'headline', 'risk_explanation', 'educational_tips', 'common_mistakes', 'psychology_guidance', 'ai_narrative', 'generated_at'],
        array_keys($explanationArray)
    ) === []
);

// A row with an unknown decision value should not throw and should fall back sanely.
$unknownRow = ['market_label' => 'GBP/USD', 'decision' => 'HOLD', 'confidence' => 10, 'risk_level' => 'unknown_level', 'reasoning' => '', 'conflicts' => null];
$unknownExplanation = (new TradeCoachEngine())->explain($unknownRow, 'beginner');
checkTrue('TradeCoachEngine::explain() normalizes an unrecognized decision to NO_TRADE', $unknownExplanation->decision === 'NO_TRADE');

// ── 4. PromptLoader loads the trade_coach template with mode substitution ──
$prompt = \App\AI\PromptLoader::load('trade_coach', [
    'market_label' => 'EUR/USD', 'decision' => 'BUY', 'confidence' => '72.5', 'risk_level' => 'medium',
    'mode' => 'professional', 'reasoning_block' => '• test', 'conflicts_block' => 'None.',
]);
checkTrue('PromptLoader::load(trade_coach) substitutes {{mode}} into the user prompt', str_contains($prompt['user'], 'professional'));
checkTrue('PromptLoader::load(trade_coach) system+user are non-empty', $prompt['system'] !== '' && $prompt['user'] !== '');

// ── 5. TradeCoachAiService tolerates AI on or off ───────────────────────────
$narrative = (new TradeCoachAiService())->narrate('EUR/USD', 'BUY', 72.5, 'medium', 'beginner', $explanation->reasoning, []);
checkTrue(
    'TradeCoachAiService::narrate() returns null or a well-shaped array',
    $narrative === null || (isset($narrative['provider'], $narrative['narrative']) && trim($narrative['narrative']) !== '')
);

$_ENV['USE_AI_SUMMARY'] = 'false';
$offNarrative = (new TradeCoachAiService())->narrate('EUR/USD', 'BUY', 72.5, 'medium', 'beginner', [], []);
checkTrue('TradeCoachAiService::narrate() returns null when USE_AI_SUMMARY is off', $offNarrative === null);
unset($_ENV['USE_AI_SUMMARY']);

// ── 6. Feature flag defaults ────────────────────────────────────────────────
// This installation's config/.env sets FEATURE_TRADE_COACH=true (the feature
// is actually enabled here) via Dotenv::createImmutable(), which populates
// BOTH $_ENV and $_SERVER — so temporarily clear both to test
// FeatureFlagService's coded default rather than this environment's chosen value.
$originalTradeCoachEnv    = $_ENV['FEATURE_TRADE_COACH']    ?? null;
$originalTradeCoachServer = $_SERVER['FEATURE_TRADE_COACH'] ?? null;
unset($_ENV['FEATURE_TRADE_COACH'], $_SERVER['FEATURE_TRADE_COACH']);
checkTrue('feature_enabled(TRADE_COACH) defaults to false', feature_enabled('TRADE_COACH') === false);
if ($originalTradeCoachEnv !== null)    { $_ENV['FEATURE_TRADE_COACH']    = $originalTradeCoachEnv; }
if ($originalTradeCoachServer !== null) { $_SERVER['FEATURE_TRADE_COACH'] = $originalTradeCoachServer; }
checkTrue('FeatureFlagService::knownFlags() still includes TRADE_COACH', in_array('TRADE_COACH', FeatureFlagService::knownFlags(), true));

echo "\n";
echo $failures === 0
    ? "ALL CHECKS PASSED\n"
    : "{$failures} CHECK(S) FAILED\n";

exit($failures === 0 ? 0 : 1);
