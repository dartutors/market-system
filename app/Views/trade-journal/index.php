<div class="page-header">
    <h2 class="page-title">Trade Journal &amp; Performance Analytics</h2>
    <p class="page-sub">Log trades manually or from a signal/AI decision, track outcomes and mistakes, and review pair/session/win-rate patterns. Educational only — no real trading.</p>
</div>

<?php if ($flashOk): ?>
<div class="flash success"><?= e($flashOk) ?></div>
<?php endif; ?>

<!-- ══ PERFORMANCE OVERVIEW ══════════════════════════════════════ -->
<div class="card">
    <div class="card__hd">
        <h3 class="card__title">Performance Overview</h3>
        <span class="card__sub"><?= (int) $stats['closed_count'] ?> closed / <?= (int) $stats['open_count'] ?> open</span>
    </div>
    <div class="perf-grid">
        <div class="perf-stat">
            <span class="perf-stat__num"><?= (int) $stats['total'] ?></span>
            <span class="perf-stat__lbl">Total logged</span>
        </div>
        <div class="perf-stat perf-stat--green">
            <span class="perf-stat__num"><?= $stats['closed_count'] > 0 ? $stats['win_rate'] . '%' : '—' ?></span>
            <span class="perf-stat__lbl">Win rate</span>
        </div>
        <div class="perf-stat <?= $stats['total_profit_loss'] >= 0 ? 'perf-stat--green' : 'perf-stat--red' ?>">
            <span class="perf-stat__num"><?= $stats['total_profit_loss'] ?></span>
            <span class="perf-stat__lbl">Total P/L</span>
        </div>
        <div class="perf-stat">
            <span class="perf-stat__num"><?= $stats['avg_profit_loss'] ?></span>
            <span class="perf-stat__lbl">Avg P/L per trade</span>
        </div>
        <div class="perf-stat perf-stat--green">
            <span class="perf-stat__num"><?= (int) $stats['wins'] ?></span>
            <span class="perf-stat__lbl">Wins</span>
        </div>
        <div class="perf-stat perf-stat--red">
            <span class="perf-stat__num"><?= (int) $stats['losses'] ?></span>
            <span class="perf-stat__lbl">Losses</span>
        </div>
    </div>
</div>

<!-- ══ PAIR / SESSION ANALYSIS ═══════════════════════════════════ -->
<div class="db-row db-row--bottom" style="margin-top:20px">
    <div class="card">
        <div class="card__hd"><h3 class="card__title">Pair Analysis</h3></div>
        <?php if (!empty($stats['by_market'])): ?>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Pair</th><th>Trades</th><th>Wins</th><th>Win rate</th><th>P/L</th></tr></thead>
                <tbody>
                <?php foreach ($stats['by_market'] as $m):
                    $wr = round(((int) $m['wins']) / max(1, (int) $m['total']) * 100, 1);
                ?>
                <tr>
                    <td><?= e((string) $m['market_label']) ?></td>
                    <td><?= (int) $m['total'] ?></td>
                    <td><?= (int) $m['wins'] ?></td>
                    <td><?= $wr ?>%</td>
                    <td><?= $m['total_profit_loss'] ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="empty">No closed trades with a pair recorded yet.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__hd"><h3 class="card__title">Session Analysis</h3></div>
        <?php if (!empty($stats['by_session'])): ?>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Session</th><th>Trades</th><th>Wins</th><th>Win rate</th><th>P/L</th></tr></thead>
                <tbody>
                <?php foreach ($stats['by_session'] as $s):
                    $wr = round(((int) $s['wins']) / max(1, (int) $s['total']) * 100, 1);
                ?>
                <tr>
                    <td><?= e(ucfirst(str_replace('_', ' ', (string) $s['session_name']))) ?></td>
                    <td><?= (int) $s['total'] ?></td>
                    <td><?= (int) $s['wins'] ?></td>
                    <td><?= $wr ?>%</td>
                    <td><?= $s['total_profit_loss'] ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="empty">No closed trades with a session recorded yet.</p>
        <?php endif; ?>
    </div>
</div>

