<?php
    $csrf    = App\Core\Csrf::token();
    $markets = configured_markets();
?>

<!-- SECTION 0: DECISION INTELLIGENCE — PRIMARY DECISION CARD -->
<?php if ($consensus !== null):
    $mq = $consensus['market_quality'];
    $tt = $consensus['trade_today'];
    $supportingCount = 0; $conflictingCount = 0;
    if ($consensus['best'] !== null) {
        foreach ($consensus['best']['sources'] as $s) {
            if (!$s['is_directional'] || !$s['available']) continue;
            if ($s['direction'] === $consensus['best']['decision']) $supportingCount++;
            elseif ($s['direction'] !== 'NEUTRAL') $conflictingCount++;
        }
    }
?>
<div class="card card--accent" id="consensus-summary">
    <div class="card__hd">
        <h3 class="card__title">Decision Intelligence — Primary Recommendation</h3>
        <span class="card__sub"><?= $consensus['stale'] ? 'Last refreshed over 35 min ago — ' : '' ?><a href="#" onclick="document.getElementById('consensus-refresh-form').requestSubmit(); return false;">Refresh now</a></span>
    </div>
    <div class="bt-stats-grid" style="grid-template-columns:repeat(3,1fr)">
        <div class="stat-block <?= $mq !== null && $mq['score'] >= 60 ? 'stat-block--green' : ($mq !== null && $mq['score'] < 40 ? 'stat-block--red' : '') ?>">
            <span class="stat-block__val"><?= $mq !== null ? round($mq['score']) : '—' ?><?= $mq !== null ? '/100' : '' ?></span>
            <span class="stat-block__lbl">Market Quality<?= $mq !== null ? ' — ' . e($mq['market_label']) : '' ?></span>
        </div>
        <div class="stat-block <?= $tt['recommended'] ? 'stat-block--green' : '' ?>">
            <span class="stat-block__val"><?= $tt['recommended'] ? '✅ YES' : '⏸ NOT YET' ?></span>
            <span class="stat-block__lbl">Trade Today<?= $tt['recommended'] ? ' — ' . e((string) $tt['market_label']) : '' ?></span>
        </div>
        <div class="stat-block">
            <span class="stat-block__val"><?= $tt['recommended'] ? (int) $tt['confidence'] . '%' : '—' ?></span>
            <span class="stat-block__lbl">Confidence · Risk: <?= e((string) $tt['risk']) ?></span>
        </div>
    </div>
    <?php if ($mq !== null): ?>
    <p class="card__sub" style="margin:10px 0 4px"><?= str_repeat('★', $mq['stars']) . str_repeat('☆', 5 - $mq['stars']) ?> <?= e($mq['label']) ?></p>
    <?php endif; ?>

    <?php if (!empty($tt['reliability']) && !$tt['reliability']['insufficient_data']): ?>
    <div class="dc-field" style="margin-top:8px">
        <span class="dc-field__lbl">Reliability <span class="u-muted">(separate from Confidence above — based on real historical outcomes, not this signal's own strength)</span></span>
        <span class="dc-field__val" style="font-size:1.3rem"><?= e((string) $tt['reliability']['grade']) ?></span>
    </div>
    <ul class="dc-wait-reasons">
        <?php foreach (array_slice($tt['reliability']['facts'], 0, 5) as $fact): ?>
        <li>✓ <?= e($fact) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php elseif (!empty($tt['reliability'])): ?>
    <p class="card__sub" style="margin-top:8px"><strong>Reliability:</strong> <?= e($tt['reliability']['facts'][0] ?? 'Insufficient historical data.') ?></p>
    <?php endif; ?>

    <?php if ($consensus['best'] !== null): ?>
    <div class="bt-stats-grid" style="grid-template-columns:repeat(3,1fr);margin-top:10px">
        <div class="stat-block"><span class="stat-block__val"><?= round((float) $consensus['best']['agreement_percentage']) ?>%</span><span class="stat-block__lbl">Agreement Score</span></div>
        <div class="stat-block stat-block--green"><span class="stat-block__val"><?= $supportingCount ?></span><span class="stat-block__lbl">Supporting Systems</span></div>
        <div class="stat-block <?= $conflictingCount > 0 ? 'stat-block--red' : '' ?>"><span class="stat-block__val"><?= $conflictingCount ?></span><span class="stat-block__lbl">Conflicting Systems</span></div>
    </div>
    <?php endif; ?>

    <p class="dc-reason" style="margin-top:10px"><strong>Why — </strong><?= e(implode('. ', array_slice($tt['reasons'], 0, 3))) ?></p>

    <?php if (!empty($tt['conflicts'])): ?>
    <p class="card__sub" style="margin-top:8px"><strong>Conflicts detected:</strong></p>
    <ul class="dc-wait-reasons">
        <?php foreach (array_slice($tt['conflicts'], 0, 4) as $c): ?>
        <li><span class="badge <?= $c['severity'] === 'high' ? 'badge--bearish' : 'badge--neutral' ?>"><?= e(strtoupper($c['severity'])) ?></span> <?= e($c['description']) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if (!empty($tt['invalidation'])): ?>
    <p class="card__sub" style="margin-top:8px"><strong><?= $tt['recommended'] ? 'This recommendation is invalidated if:' : 'Waiting for:' ?></strong></p>
    <ul class="dc-wait-reasons">
        <?php foreach (array_slice($tt['invalidation'], 0, 4) as $i): ?>
        <li><?= e($i) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if (!empty($tt['next_review_at'])): ?>
    <p class="card__sub" style="margin-top:8px"><strong>Next review:</strong> <?= e(fmt_datetime((string) $tt['next_review_at'])) ?></p>
    <?php endif; ?>

    <p class="card__sub" style="margin-top:10px">Fuses Claude, OpenAI, AI Vision, Technical Analysis, Smart Money Concepts, Multi-Timeframe Trend Alignment, News, Economic Calendar, Currency Strength, Session, Volatility, Liquidity, Strategy Lab, Historical Accuracy, and Trade Journal Learning into one evidence-based decision — see the full breakdown below. Educational decision support only; this system never executes trades.</p>
    <form id="consensus-refresh-form" method="post" action="<?= e(url('admin/refresh-consensus')) ?>" style="display:none">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    </form>
</div>
<?php endif; ?>

<?php if ($visionEnabled): ?>
<!-- ══ SECTION 1: AI VISION — PRIMARY ENTRY POINT ════════════════ -->
<div class="card card--accent" id="ai-vision-hero">
    <div class="card__hd">
        <h3 class="card__title">AI Vision — Chart Analysis</h3>
        <a href="<?= e(url('vision')) ?>" class="card__count">Full multi-timeframe workspace →</a>
    </div>
    <div class="dvh-grid">
        <div>
            <form method="post" action="<?= e(url('vision/analyze')) ?>" enctype="multipart/form-data" id="dvh-form">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <label class="dvh-drop" id="dvh-drop" tabindex="0">
                    <img class="dvh-drop__preview" id="dvh-preview" alt="">
                    <svg class="dvh-drop__icon dvh-drop__placeholder" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <span class="dvh-drop__title dvh-drop__placeholder">Upload TradingView / MT4 / MT5 Screenshot</span>
                    <span class="dvh-drop__sub dvh-drop__placeholder">Drag &amp; drop, click to browse, or paste with Ctrl+V</span>
                    <span class="dvh-drop__methods dvh-drop__placeholder">
                        <span class="dvh-method-pill">Drag &amp; drop</span>
                        <span class="dvh-method-pill">Ctrl+V paste</span>
                        <span class="dvh-method-pill">Browse files</span>
                    </span>
                    <span class="dvh-filename" id="dvh-filename"></span>
                    <input type="file" name="chart_daily" id="dvh-input" accept="image/png,image/jpeg,image/webp" style="display:none">
                </label>
                <div class="dvh-actions">
                    <input type="text" name="market_hint" class="form-select" placeholder="Pair hint (optional, e.g. EUR/USD)" style="max-width:200px">
                    <button type="submit" class="btn btn--scan" id="dvh-submit" disabled>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><polyline points="21 21 16.65 16.65"/></svg>
                        Analyze Now
                    </button>
                </div>
                <p class="bt-note" style="margin-top:8px">Quick single-chart read (Daily timeframe). For a full top-down, multi-timeframe analysis with risk sizing, open the <a href="<?= e(url('vision')) ?>">AI Vision workspace</a>.</p>
            </form>
        </div>
        <div class="dvh-side">
            <div>
                <p class="dvh-section__lbl">Recent screenshots</p>
                <?php if ($recentCharts): ?>
                <div class="dvh-thumbs">
                    <?php foreach ($recentCharts as $c): ?>
                    <a href="<?= e(url('vision/image')) ?>?id=<?= (int) $c['id'] ?>" target="_blank" rel="noopener" title="<?= e((string) $c['market_label']) ?> · <?= e((string) $c['timeframe']) ?>">
                        <img class="dvh-thumb" src="<?= e(url('vision/image')) ?>?id=<?= (int) $c['id'] ?>" alt="<?= e((string) $c['market_label']) ?>" loading="lazy">
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="empty" style="padding:16px 0">No screenshots uploaded yet.</p>
                <?php endif; ?>
            </div>
            <div>
                <p class="dvh-section__lbl">Recent recommendations</p>
                <?php if ($recentVisionSessions): ?>
                <div class="dvh-recs">
                    <?php foreach ($recentVisionSessions as $vs):
                        $vsDec    = strtoupper((string) ($vs['decision'] ?? 'NO_TRADE'));
                        $vsDecCls = 'dvh-rec__decision--' . strtolower($vsDec);
                    ?>
                    <div class="dvh-rec">
                        <span class="dvh-rec__decision <?= e($vsDecCls) ?>"><?= e($vsDec === 'NO_TRADE' ? 'NO TRADE' : $vsDec) ?></span>
                        <span class="dvh-rec__pair"><?= e((string) $vs['market_label']) ?></span>
                        <span class="dvh-rec__conf"><?= (int) round((float) $vs['confidence']) ?>%</span>
                        <span class="dvh-rec__time"><?= e(fmt_datetime((string) $vs['created_at'])) ?> · <a href="<?= e(url('vision')) ?>" class="dvh-rec__link">Re-analyze →</a></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="empty" style="padding:16px 0">No analyses yet — upload a chart to get started.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ SECTION 2: MARKET DECISION CENTER ═════════════════════════ -->
<?php if ($bestOpportunity):
    $bo       = $bestOpportunity;
    $boAction = strtoupper((string) $bo['action']);
    $boCls    = strtolower($boAction) === 'buy' ? 'dc--buy' : 'dc--sell';
    $boReasons = array_filter(explode("\n", (string) ($bo['reasons'] ?? '')), static fn(string $l): bool => trim($l) !== '');
?>
<div class="card card--decision <?= e($boCls) ?>" id="decision-center">
    <div class="dc-hd">
        <div>
            <span class="dc-pair"><?= e((string) $bo['market_label']) ?> · Current Best Opportunity</span>
            <div class="dc-action dc-action--<?= e(strtolower($boAction)) ?>"><?= $boAction === 'BUY' ? '↑ BUY' : '↓ SELL' ?></div>
        </div>
        <div class="insight-conf-wrap">
            <span class="insight-conf-label">Confidence</span>
            <div class="insight-conf-bar"><div class="insight-conf-fill" style="width:<?= max(4, (int) $bo['confidence']) ?>%"></div></div>
            <span class="insight-conf-pct"><?= (int) $bo['confidence'] ?>%</span>
        </div>
    </div>
    <div class="dc-grid">
        <div class="dc-field"><span class="dc-field__lbl">Entry window</span><span class="dc-field__val"><?= e((string) ($bo['entry_window_start'] ?? '—')) ?><?= !empty($bo['entry_window_end']) ? '–' . e((string) $bo['entry_window_end']) : '' ?></span></div>
        <div class="dc-field"><span class="dc-field__lbl">Stop loss</span><span class="dc-field__val"><?= e((string) ($bo['stop_loss_hint'] ?? '—')) ?></span></div>
        <div class="dc-field"><span class="dc-field__lbl">Take profit</span><span class="dc-field__val"><?= e((string) ($bo['take_profit_hint'] ?? '—')) ?></span></div>
        <div class="dc-field"><span class="dc-field__lbl">Risk</span><span class="dc-field__val"><?= e((string) ($bo['risk_warning'] ?? '—')) ?></span></div>
        <div class="dc-field"><span class="dc-field__lbl">Timeframe</span><span class="dc-field__val"><?= e((string) ($bo['timeframe'] ?? '—')) ?></span></div>
        <div class="dc-field"><span class="dc-field__lbl">Session</span><span class="dc-field__val"><?= e(ucfirst(str_replace('_', ' ', (string) ($bo['session_name'] ?? '—')))) ?></span></div>
    </div>
    <?php if ($boReasons): ?>
    <p class="dc-reason"><strong>Reason — </strong><?= e(implode('. ', array_map('trim', $boReasons))) ?></p>
    <?php endif; ?>
</div>
<?php else: $wr = $waitReason; ?>
<div class="card card--decision dc--wait" id="decision-center">
    <div class="dc-hd">
        <div>
            <span class="dc-pair">Market Decision Center</span>
            <div class="dc-action dc-action--wait">⏸ WAIT</div>
        </div>
        <?php if ($wr): ?>
        <div class="insight-conf-wrap">
            <span class="insight-conf-label">Closest pair</span>
            <span class="badge badge--market"><?= e((string) ($wr['pair'] ?? '')) ?></span>
        </div>
        <?php endif; ?>
    </div>
    <p class="dc-reason">Market conditions are currently not strong enough for an actionable trade.</p>
    <?php if ($wr): ?>
    <ul class="dc-wait-reasons">
        <li><?= e((string) ($wr['entry_reason'] ?? $wr['short_reason'] ?? 'No qualifying setup right now.')) ?></li>
    </ul>
    <?php else: ?>
    <p class="empty" style="padding-top:12px">Run a scan to evaluate current conditions.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- SECTION 2B: DECISION INTELLIGENCE — PER-SOURCE EVIDENCE BREAKDOWN -->
<?php if ($consensus !== null): $cb = $consensus['best']; ?>
<div class="card" id="consensus-breakdown">
    <div class="card__hd">
        <h3 class="card__title">Decision Intelligence — Evidence Breakdown</h3>
        <span class="card__sub"><?= $cb !== null ? e((string) $cb['market_label']) . ' · ' . round((float) $cb['agreement_percentage']) . '% agreement' : 'No actionable consensus right now' ?></span>
    </div>

    <?php if ($cb !== null): $tp = $cb['trade_plan']; ?>
    <div class="dc-grid" style="margin-bottom:14px">
        <div class="dc-field"><span class="dc-field__lbl">Final Decision</span><span class="dc-field__val"><span class="badge badge--<?= $cb['decision'] === 'BUY' ? 'bullish' : 'bearish' ?>"><?= e($cb['decision']) ?></span></span></div>
        <div class="dc-field"><span class="dc-field__lbl">Consensus</span><span class="dc-field__val"><?= (int) $cb['consensus_score'] ?>%</span></div>
        <div class="dc-field"><span class="dc-field__lbl">Agreement</span><span class="dc-field__val"><?= round((float) $cb['agreement_percentage']) ?>%</span></div>
        <div class="dc-field"><span class="dc-field__lbl">Risk</span><span class="dc-field__val"><?= e((string) $cb['risk_level']) ?></span></div>
    </div>
    <?php endif; ?>

    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Source</th><th>Reading</th><th>Confidence</th><th>Reasoning</th></tr></thead>
            <tbody>
            <?php
                $rowsSource = $cb !== null ? $cb['sources'] : ($consensus['all'][array_key_first($consensus['all'])]['sources'] ?? []);
                foreach ($rowsSource as $s):
                    // Directional sources show their BUY/SELL/NEUTRAL vote;
                    // context sources (Calendar/Session/Volatility/Liquidity/
                    // HistoricalAccuracy/TradeJournalLearning) show their
                    // condition label instead — both matter as evidence, per
                    // the spec's own example listing Calendar/Liquidity/
                    // Volatility alongside the directional votes.
                    if ($s['is_directional']) {
                        $reading = $s['available'] ? (string) $s['direction'] : 'N/A';
                        $dCls = $s['direction'] === 'BUY' ? 'badge--bullish' : ($s['direction'] === 'SELL' ? 'badge--bearish' : 'badge--neutral');
                    } else {
                        // First clause of the reasoning text (up to the
                        // first '(' /  ',' / '.') reads as a short label —
                        // "Very Low liquidity" / "Normal volatility" /
                        // "Safe" — rather than just its first word.
                        $reasoningText = (string) $s['reasoning'];
                        $cut = strcspn($reasoningText, '(,.');
                        $reading = $s['available'] ? mb_strtoupper(trim(mb_substr($reasoningText, 0, $cut))) : 'N/A';
                        $dCls = $s['source'] === 'Calendar' && $s['is_blocking'] ? 'badge--bearish' : 'badge--market';
                    }
            ?>
                <tr>
                    <td><?= e((string) $s['source']) ?></td>
                    <td><span class="badge <?= e($dCls) ?>"><?= e($reading) ?></span></td>
                    <td><?= $s['available'] ? (int) round((float) $s['confidence']) . '%' : '—' ?></td>
                    <td class="u-muted"><?= e(mb_substr((string) $s['reasoning'], 0, 90)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php
        // disagreement is {reasons: [...], blocked_reason: ?string} — NOT a
        // flat list of dissent items (fixed a real fatal here: array_map()
        // was iterating over both keys of that wrapper, including
        // blocked_reason's scalar/null value, not just the reasons list).
        $dissentReasons = $cb['disagreement']['reasons'] ?? [];
        $blockedReason  = $cb['disagreement']['blocked_reason'] ?? null;
    ?>
    <?php if ($cb !== null && !empty($dissentReasons)): ?>
    <p class="card__sub" style="margin-top:10px"><strong>Dissenting:</strong>
        <?= e(implode(' · ', array_map(static fn(array $d): string => $d['source'] . ' (' . $d['direction'] . ')', $dissentReasons))) ?>
    </p>
    <?php endif; ?>
    <?php if ($cb !== null && !empty($blockedReason)): ?>
    <p class="card__sub" style="margin-top:6px"><strong>Blocked:</strong> <?= e((string) $blockedReason) ?></p>
    <?php endif; ?>

    <?php if ($cb !== null && $tp !== null): ?>
    <p class="card__sub" style="margin:14px 0 8px"><strong>Trade Plan</strong> — illustrative sizing against a $<?= number_format((float) $tp['reference_balance']) ?> reference balance at <?= $tp['risk_percent'] ?>% risk, not a real account.</p>
    <div class="bt-stats-grid" style="grid-template-columns:repeat(4,1fr)">
        <div class="stat-block"><span class="stat-block__val"><?= number_format((float) $tp['entry_price'], 5) ?></span><span class="stat-block__lbl">Entry</span></div>
        <div class="stat-block stat-block--red"><span class="stat-block__val"><?= number_format((float) $tp['stop_loss'], 5) ?></span><span class="stat-block__lbl">Stop Loss</span></div>
        <div class="stat-block stat-block--green"><span class="stat-block__val"><?= $tp['take_profit_1'] !== null ? number_format((float) $tp['take_profit_1'], 5) : '—' ?></span><span class="stat-block__lbl">Take Profit 1</span></div>
        <div class="stat-block stat-block--green"><span class="stat-block__val"><?= $tp['take_profit_2'] !== null ? number_format((float) $tp['take_profit_2'], 5) : '—' ?></span><span class="stat-block__lbl">Take Profit 2</span></div>
        <div class="stat-block stat-block--green"><span class="stat-block__val">$<?= number_format((float) $tp['estimated_profit'], 2) ?></span><span class="stat-block__lbl">Est. Profit</span></div>
        <div class="stat-block stat-block--red"><span class="stat-block__val">$<?= number_format((float) $tp['estimated_loss'], 2) ?></span><span class="stat-block__lbl">Est. Loss</span></div>
        <div class="stat-block"><span class="stat-block__val"><?= e((string) $tp['risk_reward_ratio']) ?></span><span class="stat-block__lbl">Risk/Reward</span></div>
        <div class="stat-block"><span class="stat-block__val"><?= (int) $tp['expected_duration_hours'][0] ?>–<?= (int) $tp['expected_duration_hours'][1] ?>h</span><span class="stat-block__lbl">Expected Duration</span></div>
    </div>
    <p class="card__sub" style="margin-top:8px">
        <strong>Expected Probability:</strong>
        <?= $tp['expected_probability']['value'] !== null ? (int) $tp['expected_probability']['value'] . '%' : 'Insufficient data' ?>
        — <?= e((string) $tp['expected_probability']['note']) ?>
    </p>
    <?php endif; ?>

    <p class="card__sub" style="margin-top:12px">Educational decision support only — this system never executes trades. Always confirm any setup yourself before acting on it.</p>
</div>
<?php endif; ?>

<!-- ══ SECTION 3: LIVE MARKET OVERVIEW ════════════════════════════ -->
<div class="card" id="market-overview">
    <div class="card__hd">
        <h3 class="card__title">Live Market Overview</h3>
        <span class="card__sub" id="mo-status" aria-live="polite">Loading…</span>
    </div>
    <div class="mo-toolbar">
        <div class="mo-tabs" role="tablist" aria-label="Filter by category">
            <button type="button" class="mo-tab is-active" data-category="all" aria-pressed="true">All</button>
            <button type="button" class="mo-tab" data-category="forex" aria-pressed="false">Forex</button>
            <button type="button" class="mo-tab" data-category="metals" aria-pressed="false">Metals</button>
            <button type="button" class="mo-tab" data-category="indices" aria-pressed="false">Indices</button>
            <button type="button" class="mo-tab" data-category="crypto" aria-pressed="false">Crypto</button>
            <button type="button" class="mo-tab" data-category="commodities" aria-pressed="false">Commodities</button>
        </div>
        <input type="search" id="mo-search" class="form-input mo-search" placeholder="Search instrument…">
    </div>
    <div class="tbl-wrap">
        <table class="tbl" id="mo-table">
            <thead>
                <tr>
                    <th aria-label="Watch"></th>
                    <th data-sort="label" class="mo-sortable">Instrument</th>
                    <th data-sort="price" class="mo-sortable">Price</th>
                    <th data-sort="change_pct" class="mo-sortable">Change %</th>
                    <th data-sort="trend" class="mo-sortable">Trend</th>
                    <th data-sort="volatility" class="mo-sortable">Volatility</th>
                    <th data-sort="signal" class="mo-sortable">Signal</th>
                    <th data-sort="confidence" class="mo-sortable">Confidence</th>
                </tr>
            </thead>
            <tbody id="mo-tbody">
                <tr><td colspan="8" class="empty">Loading live instrument data…</td></tr>
            </tbody>
        </table>
    </div>
    <p class="card__sub" style="margin-top:10px"><?= e(implode(', ', array_column($markets, 'label'))) ?> show the <strong>official</strong> signal (scanned, alerted, daily-capped). Every other instrument shows a <strong>preview</strong> <span class="mo-preview-dot" title="Preview: same signal engine, computed live — not persisted, not alerted, not subject to the daily cap"></span> — the same signal engine, computed read-only. Click ☆ to pin an instrument to your Watchlist.</p>
</div>

<!-- ══ SECTION 12: WATCHLIST ═══════════════════════════════════════ -->
<div class="card" id="watchlist">
    <div class="card__hd">
        <h3 class="card__title">Watchlist</h3>
        <span class="card__sub" id="wl-status" aria-live="polite">—</span>
    </div>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead>
                <tr>
                    <th>Instrument</th>
                    <th>Price</th>
                    <th>Change %</th>
                    <th>Trend</th>
                    <th>Actions</th>
                    <th aria-label="Unpin"></th>
                </tr>
            </thead>
            <tbody id="wl-tbody">
                <tr><td colspan="6" class="empty">Nothing pinned yet — click ☆ next to any instrument in Live Market Overview above.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ══ SECTION 4: MARKET CLOCK CENTER ═════════════════════════════ -->
<div class="card" id="market-clocks">
    <div class="card__hd">
        <h3 class="card__title">Market Clock Center</h3>
        <span class="card__sub">Auto DST-adjusted · local exchange time</span>
    </div>
    <div class="mcc-grid">
        <div class="clock" data-mcc="sydney"><span class="clock__city">Sydney</span><time class="clock__time" data-role="time">--:--:--</time><span class="clock__badge clock__badge--closed" data-role="status">CHECKING</span><span class="clock__note" data-role="countdown">—</span><span class="clock__meta" data-role="meta">—</span></div>
        <div class="clock" data-mcc="tokyo"><span class="clock__city">Tokyo</span><time class="clock__time" data-role="time">--:--:--</time><span class="clock__badge clock__badge--closed" data-role="status">CHECKING</span><span class="clock__note" data-role="countdown">—</span><span class="clock__meta" data-role="meta">—</span></div>
        <div class="clock" data-mcc="london"><span class="clock__city">London</span><time class="clock__time" data-role="time">--:--:--</time><span class="clock__badge clock__badge--closed" data-role="status">CHECKING</span><span class="clock__note" data-role="countdown">—</span><span class="clock__meta" data-role="meta">—</span></div>
        <div class="clock" data-mcc="newyork"><span class="clock__city">New York</span><time class="clock__time" data-role="time">--:--:--</time><span class="clock__badge clock__badge--closed" data-role="status">CHECKING</span><span class="clock__note" data-role="countdown">—</span><span class="clock__meta" data-role="meta">—</span></div>
    </div>
    <div class="mcc-overlap" id="mcc-overlap">
        <span class="mcc-overlap__dot"></span>
        <span class="mcc-overlap__label" id="mcc-overlap-label">No session overlap right now</span>
        <span class="mcc-overlap__state" id="mcc-overlap-state">CHECKING</span>
    </div>
    <p class="card__sub" style="margin-top:8px">Liquidity/Trading Score reflect this session's typical real-world characteristics (higher during major-session overlaps) — a heuristic, not a live AI call.</p>
</div>

<!-- ══ MARKET SCANNER ═════════════════════════════════════════════ -->
<div class="card card--accent" id="scanner">
    <div class="scanner">
        <div class="scanner__hd">
            <div class="scanner__icon" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><polyline points="21 21 16.65 16.65"/></svg>
            </div>
            <div>
                <h2 class="scanner__title">Market Scanner</h2>
                <p class="scanner__desc">Technical analysis across all configured pairs. Informational only — no trades are placed.</p>
            </div>
        </div>
        <div class="scanner__pairs">
            <?php foreach ($markets as $m): ?>
                <span class="pair-pill"><?= e($m['label']) ?></span>
            <?php endforeach; ?>
        </div>
        <form method="post" action="<?= e(url('admin/run-scan')) ?>" class="scanner__form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="btn btn--scan">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><polyline points="21 21 16.65 16.65"/></svg>
                Run Scan Now
            </button>
        </form>
    </div>
</div>

<!-- ══ SECTION 13: QUICK ACTIONS ═══════════════════════════════════ -->
<div class="card" id="quick-actions">
    <div class="card__hd"><h3 class="card__title">Quick Actions</h3></div>
    <div class="qa-grid">
        <a href="<?= e(url('vision')) ?>" class="qa-btn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <span>Upload Screenshot</span>
        </a>
        <a href="<?= e(url('vision')) ?>" class="qa-btn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            <span>Open AI Vision</span>
        </a>
        <a href="<?= e(url('dashboard')) ?>#scanner" class="qa-btn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><polyline points="21 21 16.65 16.65"/></svg>
            <span>Run Scanner</span>
        </a>
        <a href="<?= e(url('backtest/advanced')) ?>" class="qa-btn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 17 6-6 4 4 8-8"/><path d="M17 7h4v4"/></svg>
            <span>Open Backtesting</span>
        </a>
        <a href="<?= e(url('trade-journal')) ?>" class="qa-btn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg>
            <span>Trade Journal</span>
        </a>
        <a href="<?= e(url('reports')) ?>" class="qa-btn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
            <span>Reports</span>
        </a>
        <span class="qa-btn qa-btn--disabled" title="Not built yet">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 12h8M12 8v8"/></svg>
            <span>Market Replay</span>
            <em>Soon</em>
        </span>
        <span class="qa-btn qa-btn--disabled" title="Not built yet">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg>
            <span>Settings</span>
            <em>Soon</em>
        </span>
    </div>
</div>

<!-- ══ SECTION 5: MARKET SENTIMENT ════════════════════════════════ -->
<div class="card" id="market-sentiment">
    <div class="card__hd">
        <h3 class="card__title">Market Sentiment</h3>
        <span class="card__sub">Derived from configured pairs' live data — not a licensed sentiment feed</span>
    </div>
    <?php if ($sentiment === null): ?>
        <p class="empty">Sentiment data is unavailable right now — market data could not be fetched.</p>
    <?php else:
        $fg      = $sentiment['fearGreed'];
        $vol     = $sentiment['volatility'];
        $senti   = $sentiment['sentimentIndex'];
        $fgCls   = match (true) {
            $fg['score'] >= 56.0 => 'stat-block--green',
            $fg['score'] <  45.0 => 'stat-block--red',
            default              => '',
        };
        $volCls  = match ($vol['level']) { 'high' => 'stat-block--red', 'low' => 'stat-block--green', default => '' };
    ?>
    <div class="bt-stats-grid">
        <div class="stat-block <?= e($volCls) ?>">
            <span class="stat-block__val"><?= e(ucfirst((string) $vol['level'])) ?></span>
            <span class="stat-block__lbl">Market Risk Level</span>
        </div>
        <div class="stat-block <?= e($volCls) ?>">
            <span class="stat-block__val"><?= number_format((float) $vol['overall_index'], 2) ?>%</span>
            <span class="stat-block__lbl">Volatility (ATR)</span>
        </div>
        <div class="stat-block">
            <span class="stat-block__val"><?= number_format((float) $senti['confidence'], 0) ?>%</span>
            <span class="stat-block__lbl">AI Confidence</span>
        </div>
        <div class="stat-block">
            <span class="stat-block__val"><?= $sentiment['newsBullish'] ?>/<?= $sentiment['newsBearish'] ?>/<?= $sentiment['newsNeutral'] ?></span>
            <span class="stat-block__lbl">News Sentiment (Bull/Bear/Neutral)</span>
        </div>
        <div class="stat-block <?= e($fgCls) ?>">
            <span class="stat-block__val"><?= number_format((float) $fg['score'], 1) ?></span>
            <span class="stat-block__lbl">Fear/Greed — <?= e($fg['label']) ?> (internal composite)</span>
        </div>
        <div class="stat-block stat-block--green">
            <span class="stat-block__val"><?= e((string) ($sentiment['strongest'] ?? '—')) ?></span>
            <span class="stat-block__lbl">Strongest Currency</span>
        </div>
        <div class="stat-block stat-block--red">
            <span class="stat-block__val"><?= e((string) ($sentiment['weakest'] ?? '—')) ?></span>
            <span class="stat-block__lbl">Weakest Currency</span>
        </div>
    </div>

    <?php if (!empty($sentiment['currencyStrength'])):
        $maxAbsStrength = max(array_map('abs', $sentiment['currencyStrength'])) ?: 1.0;
    ?>
    <p class="card__sub" style="margin:16px 0 10px">Currency Strength Meter (avg return across configured pairs, strongest first)</p>
    <div class="csm">
        <?php foreach ($sentiment['currencyStrength'] as $currency => $score):
            $isPositive  = $score >= 0.0;
            $barHalfPct  = min(50.0, abs($score) / $maxAbsStrength * 50.0);
        ?>
        <div class="csm-row">
            <span class="csm-row__ccy"><?= e($currency) ?></span>
            <div class="csm-row__track">
                <div class="csm-row__zero"></div>
                <div class="csm-row__bar csm-row__bar--<?= $isPositive ? 'pos' : 'neg' ?>" style="width:<?= number_format($barHalfPct, 2) ?>%"></div>
            </div>
            <span class="csm-row__val <?= $isPositive ? 'u-mo-up' : 'u-mo-down' ?>"><?= $isPositive ? '+' : '' ?><?= number_format($score, 2) ?>%</span>
        </div>
        <?php endforeach; ?>
    </div>

    <p class="card__sub" style="margin:18px 0 10px">Heatmap — ranked, click a currency for details</p>
    <div class="css-grid">
        <?php $rank = 1; foreach ($sentiment['currencyStrength'] as $currency => $score):
            $isPositive = $score >= 0.0;
            $ccyHistory = $sentiment['history'][$currency] ?? [];
            $sparkColor = $isPositive ? '#22c55e' : '#ef4444';
            $trendArrow = match (true) {
                count($ccyHistory) < 3 => '●',
                (float) end($ccyHistory)['score'] > (float) $ccyHistory[max(0, count($ccyHistory) - 3)]['score'] + 0.01 => '▲',
                (float) end($ccyHistory)['score'] < (float) $ccyHistory[max(0, count($ccyHistory) - 3)]['score'] - 0.01 => '▼',
                default => '●',
            };
        ?>
        <button type="button" class="css-tile <?= $isPositive ? 'css-tile--pos' : 'css-tile--neg' ?>" data-currency="<?= e($currency) ?>" aria-haspopup="dialog">
            <span class="css-tile__rank">#<?= $rank++ ?></span>
            <span class="css-tile__ccy"><?= e($currency) ?></span>
            <span class="css-tile__trend"><?= e($trendArrow) ?></span>
            <span class="css-tile__pct"><?= $isPositive ? '+' : '' ?><?= number_format($score, 2) ?>%</span>
            <span class="css-tile__spark"><?= currency_sparkline($ccyHistory, $sparkColor) ?></span>
        </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <p class="card__sub" style="margin-top:10px">Fear/Greed is an internal composite of AI sentiment lean + realized volatility from this app's own data — not a market-wide crowd-sentiment index. <a href="<?= e(url('market-intelligence')) ?>">Full breakdown →</a></p>
    <?php endif; ?>
</div>

<!-- ══ Currency detail modal (heatmap tile click-through) ═════════ -->
<div class="ccy-modal" id="ccy-modal" role="dialog" aria-modal="true" aria-labelledby="ccy-modal-title" hidden>
    <div class="ccy-modal__backdrop" data-role="close"></div>
    <div class="ccy-modal__panel">
        <button type="button" class="ccy-modal__close" data-role="close" aria-label="Close">&times;</button>
        <h3 class="ccy-modal__title" id="ccy-modal-title">—</h3>
        <div class="ccy-modal__body" data-role="body">
            <p class="empty">Loading…</p>
        </div>
    </div>
</div>

<!-- ══ AI MARKET INSIGHT ═════════════════════════════════════════ -->
<?php
    $ins       = $aiInsight ?? null;
    $insDecision = strtoupper((string) ($ins['decision']   ?? 'WAIT'));
    $insRisk     = strtolower((string) ($ins['risk_level'] ?? 'medium'));
    $insConf     = (int) ($ins['confidence'] ?? 0);
    $insSummary  = (string) ($ins['summary']   ?? '');
    $insPoints   = is_array($ins['key_points'] ?? null) ? $ins['key_points'] : [];
    $insProvider = strtolower((string) ($ins['provider'] ?? 'rule_based'));
    $insGenAt    = (string) ($ins['generated_at'] ?? '');

    // Decision → CSS modifier
    $decCls = match ($insDecision) {
        'BULLISH_BIAS' => 'insight--bullish',
        'BEARISH_BIAS' => 'insight--bearish',
        'WATCH'        => 'insight--watch',
        default        => 'insight--wait',
    };
    // Decision → display label
    $decLabel = match ($insDecision) {
        'BULLISH_BIAS' => 'BULLISH BIAS',
        'BEARISH_BIAS' => 'BEARISH BIAS',
        default        => $insDecision,
    };
    // Risk → CSS modifier
    $riskCls = "risk--{$insRisk}";
    // Confidence bar width
    $confBar = max(4, $insConf);
    // Generated at label
    $insGenFormatted = '';
    if ($insGenAt !== '') {
        try { $insGenFormatted = (new DateTimeImmutable($insGenAt))->format('M j · H:i'); }
        catch (Throwable) { $insGenFormatted = $insGenAt; }
    }
    $insProvLabel = match ($insProvider) {
        'claude'     => 'Claude AI',
        'openai'     => 'OpenAI',
        'rule_based' => 'Rule-based',
        default      => 'Rule-based',
    };
?>
<div class="card card--ai-insight">
    <div class="card__hd">
        <h3 class="card__title">AI Market Insight</h3>
        <div class="ai-meta">
            <?php if ($ins): ?>
            <span class="badge insight-risk-badge <?= e($riskCls) ?>"><?= e(strtoupper($insRisk)) ?> RISK</span>
            <span class="badge badge--ai badge--ai-<?= e($insProvider) ?>"><?= e($insProvLabel) ?></span>
            <?php if ($insGenFormatted !== ''): ?>
            <span class="ai-time"><?= e($insGenFormatted) ?></span>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($ins): ?>
    <div class="insight-header">
        <span class="insight-decision <?= e($decCls) ?>"><?= e($decLabel) ?></span>
        <div class="insight-conf-wrap">
            <span class="insight-conf-label">Confidence</span>
            <div class="insight-conf-bar">
                <div class="insight-conf-fill" style="width:<?= $confBar ?>%"></div>
            </div>
            <span class="insight-conf-pct"><?= $insConf ?>%</span>
        </div>
    </div>

    <?php if ($insSummary !== ''): ?>
    <p class="insight-summary"><?= e($insSummary) ?></p>
    <?php endif; ?>

    <?php if (!empty($insPoints)): ?>
    <ul class="insight-points">
        <?php foreach ($insPoints as $pt): ?>
        <li><?= e((string) $pt) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php else: ?>
    <p class="empty">No insight generated yet. Run a scan to generate one.</p>
    <?php endif; ?>
</div>

<!-- ══ SECTION 9: MULTI-TIMEFRAME PANEL ═══════════════════════════ -->
<div class="card" id="multi-timeframe-panel">
    <div class="card__hd">
        <h3 class="card__title">Multi-Timeframe Panel</h3>
        <?php if ($latestMultiTimeframe): ?>
        <span class="card__sub"><?= e((string) $latestMultiTimeframe['market_label']) ?> — last analyzed <?= e(fmt_datetime((string) $latestMultiTimeframe['created_at'])) ?></span>
        <?php endif; ?>
    </div>
    <?php if ($latestMultiTimeframe):
        $mtfBias = strtolower((string) $latestMultiTimeframe['overall_bias']);
        $mtfBiasCls = match ($mtfBias) { 'bullish' => 'u-mo-up', 'bearish' => 'u-mo-down', default => 'u-muted' };
    ?>
    <div class="dc-hd" style="margin-bottom:14px">
        <div>
            <span class="dc-pair">Overall Bias</span>
            <div class="dc-action <?= e($mtfBiasCls) ?>" style="font-size:1.4rem"><?= e(ucfirst($mtfBias)) ?></div>
        </div>
        <div class="insight-conf-wrap">
            <span class="insight-conf-label">Confidence</span>
            <div class="insight-conf-bar"><div class="insight-conf-fill" style="width:<?= max(4, (float) $latestMultiTimeframe['confidence']) ?>%"></div></div>
            <span class="insight-conf-pct"><?= (int) round((float) $latestMultiTimeframe['confidence']) ?>%</span>
        </div>
    </div>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Timeframe</th><th>Trend</th><th>Momentum</th><th>Alignment</th></tr></thead>
            <tbody>
            <?php foreach ($latestMultiTimeframe['breakdown'] as $tfLabel => $tf):
                $tfTrend  = strtolower((string) ($tf['trend'] ?? 'neutral'));
                $tfStatus = strtolower((string) ($tf['status'] ?? 'neutral'));
                $statusCls = match ($tfStatus) { 'aligned' => 'badge--bullish', 'conflict' => 'badge--bearish', default => 'badge--neutral' };
            ?>
                <tr>
                    <td><strong><?= e((string) $tfLabel) ?></strong></td>
                    <td class="<?= $tfTrend === 'bullish' ? 'u-mo-up' : ($tfTrend === 'bearish' ? 'u-mo-down' : 'u-muted') ?>"><?= match($tfTrend) { 'bullish' => '▲ Bullish', 'bearish' => '▼ Bearish', default => '● Neutral' } ?></td>
                    <td class="u-muted"><?= e(ucfirst((string) ($tf['momentum'] ?? 'neutral'))) ?></td>
                    <td><span class="badge <?= e($statusCls) ?>"><?= e(strtoupper($tfStatus)) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (!empty($latestMultiTimeframe['conflicts'])): ?>
    <div class="flash error" style="margin-top:14px">
        <div>
            <strong>Conflicts detected:</strong>
            <ul class="bt-ai-reasons">
                <?php foreach ($latestMultiTimeframe['conflicts'] as $c): ?><li><?= e((string) $c) ?></li><?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>
    <p class="card__sub" style="margin-top:12px">From the last saved analysis, not live — <a href="<?= e(url('multi-timeframe')) ?>">run a fresh top-down analysis →</a></p>
    <?php else: ?>
        <p class="empty">No multi-timeframe analysis has been run yet. <a href="<?= e(url('multi-timeframe')) ?>">Run one →</a></p>
    <?php endif; ?>
</div>

<!-- ══ SECTION 10: TRADE COACH ═════════════════════════════════════ -->
<?php if (feature_enabled('TRADE_COACH')):
    $coachDecision = App\TradeCoach\CoachContentLibrary::normalizeDecision($bestOpportunity ? strtoupper((string) $bestOpportunity['action']) : 'WAIT');
    $coachRisk     = strtolower((string) ($aiInsight['risk_level'] ?? 'medium'));
    $coachMode     = 'beginner';
?>
<div class="card" id="trade-coach-widget">
    <div class="card__hd">
        <h3 class="card__title">Trade Coach</h3>
        <a href="<?= e(url('trade-coach')) ?>" class="card__count">Full AI narrative →</a>
    </div>
    <p class="insight-decision" style="font-size:1.2rem"><?= e(App\TradeCoach\CoachContentLibrary::decisionHeadline($coachDecision, $coachMode)) ?></p>
    <p class="ai-summary-text" style="margin-top:8px"><?= e(App\TradeCoach\CoachContentLibrary::riskExplanation($coachRisk, $coachMode)) ?></p>

    <div class="ams-grid ams-grid--2col">
        <div class="ams-block">
            <p class="ams-block__lbl">Common Mistakes</p>
            <?php foreach (App\TradeCoach\CoachContentLibrary::commonMistakes($coachDecision, $coachMode) as $mistake): ?>
            <p class="ams-block__text">⚠ <?= e($mistake) ?></p>
            <?php endforeach; ?>
        </div>
        <div class="ams-block">
            <p class="ams-block__lbl">Learning Recommendation</p>
            <?php foreach (App\TradeCoach\CoachContentLibrary::educationalTips($coachDecision, $coachMode) as $tip): ?>
            <p class="ams-block__text">📘 <?= e($tip) ?></p>
            <?php endforeach; ?>
        </div>
    </div>
    <p class="ai-disclaimer" style="margin-top:10px">🧠 Psychology tip: <?= e(App\TradeCoach\CoachContentLibrary::psychologyGuidance($coachDecision, $coachMode)) ?></p>
</div>
<?php endif; ?>

<!-- ══ SECTION 11: PERFORMANCE DASHBOARD ═══════════════════════════ -->
<div class="card">
    <div class="card__hd">
        <h3 class="card__title">Performance Dashboard</h3>
        <span class="card__sub">BUY/SELL signals only — mark outcomes using buttons below</span>
    </div>
    <?php
        $ps  = $perfStats;
        $pp  = $performancePanel;
        $haveStats = $ps['with_outcome'] > 0;
        $cal = $pp['aiCalibration'];
    ?>
    <div class="perf-grid">
        <div class="perf-stat">
            <span class="perf-stat__num"><?= $pp['signalsToday'] ?></span>
            <span class="perf-stat__lbl">Signals today</span>
        </div>
        <div class="perf-stat perf-stat--green">
            <span class="perf-stat__num"><?= $haveStats ? $ps['win_rate'] . '%' : '—' ?></span>
            <span class="perf-stat__lbl">Win rate</span>
        </div>
        <div class="perf-stat">
            <span class="perf-stat__num"><?= $pp['backtestAccuracy'] !== null ? $pp['backtestAccuracy'] . '%' : '—' ?></span>
            <span class="perf-stat__lbl">Backtest accuracy</span>
        </div>
        <div class="perf-stat">
            <span class="perf-stat__num"><?= $cal['sample_size'] > 0 ? $cal['overall_factor'] : '—' ?></span>
            <span class="perf-stat__lbl">AI accuracy (calibration)<?= $cal['sample_size'] === 0 ? ' — insufficient data' : '' ?></span>
        </div>
        <div class="perf-stat">
            <span class="perf-stat__num"><?= $pp['avgConfidence'] !== null ? $pp['avgConfidence'] . '%' : '—' ?></span>
            <span class="perf-stat__lbl">Average confidence</span>
        </div>
        <div class="perf-stat">
            <span class="perf-stat__num"><?= $pp['patternCount'] > 0 ? count($pp['successfulPatterns']) . '/' . $pp['patternCount'] : '—' ?></span>
            <span class="perf-stat__lbl">Successful patterns (≥50% WR)</span>
        </div>
        <div class="perf-stat">
            <span class="perf-stat__num"><?= $pp['openOpportunities'] ?></span>
            <span class="perf-stat__lbl">Open opportunities</span>
        </div>
        <div class="perf-stat">
            <span class="perf-stat__num"><?= $pp['avgRiskReward'] !== null ? $pp['avgRiskReward'] . 'R' : '—' ?></span>
            <span class="perf-stat__lbl">Average risk/reward</span>
        </div>
        <div class="perf-stat perf-stat--green">
            <span class="perf-stat__num"><?= e($ps['best_market']) ?></span>
            <span class="perf-stat__lbl">Best pair</span>
        </div>
        <div class="perf-stat perf-stat--red">
            <span class="perf-stat__num"><?= e($ps['worst_market']) ?></span>
            <span class="perf-stat__lbl">Weakest pair</span>
        </div>
    </div>

    <?php if (!empty($ps['by_confidence'])): ?>
    <div style="margin-top:14px">
        <p class="card__sub" style="margin-bottom:8px">Confidence calibration</p>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Band</th><th>Signals</th><th>Wins</th><th>Win rate</th></tr></thead>
                <tbody>
                <?php foreach ($ps['by_confidence'] as $cb):
                    $cbBase = max(1, (int)$cb['total']);
                    $cbWr   = round((int)$cb['wins'] / $cbBase * 100, 1);
                ?>
                <tr>
                    <td><?= e((string)$cb['band']) ?></td>
                    <td><?= (int)$cb['total'] ?></td>
                    <td><?= (int)$cb['wins'] ?></td>
                    <td><?= $cbWr ?>%</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ══ MARKET DATA PROVIDER HEALTH (Phase 2.2A) ═══════════════════ -->
<?php if (!empty($marketDataStats)): ?>
<div class="card">
    <div class="card__hd">
        <h3 class="card__title">Market Data Provider Health</h3>
        <span class="card__sub">Deriv (live) → Yahoo Finance (fallback) → cache</span>
    </div>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Provider</th><th>Calls</th><th>Successes</th><th>Fallback uses</th><th>Cache serves</th><th>Avg latency</th></tr></thead>
            <tbody>
            <?php foreach ($marketDataStats as $s): ?>
            <tr>
                <td><?= e(ucwords(str_replace('_', ' ', (string) $s['provider_used']))) ?></td>
                <td><?= (int) $s['total_calls'] ?></td>
                <td><?= (int) $s['successes'] ?></td>
                <td><?= (int) $s['fallback_uses'] ?></td>
                <td><?= (int) $s['cache_serves'] ?></td>
                <td><?= round((float) $s['avg_latency_ms']) ?>ms</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (!empty($marketDataFallbackEvents)): ?>
    <p class="card__sub" style="margin-top:12px">Recent fallback/failure events</p>
    <ul class="bt-ai-reasons">
        <?php foreach ($marketDataFallbackEvents as $ev): ?>
        <li><?= e(fmt_datetime((string) $ev['created_at'])) ?> — <?= e((string) $ev['symbol']) ?> (<?= e((string) $ev['operation']) ?>): served by <?= e((string) $ev['provider_used']) ?><?= !empty($ev['error_message']) ? ' — ' . e((string) $ev['error_message']) : '' ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ══ SECTION 7: ECONOMIC CALENDAR — TODAY ═══════════════════════ -->
<div class="card" id="calendar-today">
    <div class="card__hd">
        <h3 class="card__title">Economic Calendar — Today</h3>
        <span class="card__sub"><?= e(now_eat()->format('l, M j')) ?> — EAT timezone</span>
    </div>
    <?php if (!empty($todayCalendarEvents)): ?>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Event</th><th>CCY</th><th>Impact</th><th>Time (EAT)</th><th>Countdown</th></tr></thead>
            <tbody>
            <?php foreach ($todayCalendarEvents as $ev):
                $dt = (string) ($ev['scheduled_at'] ?? '');
                try { $dtFmt = $dt !== '' ? (new DateTimeImmutable($dt))->format('H:i') : '—'; }
                catch (Throwable) { $dtFmt = $dt; }
                $isoEat = $dt !== '' ? str_replace(' ', 'T', $dt) . '+03:00' : '';
                $impact = strtolower((string) ($ev['impact'] ?? 'high'));
            ?>
            <tr class="<?= $impact === 'high' ? 'tr--blocking' : '' ?>">
                <td><?= e((string) ($ev['title'] ?? '')) ?></td>
                <td class="td--ccy"><?= e((string) ($ev['currency'] ?? '')) ?></td>
                <td><span class="badge badge--impact badge--<?= e($impact) ?>"><?= e(strtoupper($impact)) ?></span></td>
                <td class="td--mono td--muted"><?= e($dtFmt) ?></td>
                <td class="td--mono cal-countdown" data-scheduled="<?= e($isoEat) ?>">—</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <p class="empty">No calendar events scheduled for today. <a href="<?= e(url('calendar')) ?>">Manage calendar →</a></p>
    <?php endif; ?>
</div>

<!-- ══ ECONOMIC CALENDAR (7-DAY RISK WINDOW) ═══════════════════════ -->
<div class="card">
    <div class="card__hd">
        <h3 class="card__title">Economic Calendar Risk</h3>
        <span class="card__sub">next 7 days — EAT timezone</span>
    </div>
    <?php if (!empty($calendarEvents)): ?>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Event</th><th>CCY</th><th>EAT Time</th><th>Markets</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($calendarEvents as $ev):
                $blocking = (bool)($ev['is_active'] ?? false);
                $dt = $ev['scheduled_at'] ?? '';
                try { $dtFmt = $dt !== '' ? (new DateTimeImmutable($dt))->format('M j · H:i') : '—'; }
                catch (Throwable) { $dtFmt = $dt; }
            ?>
            <tr class="<?= $blocking ? 'tr--blocking' : '' ?>">
                <td><span class="badge badge--impact badge--<?= e(strtolower((string)($ev['impact'] ?? 'high'))) ?>"><?= e(strtoupper((string)($ev['impact'] ?? 'HIGH'))) ?></span> <?= e((string)($ev['title'] ?? '')) ?></td>
                <td class="td--ccy"><?= e((string)($ev['currency'] ?? '')) ?></td>
                <td class="td--mono td--muted"><?= e($dtFmt) ?></td>
                <td><?php foreach (($ev['markets'] ?? []) as $mkt): ?><span class="badge badge--market"><?= e($mkt) ?></span> <?php endforeach; ?></td>
                <td><span class="badge badge--cal-<?= $blocking ? 'blocking' : 'upcoming' ?>"><?= $blocking ? 'BLOCKING' : 'UPCOMING' ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <p class="empty">No high-impact events for the next 7 days. <a href="<?= e(url('calendar')) ?>">Manage calendar →</a></p>
    <?php endif; ?>
</div>

<!-- ══ AI SUMMARY ════════════════════════════════════════════════ -->
<div class="card card-ai">
    <div class="card__hd">
        <h3 class="card__title">AI Market Summary</h3>
        <?php if ($aiSummary): ?>
        <div class="ai-meta">
            <?php
                $prov = strtolower((string)($aiSummary['provider'] ?? 'rule_based'));
                if (!in_array($prov, ['claude','openai','cache','rule_based'], true)) $prov = 'rule_based';
                $provLabel = match($prov) { 'claude' => 'Claude AI', 'openai' => 'OpenAI', 'cache' => 'Cached', default => 'Rule-based' };
                $createdAt = (string)($aiSummary['created_at'] ?? '');
                if ($createdAt !== '') { try { $createdAt = (new DateTimeImmutable($createdAt))->format('M j · H:i'); } catch (Throwable) {} }
            ?>
            <span class="badge badge--ai badge--ai-<?= e($prov) ?>"><?= e($provLabel) ?></span>
            <span class="ai-model"><?= e((string)($aiSummary['model'] ?? '')) ?></span>
            <?php if ($createdAt !== ''): ?><span class="ai-time"><?= e($createdAt) ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($aiSummary): ?>
        <p class="ai-summary-text"><?= e(truncate_words((string)($aiSummary['summary_text'] ?? ''), 200)) ?></p>
        <div class="ai-signal-row">
            <?php
                $bias = strtolower((string)($aiSummary['market_bias'] ?? 'mixed'));
                $validAiBiases = ['bullish_usd','bearish_usd','mixed','risk_off','risk_on','neutral'];
                if (!in_array($bias, $validAiBiases, true)) $bias = 'mixed';
                $biasLabel = match($bias) { 'bullish_usd'=>'Bullish USD','bearish_usd'=>'Bearish USD','risk_off'=>'Risk Off','risk_on'=>'Risk On','neutral'=>'Neutral',default=>'Mixed' };
                $conf = (float)($aiSummary['confidence'] ?? 0.0);
            ?>
            <span class="badge badge--bias-<?= e($bias) ?>"><?= e($biasLabel) ?></span>
            <span class="ai-confidence"><?= e(number_format($conf, 1)) ?>% confidence</span>
        </div>
        <?php $pairNotes = is_array($aiSummary['pair_notes'] ?? null) ? $aiSummary['pair_notes'] : []; ?>
        <?php if (!empty($pairNotes)): ?>
        <div class="ai-pair-notes">
            <p class="ai-pair-notes__label">Technical Analysis by Pair</p>
            <?php foreach ($pairNotes as $pair => $note): ?>
            <div class="ai-pair-note"><span class="badge badge--market"><?= e((string)$pair) ?></span><span class="ai-pair-note__text"><?= e((string)$note) ?></span></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php $riskNote = trim((string)($aiSummary['risk_note'] ?? '')); ?>

        <!-- Consolidated at-a-glance sub-sections (Section 6) — all built from data already loaded elsewhere on this page, no extra AI calls. -->
        <div class="ams-grid">
            <div class="ams-block">
                <p class="ams-block__lbl">Today's Opportunities</p>
                <?php if ($bestOpportunity): ?>
                    <p class="ams-block__text"><span class="badge badge--market"><?= e((string)$bestOpportunity['market_label']) ?></span> <?= e(strtoupper((string)$bestOpportunity['action'])) ?> at <?= (int)$bestOpportunity['confidence'] ?>% confidence.</p>
                <?php else: ?>
                    <p class="ams-block__text u-muted">None currently — see Market Decision Center above.</p>
                <?php endif; ?>
            </div>
            <div class="ams-block">
                <p class="ams-block__lbl">Today's Risks</p>
                <?php
                    $blockingEvents = array_filter($calendarEvents, static fn(array $e): bool => (bool)($e['is_active'] ?? false));
                    $hasElevatedInsightRisk = isset($insRisk) && in_array($insRisk, ['medium', 'high'], true);
                ?>
                <?php if ($hasElevatedInsightRisk): ?><p class="ams-block__text">⚠ AI Insight flags <?= e($insRisk) ?> risk conditions.</p><?php endif; ?>
                <?php foreach (array_slice($blockingEvents, 0, 2) as $ev): ?>
                    <p class="ams-block__text">⚠ <?= e((string)($ev['title'] ?? '')) ?> (<?= e((string)($ev['currency'] ?? '')) ?>)</p>
                <?php endforeach; ?>
                <?php if (!$hasElevatedInsightRisk && empty($blockingEvents)): ?><p class="ams-block__text u-muted">No elevated risks flagged right now.</p><?php endif; ?>
            </div>
            <div class="ams-block">
                <p class="ams-block__lbl">Major News</p>
                <?php if (!empty($news)): ?>
                    <?php foreach (array_slice($news, 0, 3) as $n): ?>
                    <p class="ams-block__text"><?= e((string)$n['title']) ?></p>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="ams-block__text u-muted">No headlines yet.</p>
                <?php endif; ?>
            </div>
            <div class="ams-block">
                <p class="ams-block__lbl">Upcoming Events</p>
                <?php if (!empty($calendarEvents)): ?>
                    <?php foreach (array_slice($calendarEvents, 0, 2) as $ev):
                        try { $evTime = (new DateTimeImmutable((string)($ev['scheduled_at'] ?? '')))->format('M j · H:i'); } catch (Throwable) { $evTime = ''; }
                    ?>
                    <p class="ams-block__text"><?= e((string)($ev['title'] ?? '')) ?> — <?= e($evTime) ?> EAT</p>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="ams-block__text u-muted">Nothing scheduled in the next 7 days.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($riskNote !== ''): ?><p class="ai-disclaimer"><?= e($riskNote) ?></p><?php endif; ?>
    <?php else: ?>
        <p class="empty">No AI summary yet. Run a scan to generate one.</p>
    <?php endif; ?>
</div>

<!-- ══ MAIN GRID: Signals + News ════════════════════════════════ -->
<div class="db-row db-row--bottom">

    <!-- Signals -->
    <div class="card" id="signals">
        <div class="card__hd">
            <h3 class="card__title">Recent Signals</h3>
            <?php if ($signals): ?><span class="card__count"><?= count($signals) ?> records</span><?php endif; ?>
        </div>
        <?php if ($signals): ?>
        <div class="tbl-wrap">
            <table class="tbl tbl--signals">
                <thead><tr><th>Time</th><th>Market</th><th>Action</th><th>Conf.</th><th>Session</th><th>Reasons</th><th>Outcome</th></tr></thead>
                <tbody>
                <?php foreach ($signals as $sig):
                    $act     = strtoupper((string)$sig['action']);
                    $cls     = match($act) { 'BUY'=>'tr--buy','SELL'=>'tr--sell','WAIT'=>'tr--wait','AVOID'=>'tr--avoid','WATCH'=>'tr--watch',default=>'' };
                    $outcome = (string)($sig['outcome'] ?? '');
                    $hasOutcome = $outcome !== '';
                    $sigId   = (int)$sig['id'];
                    $reasons = array_filter(explode("\n", (string)$sig['reasons']), static fn(string $l): bool => trim($l) !== '');
                    $journal = $journals[$sigId] ?? null;
                    $isActionable = in_array($act, ['BUY','SELL'], true);
                ?>
                <tr class="<?= e($cls) ?>">
                    <td class="td--mono td--muted td--nowrap"><?= e(fmt_datetime((string)$sig['created_at'])) ?></td>
                    <td><span class="badge badge--market"><?= e((string)$sig['market_label']) ?></span></td>
                    <td class="td--action td--nowrap"><?= e($act) ?></td>
                    <td class="td--conf td--nowrap"><?= e((string)$sig['confidence']) ?>%</td>
                    <td class="td--muted td--nowrap"><?= e(ucfirst(str_replace('_',' ',(string)$sig['session_name']))) ?></td>
                    <td><ul class="reasons"><?php foreach ($reasons as $r): ?><li><?= e(trim($r)) ?></li><?php endforeach; ?></ul></td>
                    <td class="td--outcome">
                        <?php if ($hasOutcome): ?>
                            <?php
                                $oc = strtolower($outcome);
                                $ocBadge = match($oc) { 'win'=>'badge--outcome-win','loss'=>'badge--outcome-loss','breakeven'=>'badge--outcome-be',default=>'badge--outcome-skip' };
                            ?>
                            <span class="badge <?= e($ocBadge) ?>"><?= e(strtoupper($outcome)) ?></span>
                        <?php elseif ($isActionable): ?>
                            <div class="outcome-btns">
                                <?php foreach (['win'=>'W','loss'=>'L','breakeven'=>'BE','skipped'=>'SK'] as $ov => $lbl): ?>
                                <form method="post" action="<?= e(url('signal/outcome')) ?>" style="display:inline">
                                    <input type="hidden" name="_csrf"      value="<?= e(App\Core\Csrf::token()) ?>">
                                    <input type="hidden" name="signal_id"  value="<?= $sigId ?>">
                                    <input type="hidden" name="outcome"    value="<?= e($ov) ?>">
                                    <button type="submit" class="outcome-btn outcome-btn--<?= e($ov) ?>" title="Mark <?= e($ov) ?>"><?= e($lbl) ?></button>
                                </form>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span class="td--muted">—</span>
                        <?php endif; ?>

                        <!-- Journal notes (collapsed) -->
                        <details class="journal-detail">
                            <summary class="journal-toggle">📓 Note</summary>
                            <form method="post" action="<?= e(url('signal/journal')) ?>" class="journal-form">
                                <input type="hidden" name="_csrf"     value="<?= e(App\Core\Csrf::token()) ?>">
                                <input type="hidden" name="signal_id" value="<?= $sigId ?>">
                                <textarea name="notes" rows="2" class="journal-textarea" placeholder="Add your observations..."><?= e((string)($journal['notes'] ?? '')) ?></textarea>
                                <button type="submit" class="btn-journal-save">Save</button>
                            </form>
                        </details>

                        <?php if ($isActionable && feature_enabled('TRADE_JOURNAL')): ?>
                        <a href="<?= e(url('trade-journal')) ?>?log_signal_id=<?= $sigId ?>" class="journal-toggle">📔 Log to Journal</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <p class="empty">No signals recorded yet. Run a scan to begin.</p>
        <?php endif; ?>
    </div>

    <!-- Side column: News + Risk Calc -->
    <div>
        <!-- News -->
        <div class="card" id="news">
            <div class="card__hd">
                <h3 class="card__title">Market News</h3>
                <?php if ($news): ?><span class="card__count"><?= count($news) ?> items</span><?php endif; ?>
            </div>
            <?php if ($news): ?>
            <ul class="news">
            <?php foreach ($news as $item):
                $bias = strtolower((string)($item['market_bias'] ?? 'neutral'));
                if (!in_array($bias, ['bullish','bearish','neutral'], true)) $bias = 'neutral';
                $pubDate  = substr((string)($item['published_at'] ?? ''), 0, 10);
                $provider = strtolower((string)($item['provider'] ?? 'unknown'));
                if (!in_array($provider, ['marketaux','rss','newsapi'], true)) $provider = 'unknown';
                $provLabel = match($provider) { 'marketaux'=>'MX','rss'=>'RSS','newsapi'=>'NA',default=>'?' };
                $category  = categorize_news((string)$item['title'], (string)$item['source']);
                $cachedExplanation = trim((string)($item['ai_explanation'] ?? ''));
            ?>
                <li class="news__item">
                    <p class="news__headline"><span class="badge badge--bias badge--<?= e($bias) ?>"><?= e(strtoupper($bias)) ?></span><span class="badge badge--category"><?= e($category) ?></span><?= e((string)$item['title']) ?></p>
                    <div class="news__meta">
                        <span class="badge badge--prov badge--prov-<?= e($provider) ?>"><?= e($provLabel) ?></span>
                        <span class="news__source"><?= e((string)$item['source']) ?></span>
                        <?php if ($pubDate !== ''): ?><span class="news__date"><?= e($pubDate) ?></span><?php endif; ?>
                        <button type="button" class="news__explain-btn" data-news-id="<?= (int)$item['id'] ?>" <?= $cachedExplanation !== '' ? 'style="display:none"' : '' ?>>✨ Explain impact</button>
                    </div>
                    <div class="news__explanation" data-role="explanation" <?= $cachedExplanation === '' ? 'hidden' : '' ?>><?= e($cachedExplanation) ?></div>
                </li>
            <?php endforeach; ?>
            </ul>
            <?php else: ?>
                <p class="empty">No news yet. Run a scan to fetch headlines.</p>
            <?php endif; ?>
        </div>

        <!-- Risk Calculator -->
        <div class="card">
            <h3 class="card__title">Risk Calculator</h3>
            <p class="card__sub" style="margin-bottom:14px">Position size estimator — educational only</p>
            <div class="rc-fields">
                <div class="form-group">
                    <label for="rc-bal">Account balance ($)</label>
                    <input type="number" id="rc-bal" min="0" step="100" value="1000" class="form-input" oninput="calcRisk()">
                </div>
                <div class="form-group">
                    <label for="rc-pct">Risk per trade (%)</label>
                    <input type="number" id="rc-pct" min="0.1" max="10" step="0.1" value="1" class="form-input" oninput="calcRisk()">
                </div>
                <div class="form-group">
                    <label for="rc-stop">Stop loss (pips)</label>
                    <input type="number" id="rc-stop" min="1" step="1" value="20" class="form-input" oninput="calcRisk()">
                </div>
                <div class="form-group">
                    <label for="rc-pair">Pair</label>
                    <select id="rc-pair" class="form-select" onchange="calcRisk()">
                        <?php foreach ($markets as $m): ?>
                        <option value="<?= e($m['label']) ?>"><?= e($m['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="rc-result" id="rc-result">
                <div class="rc-row"><span>Risk amount</span><strong id="rc-risk-amt">—</strong></div>
                <div class="rc-row"><span>Standard lots</span><strong id="rc-lots">—</strong></div>
                <div class="rc-row"><span>Mini lots (×10)</span><strong id="rc-mini">—</strong></div>
                <div class="rc-row"><span>Micro lots (×100)</span><strong id="rc-micro">—</strong></div>
                <div class="rc-row"><span>2R profit target</span><strong id="rc-2r">—</strong></div>
            </div>
            <p class="ai-disclaimer">Estimated values only. Verify with your broker.</p>
        </div>
    </div>

</div>

<!-- ══ MARKET CLOCK CENTER SCRIPT (Section 4) ═════════════════════ -->
<script>
(function () {
    'use strict';
    // Standard forex session hours, in each exchange's own LOCAL time — the
    // IANA zone names below make DST transitions automatic (no manual
    // offset math, no daylight-saving bugs twice a year).
    // liquidityTier reflects real, well-established forex market structure
    // (London and New York carry the deepest liquidity of the four
    // sessions) — not a live computation, disclosed as a heuristic in the
    // card's own subtitle.
    var MARKETS = [
        { id: 'sydney',  tz: 'Australia/Sydney',   open: 8,  close: 17, liquidityTier: 1, label: 'Sydney' },
        { id: 'tokyo',   tz: 'Asia/Tokyo',          open: 9,  close: 18, liquidityTier: 2, label: 'Tokyo' },
        { id: 'london',  tz: 'Europe/London',       open: 8,  close: 17, liquidityTier: 3, label: 'London' },
        { id: 'newyork', tz: 'America/New_York',    open: 8,  close: 17, liquidityTier: 3, label: 'New York' },
    ];
    var LIQUIDITY_LABELS = { 0: 'Closed', 1: 'Low', 2: 'Medium', 3: 'High' };
    var PRE_AFTER_WINDOW_SEC = 60 * 60; // within 1h of open/close

    var timeFmts = {};
    var partFmts = {};
    MARKETS.forEach(function (m) {
        timeFmts[m.id] = new Intl.DateTimeFormat('en-GB', { timeZone: m.tz, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
        partFmts[m.id] = new Intl.DateTimeFormat('en-US', { timeZone: m.tz, hour12: false, weekday: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    });

    var WEEKDAY_INDEX = { Mon: 0, Tue: 1, Wed: 2, Thu: 3, Fri: 4, Sat: 5, Sun: 6 };
    var WEEK_SECONDS = 7 * 86400;

    function zonedSeconds(now, marketId) {
        var parts = {};
        partFmts[marketId].formatToParts(now).forEach(function (p) { parts[p.type] = p.value; });
        var day = WEEKDAY_INDEX[parts.weekday];
        return day * 86400 + parseInt(parts.hour, 10) * 3600 + parseInt(parts.minute, 10) * 60 + parseInt(parts.second, 10);
    }

    function fmtDuration(totalSeconds) {
        var h = Math.floor(totalSeconds / 3600);
        var m = Math.floor((totalSeconds % 3600) / 60);
        return (h < 10 ? '0' : '') + h + 'h ' + (m < 10 ? '0' : '') + m + 'm';
    }

    /** Session windows Mon(0)-Fri(4) local, [open,close) in seconds-of-week. */
    function sessionWindows(market) {
        var wins = [];
        for (var d = 0; d <= 4; d++) {
            wins.push({ start: d * 86400 + market.open * 3600, end: d * 86400 + market.close * 3600 });
        }
        return wins;
    }

    var state = {}; // marketId -> { isOpen, closesIn, opensIn }

    function evaluate(market, nowSec) {
        var wins = sessionWindows(market);
        for (var i = 0; i < wins.length; i++) {
            if (nowSec >= wins[i].start && nowSec < wins[i].end) {
                return { isOpen: true, closesIn: wins[i].end - nowSec, opensIn: null, justClosed: false };
            }
        }
        // Closed — find the next open boundary within the week (wrap if needed).
        var best = null;
        for (var w = 0; w < 2; w++) { // this week + next week, to always find one
            for (var j = 0; j < wins.length; j++) {
                var candidate = wins[j].start + w * WEEK_SECONDS;
                if (candidate >= nowSec && (best === null || candidate < best)) { best = candidate; }
            }
        }
        // "Just closed" (after-hours) if the most recent close was within the window.
        var justClosed = false;
        for (var k = 0; k < wins.length; k++) {
            var recentClose = wins[k].end;
            if (nowSec >= recentClose && nowSec - recentClose <= PRE_AFTER_WINDOW_SEC) { justClosed = true; }
            var recentCloseLastWeek = wins[k].end - WEEK_SECONDS;
            if (nowSec >= recentCloseLastWeek && nowSec - recentCloseLastWeek <= PRE_AFTER_WINDOW_SEC) { justClosed = true; }
        }
        return { isOpen: false, closesIn: null, opensIn: best - nowSec, justClosed: justClosed };
    }

    function render() {
        var now = new Date();
        var openMarkets = [];

        MARKETS.forEach(function (market) {
            var el = document.querySelector('[data-mcc="' + market.id + '"]');
            if (!el) { return; }
            var timeEl = el.querySelector('[data-role="time"]');
            var statusEl = el.querySelector('[data-role="status"]');
            var countdownEl = el.querySelector('[data-role="countdown"]');
            var metaEl = el.querySelector('[data-role="meta"]');

            timeEl.textContent = timeFmts[market.id].format(now);

            var nowSec = zonedSeconds(now, market.id);
            var res = evaluate(market, nowSec);
            var wasOpen = state[market.id] ? state[market.id].isOpen : null;
            state[market.id] = res;
            if (res.isOpen) { openMarkets.push(market); }

            // wasOpen === false (not null) excludes the very first render on
            // page load — otherwise every session already open when the
            // page loads would fire a spurious "just opened" notification.
            if (res.isOpen && wasOpen === false && window.MSSNotify) {
                window.MSSNotify.notify('Market Open — ' + market.label, market.label + ' session is now open.', 'mss-session-' + market.id);
            }

            if (res.isOpen) {
                statusEl.textContent = 'OPEN NOW';
                statusEl.className = 'clock__badge clock__badge--open';
                el.classList.add('clock--open');
                countdownEl.textContent = 'Closes in ' + fmtDuration(res.closesIn);
            } else if (res.justClosed) {
                statusEl.textContent = 'AFTER HOURS';
                statusEl.className = 'clock__badge clock__badge--after';
                el.classList.remove('clock--open');
                countdownEl.textContent = 'Opens in ' + fmtDuration(res.opensIn);
            } else if (res.opensIn <= PRE_AFTER_WINDOW_SEC) {
                statusEl.textContent = 'PRE-MARKET';
                statusEl.className = 'clock__badge clock__badge--pre';
                el.classList.remove('clock--open');
                countdownEl.textContent = 'Opens in ' + fmtDuration(res.opensIn);
            } else {
                statusEl.textContent = 'CLOSED';
                statusEl.className = 'clock__badge clock__badge--closed';
                el.classList.remove('clock--open');
                countdownEl.textContent = 'Opens in ' + fmtDuration(res.opensIn);
            }

            if (metaEl) {
                if (res.isOpen) {
                    var tier = market.liquidityTier;
                    var score = tier * 25; // base: 25/50/75 for low/medium/high tier
                    metaEl.textContent = 'Liquidity: ' + LIQUIDITY_LABELS[tier] + ' · Score: ' + score;
                } else {
                    metaEl.textContent = 'Liquidity: Closed · Score: 0';
                }
            }
        });

        // Generalized overlap: any 2+ sessions open together, not just
        // London+New York — Tokyo+Sydney also genuinely overlaps daily.
        var overlapBox = document.getElementById('mcc-overlap');
        var overlapState = document.getElementById('mcc-overlap-state');
        var overlapLabel = document.getElementById('mcc-overlap-label');
        var overlapActive = openMarkets.length >= 2;
        if (overlapBox && overlapState && overlapLabel) {
            overlapBox.classList.toggle('is-active', overlapActive);
            overlapState.textContent = overlapActive ? 'ACTIVE NOW' : 'NOT ACTIVE';
            if (overlapActive) {
                var names = openMarkets.map(function (m) { return m.label; }).join(' + ');
                var combinedTier = Math.min(3, openMarkets.reduce(function (sum, m) { return sum + m.liquidityTier; }, 0));
                overlapLabel.textContent = names + ' overlap — ' + LIQUIDITY_LABELS[combinedTier] + ' combined liquidity, the strongest window to trade';
            } else {
                overlapLabel.textContent = 'No session overlap right now';
            }
        }

        // Update each open market's score with the overlap bonus now that
        // every session's open/closed state for this tick is known.
        if (overlapActive) {
            openMarkets.forEach(function (market) {
                var el = document.querySelector('[data-mcc="' + market.id + '"] [data-role="meta"]');
                if (el) {
                    var score = Math.min(100, market.liquidityTier * 25 + 15);
                    el.textContent = 'Liquidity: ' + LIQUIDITY_LABELS[market.liquidityTier] + ' (overlap) · Score: ' + score;
                }
            });
        }
    }

    render();
    setInterval(render, 1000);
}());

// ── Live Market Overview (Section 3) + Watchlist (Section 12) ────────
(function () {
    'use strict';
    var tbody   = document.getElementById('mo-tbody');
    var status  = document.getElementById('mo-status');
    var search  = document.getElementById('mo-search');
    var tabs    = document.querySelectorAll('.mo-tab');
    var wlBody  = document.getElementById('wl-tbody');
    var wlStatus = document.getElementById('wl-status');
    if (!tbody) { return; }

    var CONFIGURED_LABELS = <?= json_encode(array_column($markets, 'label')) ?>;
    var CSRF = <?= json_encode($csrf) ?>;

    var rows = [];
    var activeCategory = 'all';
    var sortKey = null;
    var sortDir = 1;

    function fmtPrice(v) {
        if (v === null || v === undefined) { return '—'; }
        var abs = Math.abs(v);
        var decimals = abs >= 100 ? 2 : (abs >= 1 ? 4 : 5);
        return v.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }
    function fmtChange(v) {
        if (v === null || v === undefined) { return '—'; }
        var s = (v > 0 ? '+' : '') + v.toFixed(2) + '%';
        return s;
    }
    function trendLabel(t) {
        if (t === 'bullish') { return '▲ Bullish'; }
        if (t === 'bearish') { return '▼ Bearish'; }
        if (t === 'neutral') { return '● Neutral'; }
        return '—';
    }

    function applyFilters() {
        var q = (search.value || '').trim().toLowerCase();
        var filtered = rows.filter(function (r) {
            if (activeCategory !== 'all' && r.category !== activeCategory) { return false; }
            if (q && r.label.toLowerCase().indexOf(q) === -1) { return false; }
            return true;
        });
        if (sortKey) {
            filtered.sort(function (a, b) {
                var av = a[sortKey], bv = b[sortKey];
                if (av === null || av === undefined) { av = sortKey === 'label' ? '' : -Infinity; }
                if (bv === null || bv === undefined) { bv = sortKey === 'label' ? '' : -Infinity; }
                if (typeof av === 'string') { return av.localeCompare(bv) * sortDir; }
                return (av - bv) * sortDir;
            });
        }
        renderRows(filtered);
    }

    function renderRows(list) {
        if (!list.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="empty">No instruments match.</td></tr>';
            return;
        }
        tbody.innerHTML = list.map(function (r) {
            var changeCls = r.change_pct > 0 ? 'u-mo-up' : (r.change_pct < 0 ? 'u-mo-down' : '');
            var trendCls  = 'u-mo-trend--' + (r.trend || 'none');
            var volCls    = 'badge--' + (r.volatility === 'high' ? 'high' : (r.volatility === 'low' ? 'low' : 'medium'));
            var sigCls    = r.signal === 'BUY' ? 'tr--buy' : (r.signal === 'SELL' ? 'tr--sell' : '');
            return '<tr class="' + sigCls + '">'
                + '<td><button type="button" class="wl-star' + (r.watching ? ' is-active' : '') + '" data-symbol="' + r.symbol + '" aria-pressed="' + (r.watching ? 'true' : 'false') + '" aria-label="' + (r.watching ? 'Remove ' + r.label + ' from watchlist' : 'Add ' + r.label + ' to watchlist') + '" title="' + (r.watching ? 'Remove from watchlist' : 'Add to watchlist') + '">' + (r.watching ? '★' : '☆') + '</button></td>'
                + '<td><span class="badge badge--market">' + r.label + '</span></td>'
                + '<td class="td--mono">' + fmtPrice(r.price) + '</td>'
                + '<td class="td--mono ' + changeCls + '">' + fmtChange(r.change_pct) + '</td>'
                + '<td class="' + trendCls + '">' + trendLabel(r.trend) + '</td>'
                + '<td>' + (r.volatility ? '<span class="badge ' + volCls + '">' + r.volatility.toUpperCase() + '</span>' : '—') + '</td>'
                + '<td class="td--action">' + (r.signal ? r.signal + (r.signal_official ? '' : ' <span class="mo-preview-dot" title="Preview: same signal engine, computed live — not persisted, not alerted, not subject to the daily cap"></span>') : '—') + '</td>'
                + '<td class="td--conf">' + (r.confidence !== null && r.confidence !== undefined ? r.confidence + '%' : '—') + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderWatchlist() {
        if (!wlBody) { return; }
        var pinned = rows.filter(function (r) { return r.watching; });
        wlStatus.textContent = pinned.length + ' pinned';
        if (!pinned.length) {
            wlBody.innerHTML = '<tr><td colspan="6" class="empty">Nothing pinned yet — click ☆ next to any instrument in Live Market Overview above.</td></tr>';
            return;
        }
        wlBody.innerHTML = pinned.map(function (r) {
            var changeCls = r.change_pct > 0 ? 'u-mo-up' : (r.change_pct < 0 ? 'u-mo-down' : '');
            var trendCls  = 'u-mo-trend--' + (r.trend || 'none');
            var scannerEnabled = CONFIGURED_LABELS.indexOf(r.label) !== -1;
            var scannerLink = scannerEnabled
                ? '<a href="<?= e(url('dashboard')) ?>#scanner" class="wl-action">Scanner</a>'
                : '<span class="wl-action wl-action--disabled" title="Scanner only covers ' + CONFIGURED_LABELS.join(', ') + '">Scanner</span>';
            return '<tr>'
                + '<td><span class="badge badge--market">' + r.label + '</span></td>'
                + '<td class="td--mono">' + fmtPrice(r.price) + '</td>'
                + '<td class="td--mono ' + changeCls + '">' + fmtChange(r.change_pct) + '</td>'
                + '<td class="' + trendCls + '">' + trendLabel(r.trend) + '</td>'
                + '<td class="wl-actions">'
                    + '<a href="<?= e(url('market-intelligence')) ?>" class="wl-action">Analyze</a>'
                    + '<a href="<?= e(url('vision')) ?>?pair=' + encodeURIComponent(r.label) + '" class="wl-action">AI Vision</a>'
                    + scannerLink
                + '</td>'
                + '<td><button type="button" class="wl-star is-active" data-symbol="' + r.symbol + '" aria-pressed="true" aria-label="Remove ' + r.label + ' from watchlist" title="Remove from watchlist">★</button></td>'
                + '</tr>';
        }).join('');
    }

    function toggleWatch(symbol, btn) {
        btn.disabled = true;
        var body = new URLSearchParams();
        body.set('_csrf', CSRF);
        body.set('symbol', symbol);
        fetch('<?= e(url('watchlist/toggle')) ?>', { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
            .then(function (res) {
                if (!res.ok) { throw new Error(res.data.error || 'Request failed'); }
                rows.forEach(function (r) { if (r.symbol === symbol) { r.watching = res.data.watching; } });
                applyFilters();
                renderWatchlist();
            })
            .catch(function () { /* leave state unchanged on failure */ })
            .finally(function () { btn.disabled = false; });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.wl-star');
        if (!btn) { return; }
        toggleWatch(btn.getAttribute('data-symbol'), btn);
    });

    var HIGH_CONFIDENCE_THRESHOLD = 75;
    var notifiedSignals = {}; // symbol -> last-notified signal+confidence, so an unchanged signal doesn't re-fire every 2-minute poll

    function checkHighConfidenceSignals(instruments) {
        if (!window.MSSNotify) return;
        instruments.forEach(function (r) {
            if (!r.signal_official || (r.signal !== 'BUY' && r.signal !== 'SELL') || r.confidence < HIGH_CONFIDENCE_THRESHOLD) {
                return;
            }
            var key = r.symbol + ':' + r.signal + ':' + r.confidence;
            if (notifiedSignals[r.symbol] === key) { return; }
            notifiedSignals[r.symbol] = key;
            window.MSSNotify.notify(
                'High Confidence Signal — ' + r.label,
                r.signal + ' at ' + r.confidence + '% confidence.',
                'mss-signal-' + r.symbol
            );
        });
    }

    function load() {
        fetch('<?= e(url('api/market-overview/quotes')) ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
            .then(function (data) {
                rows = data.instruments || [];
                var unavailable = rows.filter(function (r) { return !r.available; }).length;
                status.textContent = rows.length + ' instruments' + (unavailable ? ' · ' + unavailable + ' unavailable' : '') + ' · updated ' + new Date().toLocaleTimeString();
                applyFilters();
                renderWatchlist();
                checkHighConfidenceSignals(rows);
            })
            .catch(function (e) {
                status.textContent = 'Live data unavailable';
                tbody.innerHTML = '<tr><td colspan="8" class="empty">Could not load live market data (' + e.message + ').</td></tr>';
            });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('is-active'); t.setAttribute('aria-pressed', 'false'); });
            tab.classList.add('is-active');
            tab.setAttribute('aria-pressed', 'true');
            activeCategory = tab.getAttribute('data-category');
            applyFilters();
        });
    });
    search.addEventListener('input', applyFilters);
    document.querySelectorAll('.mo-sortable').forEach(function (th) {
        th.addEventListener('click', function () {
            var key = th.getAttribute('data-sort');
            sortDir = (sortKey === key) ? -sortDir : 1;
            sortKey = key;
            applyFilters();
        });
    });

    load();
    setInterval(load, 120000); // matches the server's 5-min response cache closely enough without over-polling
}());

// ── Risk calculator ──────────────────────────────────────────────
function calcRisk() {
    var bal   = parseFloat(document.getElementById('rc-bal').value)  || 0;
    var pct   = parseFloat(document.getElementById('rc-pct').value)  || 1;
    var stop  = parseFloat(document.getElementById('rc-stop').value) || 0;
    var pair  = document.getElementById('rc-pair').value;
    var pip   = {'EUR/USD':10,'GBP/USD':10,'USD/JPY':9.10}[pair] || 10;
    if (!bal || !stop) { ['rc-risk-amt','rc-lots','rc-mini','rc-micro','rc-2r'].forEach(function(id){ document.getElementById(id).textContent='—'; }); return; }
    var risk  = bal * (pct / 100);
    var lots  = risk / (stop * pip);
    document.getElementById('rc-risk-amt').textContent = '$' + risk.toFixed(2);
    document.getElementById('rc-lots').textContent     = lots.toFixed(4);
    document.getElementById('rc-mini').textContent     = (lots * 10).toFixed(3);
    document.getElementById('rc-micro').textContent    = (lots * 100).toFixed(2);
    document.getElementById('rc-2r').textContent       = '$' + (risk * 2).toFixed(2);
}
calcRisk();

// ── AI Vision quick dropzone (drag / paste / browse) ──────────────
(function () {
    'use strict';
    var drop    = document.getElementById('dvh-drop');
    var input   = document.getElementById('dvh-input');
    var preview = document.getElementById('dvh-preview');
    var fname   = document.getElementById('dvh-filename');
    var submit  = document.getElementById('dvh-submit');
    if (!drop || !input) { return; }

    function assign(file) {
        if (!file || file.type.indexOf('image/') !== 0) { return; }
        var dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        preview.src = URL.createObjectURL(file);
        drop.classList.add('is-filled');
        fname.textContent = file.name || 'Pasted image';
        submit.disabled = false;
    }

    drop.addEventListener('click', function (e) { if (e.target !== input) { input.click(); } });
    drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
    input.addEventListener('change', function () { if (input.files && input.files[0]) { assign(input.files[0]); } });

    ['dragover', 'dragleave', 'drop'].forEach(function (evt) {
        drop.addEventListener(evt, function (e) {
            e.preventDefault();
            drop.classList.toggle('is-dragover', evt === 'dragover');
            if (evt === 'drop' && e.dataTransfer && e.dataTransfer.files.length) { assign(e.dataTransfer.files[0]); }
        });
    });

    document.addEventListener('paste', function (e) {
        if (document.activeElement !== drop && !drop.contains(document.activeElement)) { return; }
        var items = (e.clipboardData || window.clipboardData).items;
        for (var i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image/') === 0) { assign(items[i].getAsFile()); break; }
        }
    });
}());

// ── Section 7: today's calendar countdown ─────────────────────────
(function () {
    'use strict';
    var cells = document.querySelectorAll('.cal-countdown');
    if (!cells.length) { return; }

    function fmt(totalSeconds) {
        var past = totalSeconds < 0;
        var abs = Math.abs(totalSeconds);
        var h = Math.floor(abs / 3600);
        var m = Math.floor((abs % 3600) / 60);
        var label = (h < 10 ? '0' : '') + h + 'h ' + (m < 10 ? '0' : '') + m + 'm';
        return past ? label + ' ago' : 'in ' + label;
    }

    function tick() {
        var now = Date.now();
        cells.forEach(function (cell) {
            var iso = cell.getAttribute('data-scheduled');
            if (!iso) { cell.textContent = '—'; return; }
            var target = new Date(iso).getTime();
            if (isNaN(target)) { cell.textContent = '—'; return; }
            cell.textContent = fmt(Math.round((target - now) / 1000));
        });
    }
    tick();
    setInterval(tick, 30000);
}());

// ── Section 8: news click-to-explain ───────────────────────────────
(function () {
    'use strict';
    document.querySelectorAll('.news__explain-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var li = btn.closest('.news__item');
            var target = li ? li.querySelector('[data-role="explanation"]') : null;
            if (!target) { return; }

            btn.disabled = true;
            var originalText = btn.textContent;
            btn.textContent = 'Explaining…';

            var body = new URLSearchParams();
            body.set('_csrf', <?= json_encode($csrf) ?>);
            body.set('news_id', btn.getAttribute('data-news-id'));

            fetch('<?= e(url('news/explain')) ?>', { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
                .then(function (res) {
                    if (!res.ok) { throw new Error(res.data.error || 'Request failed'); }
                    target.textContent = res.data.explanation;
                    target.hidden = false;
                    btn.style.display = 'none';
                })
                .catch(function (e) {
                    btn.disabled = false;
                    btn.textContent = originalText;
                    target.textContent = 'Could not load an explanation (' + e.message + ').';
                    target.hidden = false;
                });
        });
    });
}());

// ── Currency heatmap tile click-through modal ──────────────────────
(function () {
    'use strict';
    var modal = document.getElementById('ccy-modal');
    if (!modal) { return; }
    var titleEl = document.getElementById('ccy-modal-title');
    var bodyEl  = modal.querySelector('[data-role="body"]');

    function fmtDate(iso) {
        if (!iso) { return ''; }
        try { return new Date(iso.replace(' ', 'T')).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }); }
        catch (e) { return iso; }
    }

    function trendLabel(t) {
        return { rising: '▲ Rising', falling: '▼ Falling', flat: '● Flat' }[t] || 'Not enough history yet';
    }

    // data.news[].title comes from external RSS/news-API sources, and
    // data.events[].title is directly admin-submitted (CalendarController::
    // store()) with no server-side sanitization — both were previously
    // concatenated straight into innerHTML, a real stored-XSS path (a
    // malicious feed headline or a planted calendar-event title would
    // execute in every admin's session that opened this modal). Every
    // dynamic field below is escaped before it touches the html string,
    // including ones that happen to be server-constrained today (ai_opinion,
    // impact enum) — defense in depth against that constraint changing later.
    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    function render(data) {
        titleEl.textContent = data.currency + ' — Currency Detail';
        var html = '';

        html += '<div class="ccy-modal__row"><span class="ccy-modal__lbl">Trend</span><span>' + trendLabel(data.trend) + '</span></div>';

        if (data.volatility) {
            html += '<div class="ccy-modal__row"><span class="ccy-modal__lbl">Volatility (avg ATR%)</span><span>' + data.volatility.atr_percent.toFixed(4) + '% across ' + escapeHtml(data.volatility.sample_pairs.join(', ')) + '</span></div>';
        } else {
            html += '<div class="ccy-modal__row"><span class="ccy-modal__lbl">Volatility</span><span class="u-muted">Not available</span></div>';
        }

        html += '<div class="ccy-modal__row"><span class="ccy-modal__lbl">AI Opinion</span><span>' + (data.ai_opinion ? escapeHtml(data.ai_opinion) : '<span class="u-muted">No AI opinion specific to this currency yet.</span>') + '</span></div>';

        html += '<p class="ccy-modal__section-lbl">Related Pairs</p>';
        html += data.related_pairs.length
            ? '<div class="ccy-modal__pairs">' + data.related_pairs.map(function (p) { return '<span class="badge badge--market">' + escapeHtml(p) + '</span>'; }).join('') + '</div>'
            : '<p class="empty">None configured.</p>';

        html += '<p class="ccy-modal__section-lbl">Upcoming Economic Events</p>';
        html += data.events.length
            ? data.events.map(function (e) { return '<p class="ccy-modal__line">' + escapeHtml(fmtDate(e.scheduled_at)) + ' — ' + escapeHtml(e.title) + ' <span class="badge badge--' + escapeHtml(e.impact.toLowerCase()) + '">' + escapeHtml(e.impact.toUpperCase()) + '</span></p>'; }).join('')
            : '<p class="empty">None scheduled.</p>';

        html += '<p class="ccy-modal__section-lbl">Related News</p>';
        html += data.news.length
            ? data.news.map(function (n) { return '<p class="ccy-modal__line">' + escapeHtml(n.title) + '</p>'; }).join('')
            : '<p class="empty">No recent headlines mention this currency.</p>';

        bodyEl.innerHTML = html;
    }

    function open(currency) {
        titleEl.textContent = currency + ' — Currency Detail';
        bodyEl.innerHTML = '<p class="empty">Loading…</p>';
        modal.hidden = false;
        document.body.style.overflow = 'hidden';

        fetch('<?= e(url('api/currency-detail')) ?>?currency=' + encodeURIComponent(currency), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
            .then(function (res) {
                if (!res.ok) { throw new Error(res.data.error || 'Request failed'); }
                render(res.data);
            })
            .catch(function (e) {
                bodyEl.innerHTML = '<p class="empty">Could not load details (' + e.message + ').</p>';
            });
    }

    function close() {
        modal.hidden = true;
        document.body.style.overflow = '';
    }

    document.querySelectorAll('.css-tile').forEach(function (tile) {
        tile.addEventListener('click', function () { open(tile.getAttribute('data-currency')); });
    });
    modal.querySelectorAll('[data-role="close"]').forEach(function (el) {
        el.addEventListener('click', close);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) { close(); }
    });
}());
</script>
