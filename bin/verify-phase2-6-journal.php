<?php
declare(strict_types=1);

/**
 * Deterministic regression checks for Phase 2.6 — Trade Journal &
 * Performance Analytics (App\Models\TradeJournal, App\TradeJournal\*).
 *
 * Unlike the read-only checks in bin/verify-phase2-5-risk.php, this script
 * INSERTS a handful of real trade_journal rows to exercise create/update/
 * close/stats/findAll end-to-end, then soft-deletes every row it created
 * (via TradeJournal::softDelete()) so the real table is left exactly as it
 * was found. Run with:
 *
 *   php bin/verify-phase2-6-journal.php
 *
 * Exits 0 if all checks pass, 1 otherwise.
 */

require_once __DIR__ . '/../bootstrap/helpers.php';
require_once __DIR__ . '/../bootstrap/app.php';

use App\Models\AiDecision;
use App\Models\TradeJournal;
use App\Services\FeatureFlagService;
use App\TradeJournal\JournalCoachingService;
use App\TradeJournal\MistakeTagCatalog;

$failures = 0;
$createdIds = [];

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

// ── 1. MistakeTagCatalog ─────────────────────────────────────────────────────
checkTrue('MistakeTagCatalog::isValid() accepts a known tag', MistakeTagCatalog::isValid('fomo_entry'));
checkTrue('MistakeTagCatalog::isValid() rejects an unknown tag', !MistakeTagCatalog::isValid('bogus_tag_xyz'));
checkTrue('MistakeTagCatalog::label() returns a human label for a known tag', MistakeTagCatalog::label('fomo_entry') !== 'fomo_entry');
checkTrue('MistakeTagCatalog::label() falls back to the raw tag for an unknown one', MistakeTagCatalog::label('bogus_tag_xyz') === 'bogus_tag_xyz');
checkTrue(
    'MistakeTagCatalog::sanitize() drops invalid tags and dedupes',
    MistakeTagCatalog::sanitize(['fomo_entry', 'bogus_tag_xyz', 'fomo_entry', 'no_stop_loss']) === ['fomo_entry', 'no_stop_loss']
);

// ── 2. TradeJournal::create() / findById() ──────────────────────────────────
$id1 = TradeJournal::create([
    'market_label'      => 'EUR/USD',
    'direction'         => 'BUY',
    'source'            => 'manual',
    'session_name'      => 'london',
    'entry_price'       => 1.2000,
    'notes'             => 'Verify script test entry #1',
    'mistake_tags_json' => null,
]);
$createdIds[] = $id1;

$row1 = TradeJournal::findById($id1);
checkTrue('TradeJournal::create() + findById() round-trips market_label/direction', $row1 !== null && $row1['market_label'] === 'EUR/USD' && $row1['direction'] === 'BUY');
checkTrue('TradeJournal::create() defaults source to manual when given', $row1['source'] === 'manual');
checkTrue('TradeJournal::create() leaves the entry open (closed_at NULL)', $row1['closed_at'] === null);

// ── 3. TradeJournal::update() (open-entry edits, no outcome) ────────────────
TradeJournal::update($id1, [
    'notes'             => 'Updated note',
    'lessons_learned'   => null,
    'mistake_tags_json' => json_encode(['fomo_entry'], JSON_UNESCAPED_UNICODE),
]);
$row1b = TradeJournal::findById($id1);
checkTrue('TradeJournal::update() persists new notes', $row1b['notes'] === 'Updated note');
checkTrue('TradeJournal::update() persists mistake tags', json_decode((string) $row1b['mistake_tags_json'], true) === ['fomo_entry']);
checkTrue('TradeJournal::update() does not close the trade', $row1b['closed_at'] === null);

// ── 4. TradeJournal::close() ─────────────────────────────────────────────────
TradeJournal::close($id1, [
    'exit_price'  => 1.2050,
    'profit_loss' => 50.0,
    'pips'        => 50.0,
    'outcome'     => 'win',
]);
$row1c = TradeJournal::findById($id1);
checkTrue('TradeJournal::close() sets outcome', $row1c['outcome'] === 'win');
checkTrue('TradeJournal::close() sets closed_at', $row1c['closed_at'] !== null);
checkTrue('TradeJournal::close() preserves mistake tags via COALESCE when none passed', json_decode((string) $row1c['mistake_tags_json'], true) === ['fomo_entry']);
checkTrue('TradeJournal::close() sets profit_loss', abs((float) $row1c['profit_loss'] - 50.0) < 0.001);

$rejected = TradeJournal::findById($id1);
TradeJournal::close($id1, ['outcome' => 'not_a_real_outcome']);
$rowAfterBadOutcome = TradeJournal::findById($id1);
checkTrue('TradeJournal::close() rejects an invalid outcome value (no-op)', $rowAfterBadOutcome['outcome'] === $rejected['outcome']);