<!-- ══ WIN-RATE TREND / MISTAKE TRACKING ═════════════════════════ -->
<div class="db-row db-row--bottom" style="margin-top:20px">
    <div class="card">
        <div class="card__hd"><h3 class="card__title">Win-Rate Trend (last 30 days)</h3></div>
        <?php if (!empty($stats['daily_trend'])): ?>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Day</th><th>Trades</th><th>Wins</th><th>Losses</th><th>Win rate</th></tr></thead>
                <tbody>
                <?php foreach ($stats['daily_trend'] as $d):
                    $wr = round(((int) $d['wins']) / max(1, (int) $d['total']) * 100, 1);
                ?>
                <tr>
                    <td><?= e((string) $d['day']) ?></td>
                    <td><?= (int) $d['total'] ?></td>
                    <td><?= (int) $d['wins'] ?></td>
                    <td><?= (int) $d['losses'] ?></td>
                    <td><?= $wr ?>%</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="empty">No closed trades in the last 30 days yet.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__hd"><h3 class="card__title">Mistake Tracking</h3></div>
        <?php if (!empty($stats['mistake_tag_counts'])): ?>
        <ul class="bt-ai-reasons">
            <?php foreach ($stats['mistake_tag_counts'] as $tag => $count): ?>
            <li><?= e(\App\TradeJournal\MistakeTagCatalog::label((string) $tag)) ?> — logged <?= (int) $count ?> time(s)</li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="empty">No mistakes tagged yet.</p>
        <?php endif; ?>
    </div>
</div>

<!-- ══ AI COACHING REPORT ════════════════════════════════════════ -->
<div class="card bt-ai-card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">AI Coaching Report</h3>
    </div>
    <form method="post" action="<?= e(url('trade-journal/coaching-report')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button type="submit" class="btn btn--scan">Generate Coaching Report</button>
    </form>

    <?php if ($coachingReport !== null): ?>
    <div style="margin-top:14px">
        <p class="bt-ai-summary"><?= e($coachingReport['narrative']) ?></p>
        <?php if (!empty($coachingReport['focus_area'])): ?>
        <p><strong>Focus on:</strong> <?= e($coachingReport['focus_area']) ?></p>
        <?php endif; ?>
        <?php if (!empty($coachingReport['risk_note'])): ?>
        <p class="bt-note"><?= e($coachingReport['risk_note']) ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ══ NEW ENTRY FORM ════════════════════════════════════════════ -->
<div class="card card--accent" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Log a Trade</h3>
        <span class="card__sub"><a href="<?= e(url('trade-journal/archive')) ?>">Browse AI Recommendation Archive →</a></span>
    </div>
    <form method="post" action="<?= e(url('trade-journal/store')) ?>" class="bt-form" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <?php if ($prefill !== null): ?>
        <input type="hidden" name="signal_id"   value="<?= (int) ($prefill['signal_id']   ?? 0) ?>">
        <input type="hidden" name="decision_id" value="<?= (int) ($prefill['decision_id'] ?? 0) ?>">
        <p class="stat-block__lbl">Pre-filled from <?= $prefill['signal_id'] !== null ? 'signal #' . (int) $prefill['signal_id'] : 'AI decision #' . (int) $prefill['decision_id'] ?>.</p>
        <?php endif; ?>

        <div class="bt-form__fields">
            <div class="form-group">
                <label for="tj-market">Market pair</label>
                <select id="tj-market" name="market_label" class="form-select">
                    <?php foreach ($markets as $m): ?>
                    <option value="<?= e($m['label']) ?>" <?= ($prefill['market_label'] ?? '') === $m['label'] ? 'selected' : '' ?>><?= e($m['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="tj-direction">Direction</label>
                <select id="tj-direction" name="direction" class="form-select">
                    <option value="BUY"  <?= ($prefill['direction'] ?? '') === 'BUY'  ? 'selected' : '' ?>>Buy</option>
                    <option value="SELL" <?= ($prefill['direction'] ?? '') === 'SELL' ? 'selected' : '' ?>>Sell</option>
                </select>
            </div>

            <div class="form-group">
                <label for="tj-emotion">Emotion</label>
                <select id="tj-emotion" name="emotion" class="form-select">
                    <option value="">— not logged —</option>
                    <?php foreach ($emotions as $emotionKey => $emotionLabel): ?>
                    <option value="<?= e($emotionKey) ?>"><?= e($emotionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="tj-pattern">Setup pattern</label>
                <select id="tj-pattern" name="setup_pattern" class="form-select">
                    <option value="">— unclassified —</option>
                    <?php foreach (\App\Learning\SetupPatternCatalog::all() as $patternKey => $patternLabel): ?>
                    <option value="<?= e($patternKey) ?>"><?= e($patternLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="tj-entry">Entry price</label>
                <input type="number" id="tj-entry" name="entry_price" step="0.00001" class="form-input">
            </div>

            <div class="form-group">
                <label for="tj-screenshot">Screenshot (optional)</label>
                <input type="file" id="tj-screenshot" name="screenshot" accept="image/png,image/jpeg,image/webp">
            </div>

            <div class="form-group" style="flex-basis:100%">
                <label for="tj-notes">Notes</label>
                <textarea id="tj-notes" name="notes" rows="2" class="form-input" placeholder="Why did you take this trade?"></textarea>
            </div>

            <button type="submit" class="btn btn--scan">Log Trade</button>
        </div>
    </form>
</div>

<!-- ══ RECENT ENTRIES ════════════════════════════════════════════ -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Recent Entries</h3>
        <?php if ($entries): ?><span class="card__count"><?= count($entries) ?> records</span><?php endif; ?>
    </div>

    <?php if ($entries): ?>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead>
                <tr><th>Logged</th><th>Pair</th><th>Dir</th><th>Emotion</th><th>Pattern</th><th>Session</th><th>Source</th><th>Entry</th><th>Outcome</th><th>P/L</th><th>Grade</th><th>Screenshot</th><th>Review</th></tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $entry):
                $outcome = (string) ($entry['outcome'] ?? '');
                $isOpen  = empty($entry['closed_at']);
                $rowCls  = match ($outcome) { 'win' => 'tr--buy', 'loss' => 'tr--sell', default => '' };
                $mistakeTags = json_decode((string) ($entry['mistake_tags_json'] ?? ''), true) ?: [];
            ?>
            <tr class="<?= e($rowCls) ?>">
                <td class="td--mono td--muted td--nowrap"><?= e(fmt_datetime((string) $entry['created_at'])) ?></td>
                <td><span class="badge badge--market"><?= e((string) ($entry['market_label'] ?? '—')) ?></span></td>
                <td class="td--action"><?= e((string) ($entry['direction'] ?? '—')) ?></td>
                <td class="td--muted"><?= e(!empty($entry['emotion']) ? \App\TradeJournal\EmotionCatalog::label((string) $entry['emotion']) : '—') ?></td>
                <td class="td--muted"><?= e(!empty($entry['setup_pattern']) ? \App\Learning\SetupPatternCatalog::label((string) $entry['setup_pattern']) : '—') ?></td>
                <td class="td--muted"><?= e(ucfirst(str_replace('_', ' ', (string) ($entry['session_name'] ?? '—')))) ?></td>
                <td class="td--muted"><?= e((string) $entry['source']) ?></td>
                <td class="td--mono"><?= e((string) ($entry['entry_price'] ?? '—')) ?></td>
                <td>
                    <?php if (!$isOpen): ?>
                        <?php $ocBadge = match ($outcome) { 'win' => 'badge--outcome-win', 'loss' => 'badge--outcome-loss', 'breakeven' => 'badge--outcome-be', default => 'badge--outcome-skip' }; ?>
                        <span class="badge <?= e($ocBadge) ?>"><?= e(strtoupper($outcome)) ?></span>
                    <?php else: ?>
                        <span class="td--muted">Open</span>
                    <?php endif; ?>
                </td>
                <td class="td--mono"><?= e((string) ($entry['profit_loss'] ?? '—')) ?></td>
                <td>
                    <?php if (!empty($entry['report_card_grade'])): ?>
                        <span class="badge badge--market" title="AI Coach report card"><?= e((string) $entry['report_card_grade']) ?></span>
                    <?php else: ?>
                        <span class="td--muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($entry['image_id'])): ?>
                    <a href="<?= e(url('vision/image')) ?>?id=<?= (int) $entry['image_id'] ?>" target="_blank" rel="noopener">View</a>
                    <?php else: ?>
                    <span class="td--muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <details class="journal-detail">
                        <summary class="journal-toggle"><?= $isOpen ? '✏️ Close / Edit' : '✏️ Edit' ?></summary>
                        <form method="post" action="<?= e(url('trade-journal/update')) ?>" class="journal-form">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int) $entry['id'] ?>">

                            <textarea name="notes" rows="2" class="journal-textarea" placeholder="Notes"><?= e((string) ($entry['notes'] ?? '')) ?></textarea>
                            <textarea name="lessons_learned" rows="2" class="journal-textarea" placeholder="Lessons learned"><?= e((string) ($entry['lessons_learned'] ?? '')) ?></textarea>

                            <?php if (!empty($entry['ai_feedback'])): ?>
                            <p class="card__sub" style="margin:6px 0"><strong>AI feedback:</strong> <?= e((string) $entry['ai_feedback']) ?></p>
                            <?php endif; ?>

                            <?php if (!empty($entry['report_card_json'])):
                                $rc = json_decode((string) $entry['report_card_json'], true) ?: [];
                            ?>
                            <p class="card__sub" style="margin:10px 0 4px"><strong>Trade Report Card — Overall <?= e((string) $entry['report_card_grade']) ?></strong></p>
                            <div class="tbl-wrap">
                            <table class="tbl" style="margin-bottom:6px">
                                <tbody>
                                <?php foreach ($rc as $dimension => $d): ?>
                                    <tr>
                                        <td class="td--muted" style="width:140px"><?= e($dimension) ?></td>
                                        <td class="td--mono" style="width:50px"><?= $d['score'] !== null ? (int) $d['score'] . '%' : 'N/A' ?></td>
                                        <td class="u-muted" style="font-size:0.78rem"><?= e((string) $d['reason']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                            <?php endif; ?>

                            <label class="stat-block__lbl" style="display:block;margin-top:6px">Emotion</label>
                            <select name="emotion" class="form-select">
                                <option value="">— leave as-is —</option>
                                <?php foreach (\App\TradeJournal\EmotionCatalog::all() as $emotionKey => $emotionLabel): ?>
                                <option value="<?= e($emotionKey) ?>" <?= ($entry['emotion'] ?? '') === $emotionKey ? 'selected' : '' ?>><?= e($emotionLabel) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <label class="stat-block__lbl" style="display:block;margin-top:6px">Setup pattern</label>
                            <select name="setup_pattern" class="form-select">
                                <option value="">— leave as-is —</option>
                                <?php foreach (\App\Learning\SetupPatternCatalog::all() as $patternKey => $patternLabel): ?>
                                <option value="<?= e($patternKey) ?>" <?= ($entry['setup_pattern'] ?? '') === $patternKey ? 'selected' : '' ?>><?= e($patternLabel) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <div class="mistake-tags">
                                <?php foreach (\App\TradeJournal\MistakeTagCatalog::all() as $tagKey => $tagLabel): ?>
                                <label class="mistake-tag-check">
                                    <input type="checkbox" name="mistake_tags[]" value="<?= e($tagKey) ?>" <?= in_array($tagKey, $mistakeTags, true) ? 'checked' : '' ?>>
                                    <?= e($tagLabel) ?>
                                </label>
                                <?php endforeach; ?>
                            </div>

                            <?php if ($isOpen): ?>
                            <div class="bt-form__fields" style="margin-top:8px">
                                <div class="form-group">
                                    <label>Exit price</label>
                                    <input type="number" name="exit_price" step="0.00001" class="form-input">
                                </div>
                                <div class="form-group">
                                    <label>Profit/Loss ($)</label>
                                    <input type="number" name="profit_loss" step="0.01" class="form-input">
                                </div>
                                <div class="form-group">
                                    <label>Pips</label>
                                    <input type="number" name="pips" step="0.1" class="form-input">
                                </div>
                                <div class="form-group">
                                    <label>Outcome (closes the trade)</label>
                                    <select name="outcome" class="form-select">
                                        <option value="">— leave open, just save notes —</option>
                                        <option value="win">Win</option>
                                        <option value="loss">Loss</option>
                                        <option value="breakeven">Breakeven</option>
                                        <option value="skipped">Skipped</option>
                                    </select>
                                </div>
                            </div>
                            <?php endif; ?>

                            <button type="submit" class="btn-journal-save">Save</button>
                        </form>
                    </details>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p class="empty">No trades logged yet. Use the form above to log your first one.</p>
    <?php endif; ?>
</div>

<style>
.mistake-tags { display: flex; flex-wrap: wrap; gap: 6px 14px; margin: 8px 0; }
.mistake-tag-check { display: flex; align-items: center; gap: 5px; font-size: 0.85rem; color: var(--text-muted, #8892a0); }
</style>