// ── 5. A second, losing trade for stats/aggregation checks ──────────────────
$id2 = TradeJournal::create([
    'market_label'      => 'EUR/USD',
    'direction'         => 'SELL',
    'source'            => 'manual',
    'session_name'      => 'new_york',
    'entry_price'       => 1.2100,
    'mistake_tags_json' => json_encode(['fomo_entry', 'no_stop_loss'], JSON_UNESCAPED_UNICODE),
]);
$createdIds[] = $id2;
TradeJournal::close($id2, ['exit_price' => 1.2150, 'profit_loss' => -50.0, 'pips' => -50.0, 'outcome' => 'loss']);

// ── 6. TradeJournal::stats() reflects both trades correctly ─────────────────
$stats = TradeJournal::stats();
checkTrue('TradeJournal::stats() total includes both test trades', $stats['total'] >= 2);
checkTrue('TradeJournal::stats() wins count includes the win', $stats['wins'] >= 1);
checkTrue('TradeJournal::stats() losses count includes the loss', $stats['losses'] >= 1);

$eurUsdRow = null;
foreach ($stats['by_market'] as $m) {
    if ($m['market_label'] === 'EUR/USD') { $eurUsdRow = $m; break; }
}
checkTrue('TradeJournal::stats() by_market includes EUR/USD with both closed trades', $eurUsdRow !== null && (int) $eurUsdRow['total'] >= 2, (string) ($eurUsdRow['total'] ?? 'missing'));

$londonRow = null;
$newYorkRow = null;
foreach ($stats['by_session'] as $s) {
    if ($s['session_name'] === 'london')    { $londonRow = $s; }
    if ($s['session_name'] === 'new_york') { $newYorkRow = $s; }
}
checkTrue('TradeJournal::stats() by_session includes the london session', $londonRow !== null);
checkTrue('TradeJournal::stats() by_session includes the new_york session', $newYorkRow !== null);

checkTrue('TradeJournal::stats() mistake_tag_counts includes fomo_entry >= 2', ($stats['mistake_tag_counts']['fomo_entry'] ?? 0) >= 2, (string) ($stats['mistake_tag_counts']['fomo_entry'] ?? 0));
checkTrue('TradeJournal::stats() mistake_tag_counts includes no_stop_loss >= 1', ($stats['mistake_tag_counts']['no_stop_loss'] ?? 0) >= 1);

// ── 7. TradeJournal::findAll() filters ───────────────────────────────────────
$eurUsdEntries = TradeJournal::findAll(['market_label' => 'EUR/USD'], 100);
$foundBoth = 0;
foreach ($eurUsdEntries as $e) {
    if (\in_array((int) $e['id'], [$id1, $id2], true)) { $foundBoth++; }
}
checkTrue('TradeJournal::findAll() market_label filter finds both test entries', $foundBoth === 2, (string) $foundBoth);

$winOnly = TradeJournal::findAll(['outcome' => 'win'], 100);
checkTrue('TradeJournal::findAll() outcome filter excludes the loss entry', !\in_array($id2, array_column($winOnly, 'id'), true));

// ── 8. AiDecision::findById() ────────────────────────────────────────────────
checkTrue('AiDecision::findById() returns null for a non-existent id', AiDecision::findById(999999999) === null);

// ── 9. JournalCoachingService ────────────────────────────────────────────────
$report = (new JournalCoachingService())->report();
checkTrue('JournalCoachingService::report() returns a non-empty narrative', trim((string) $report['narrative']) !== '');
checkTrue(
    'JournalCoachingService::report() provider is rule_based, claude, or openai',
    \in_array($report['provider'], ['rule_based', 'claude', 'openai'], true),
    $report['provider']
);

$_ENV['USE_AI_SUMMARY'] = 'false';
$ruleBasedReport = (new JournalCoachingService())->report();
checkTrue('JournalCoachingService::report() falls back to rule_based when USE_AI_SUMMARY is off', $ruleBasedReport['provider'] === 'rule_based');
unset($_ENV['USE_AI_SUMMARY']);

// ── 10. Feature flag ─────────────────────────────────────────────────────────
checkTrue('FeatureFlagService::knownFlags() includes TRADE_JOURNAL', in_array('TRADE_JOURNAL', FeatureFlagService::knownFlags(), true));
checkTrue('feature_enabled(TRADE_JOURNAL) is boolean', is_bool(feature_enabled('TRADE_JOURNAL')));

// ── Cleanup: soft-delete every row this script created ───────────────────────
foreach ($createdIds as $id) {
    TradeJournal::softDelete($id);
}
$stillVisible = 0;
foreach ($createdIds as $id) {
    if (TradeJournal::findById($id) !== null) { $stillVisible++; }
}
checkTrue('Cleanup: all test entries are soft-deleted (invisible to findById)', $stillVisible === 0, (string) $stillVisible);

echo "\n";
echo $failures === 0
    ? "ALL CHECKS PASSED\n"
    : "{$failures} CHECK(S) FAILED\n";

exit($failures === 0 ? 0 : 1);
