<div class="page-header">
    <h2 class="page-title">AI Learning Engine</h2>
    <p class="page-sub">Recommendations improve based on historical evidence rather than static rules alone. Educational only — no real trading.</p>
    <p class="card__sub" style="margin-top:6px">
        <a href="<?= e(url('learning/accuracy')) ?>">Institutional Accuracy Dashboard →</a>
        &nbsp;·&nbsp;
        <a href="<?= e(url('learning/replay')) ?>">AI Decision Replay →</a>
        <?php if (is_admin()): ?>
        &nbsp;·&nbsp;
        <a href="<?= e(url('learning/weight-proposals')) ?>">AI Weight Optimizer (admin) →</a>
        <?php endif; ?>
    </p>
</div>

<?php if ($flashOk): ?>
<div class="flash success"><?= e($flashOk) ?></div>
<?php endif; ?>

<?php $o = $overview; ?>

<!-- ══ LEARN FROM HISTORICAL TRADES ══════════════════════════════ -->
<div class="card">
    <div class="card__hd">
        <h3 class="card__title">Learn From Historical Trades</h3>
        <span class="card__sub"><a href="<?= e(url('trade-journal')) ?>">Full Trade Journal analytics →</a></span>
    </div>
    <div class="perf-grid">
        <div class="perf-stat">
            <span class="perf-stat__num"><?= (int) $o->tradeJournalStats['closed_count'] ?></span>
            <span class="perf-stat__lbl">Closed trades logged</span>
        </div>
        <div class="perf-stat perf-stat--green">
            <span class="perf-stat__num"><?= $o->tradeJournalStats['closed_count'] > 0 ? $o->tradeJournalStats['win_rate'] . '%' : '—' ?></span>
            <span class="perf-stat__lbl">Win rate</span>
        </div>
        <div class="perf-stat <?= $o->tradeJournalStats['total_profit_loss'] >= 0 ? 'perf-stat--green' : 'perf-stat--red' ?>">
            <span class="perf-stat__num"><?= $o->tradeJournalStats['total_profit_loss'] ?></span>
            <span class="perf-stat__lbl">Total P/L</span>
        </div>
    </div>
</div>

<!-- ══ LEARNING DASHBOARD METRICS ═════════════════════════════════ -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Learning Dashboard</h3>
        <span class="card__sub">Real patterns from your own trading history</span>
    </div>
    <div class="bt-stats-grid">
        <?php $cm = $coachMetrics; ?>
        <div class="stat-block">
            <span class="stat-block__val"><?= $cm['most_profitable_session'] !== null ? e(ucfirst(str_replace('_', ' ', (string) $cm['most_profitable_session']['label']))) : '—' ?></span>
            <span class="stat-block__lbl">Most Profitable Session</span>
        </div>
        <div class="stat-block">
            <span class="stat-block__val"><?= $cm['most_profitable_pair'] !== null ? e((string) $cm['most_profitable_pair']['label']) : '—' ?></span>
            <span class="stat-block__lbl">Most Profitable Pair</span>
        </div>
        <div class="stat-block <?= $cm['weakest_habit'] !== null ? 'stat-block--red' : '' ?>">
            <span class="stat-block__val"><?= $cm['weakest_habit'] !== null ? (int) $cm['weakest_habit']['count'] : '—' ?></span>
            <span class="stat-block__lbl">Weakest Habit<?= $cm['weakest_habit'] !== null ? ' — ' . e((string) $cm['weakest_habit']['label']) : '' ?></span>
        </div>
        <div class="stat-block">
            <span class="stat-block__val"><?= $cm['avg_holding_period_hours'] !== null ? round($cm['avg_holding_period_hours'], 1) . 'h' : '—' ?></span>
            <span class="stat-block__lbl">Avg Holding Period</span>
        </div>
        <div class="stat-block <?= $cm['avg_profit_loss'] >= 0 ? 'stat-block--green' : 'stat-block--red' ?>">
            <span class="stat-block__val">$<?= number_format($cm['avg_profit_loss'], 2) ?></span>
            <span class="stat-block__lbl">Avg P/L per Trade <span class="u-muted">($, not R-multiple — see note below)</span></span>
        </div>
        <div class="stat-block">
            <span class="stat-block__val"><?= $cm['ai_recommendation_adherence'] !== null ? $cm['ai_recommendation_adherence']['percent'] . '%' : '—' ?></span>
            <span class="stat-block__lbl">AI Recommendation Adherence <span class="u-muted">(approximate)</span></span>
        </div>
    </div>

    <?php if ($cm['improvement_trend'] !== null): $it = $cm['improvement_trend']; $delta = $it['recent_win_rate'] - $it['earlier_win_rate']; ?>
    <p class="card__sub" style="margin-top:14px">
        <strong>Improvement trend:</strong>
        <?= $it['earlier_win_rate'] ?>% win rate on your first <?= $it['earlier_count'] ?> trades &rarr;
        <?= $it['recent_win_rate'] ?>% on your most recent <?= $it['recent_count'] ?>
        (<span class="<?= $delta >= 0 ? 'u-mo-up' : 'u-mo-down' ?>"><?= $delta >= 0 ? '+' : '' ?><?= round($delta, 1) ?> pts</span>).
    </p>
    <?php else: ?>
    <p class="card__sub" style="margin-top:14px">Improvement trend needs at least <?= 2 * 8 ?> closed trades to compare an earlier period against a recent one — keep logging.</p>
    <?php endif; ?>

    <p class="card__sub" style="margin-top:8px">"Win rate by timeframe" is not shown — this app's Trade Journal does not currently capture a timeframe field per entry, and this dashboard will not estimate one. "AI Recommendation Adherence" is a best-effort match by pair and nearest timestamp (no direct link exists between a logged trade and a specific AI decision record) — treat it as indicative, not exact.</p>
</div>

<!-- ══ PERSONAL INSIGHTS & LESSONS ═════════════════════════════════ -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">AI Lessons</h3>
        <span class="card__sub"><?= count($personalInsights) ?> insight(s) from your history</span>
    </div>
    <?php if ($personalInsights): ?>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Lesson</th><th>Evidence</th></tr></thead>
            <tbody>
            <?php foreach ($personalInsights as $i): ?>
                <tr><td><?= e($i['lesson']) ?></td><td class="u-muted"><?= e($i['evidence']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p class="empty">Not enough closed trades yet for a reliable pattern to emerge — every lesson here requires real, sufficiently-sized evidence, not a guess.</p>
    <?php endif; ?>
</div>

<!-- ══ ACHIEVEMENTS ═══════════════════════════════════════════════ -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Achievements</h3>
        <span class="card__sub"><?= count(array_filter($achievements, static fn(array $a): bool => $a['unlocked'])) ?>/<?= count($achievements) ?> unlocked</span>
    </div>
    <div class="bt-stats-grid">
        <?php foreach ($achievements as $a): ?>
        <div class="stat-block <?= $a['unlocked'] ? 'stat-block--green' : '' ?>" style="<?= $a['unlocked'] ? '' : 'opacity:0.55' ?>">
            <span class="stat-block__val"><?= $a['unlocked'] ? '🏅' : round($a['progress'] * 100) . '%' ?></span>
            <span class="stat-block__lbl"><?= e($a['label']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="card__sub" style="margin-top:10px">Milestones for consistent process, not risk-taking — nothing here rewards position size, leverage, or trade frequency.</p>
</div>

<!-- ══ CONFIDENCE CALIBRATION ════════════════════════════════════ -->
<div class="card bt-ai-card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Confidence Calibration</h3>
        <span class="card__sub">Stated confidence vs. actual outcome, from App\Models\SignalEvent</span>
    </div>

    <?php $cc = $o->confidenceCalibration; ?>
    <div class="bt-stats-grid">
        <div class="stat-block <?= $cc['overall_factor'] > 55 ? 'stat-block--green' : ($cc['overall_factor'] < 45 ? 'stat-block--red' : '') ?>">
            <span class="stat-block__val"><?= $cc['overall_factor'] ?></span>
            <span class="stat-block__lbl">Calibration factor (live)</span>
        </div>
        <div class="stat-block">
            <span class="stat-block__val"><?= $cc['avg_calibration_error'] >= 0 ? '+' : '' ?><?= $cc['avg_calibration_error'] ?>pts</span>
            <span class="stat-block__lbl">Avg calibration error</span>
        </div>
        <div class="stat-block">
            <span class="stat-block__val"><?= (int) $cc['sample_size'] ?></span>
            <span class="stat-block__lbl">Signals with a recorded outcome</span>
        </div>
        <div class="stat-block <?= $cc['well_calibrated'] ? 'stat-block--green' : '' ?>">
            <span class="stat-block__val"><?= $cc['well_calibrated'] ? 'Yes' : 'No' ?></span>
            <span class="stat-block__lbl">Well calibrated?</span>
        </div>
    </div>

    <?php if (!empty($cc['by_band'])): ?>
    <div class="tbl-wrap" style="margin-top:14px">
        <table class="tbl">
            <thead><tr><th>Confidence band</th><th>Signals</th><th>Actual win rate</th><th>Assumed</th><th>Error</th></tr></thead>
            <tbody>
            <?php foreach ($cc['by_band'] as $b): ?>
            <tr>
                <td><?= e($b['band']) ?></td>
                <td><?= (int) $b['sample_size'] ?></td>
                <td><?= $b['actual_win_rate'] ?>%</td>
                <td><?= $b['assumed_confidence'] ?>%</td>
                <td><?= $b['calibration_error'] >= 0 ? '+' : '' ?><?= $b['calibration_error'] ?>pts</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p class="empty" style="margin-top:10px">No signals with a recorded outcome yet — mark some outcomes on the dashboard first.</p>
    <?php endif; ?>

    <?php if ($o->rememberedCalibration !== null): ?>
    <p class="stat-block__lbl" style="margin-top:12px">
        AI memory last updated <?= e(fmt_datetime($o->rememberedCalibration['computed_at'])) ?>
        (factor <?= $o->rememberedCalibration['value']['overall_factor'] ?? '—' ?>, <?= (int) $o->rememberedCalibration['sample_size'] ?> signal(s)).
        This is what App\Decision\DecisionEngine actually reads — the live figures above may have moved on since.
    </p>
    <?php else: ?>
    <p class="stat-block__lbl" style="margin-top:12px">No AI memory snapshot yet — click below to teach it from the current evidence.</p>
    <?php endif; ?>

    <form method="post" action="<?= e(url('learning/rebuild-calibration')) ?>" style="margin-top:10px">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button type="submit" class="btn btn--scan">Update AI Memory From Current Evidence</button>
    </form>
    <p class="stat-block__lbl" style="margin-top:8px">
        This factor only affects live recommendations if both <code>FEATURE_AI_LEARNING</code> is on
        and <code>SCORE_WEIGHT_CALIBRATION</code> is explicitly set above its default of 0 in config/.env.
    </p>
</div>

<!-- ══ STRATEGY PERFORMANCE TRACKING ═════════════════════════════ -->
<div class="db-row db-row--bottom" style="margin-top:20px">
    <div class="card">
        <div class="card__hd">
            <h3 class="card__title">Strategy Performance Tracking</h3>
            <span class="card__sub"><a href="<?= e(url('backtest')) ?>">Run a backtest →</a></span>
        </div>
        <?php if (!empty($o->strategyStats)): ?>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Pair</th><th>Timeframe</th><th>Strategy</th><th>Period</th><th>Win rate</th><th>Avg R</th><th>Max DD (R)</th><th>Trades</th></tr></thead>
                <tbody>
                <?php foreach ($o->strategyStats as $s): ?>
                <tr>
                    <td><span class="badge badge--market"><?= e((string) $s['market_label']) ?></span></td>
                    <td class="td--muted"><?= e((string) $s['timeframe']) ?></td>
                    <td><?= e((string) $s['strategy_tag']) ?></td>
                    <td class="td--muted"><?= e((string) $s['period_start']) ?> to <?= e((string) $s['period_end']) ?></td>
                    <td><?= $s['win_rate'] ?>%</td>
                    <td><?= $s['avg_r'] ?></td>
                    <td><?= $s['max_drawdown_r'] ?></td>
                    <td><?= (int) $s['sample_size'] ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="empty">No backtests recorded yet — run one from the Backtest page.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__hd">
            <h3 class="card__title">Pattern Success Tracking</h3>
        </div>
        <?php if (!empty($o->patternStats)): ?>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Setup pattern</th><th>Occurrences</th><th>Win rate</th><th>Avg P/L</th></tr></thead>
                <tbody>
                <?php foreach ($o->patternStats as $p): ?>
                <tr>
                    <td><?= e(\App\Learning\SetupPatternCatalog::label((string) $p['pattern_name'])) ?></td>
                    <td><?= (int) $p['occurrences'] ?></td>
                    <td><?= $p['win_rate'] ?>%</td>
                    <td><?= $p['avg_r'] ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="empty">No pattern statistics yet — tag a "setup pattern" when logging trades, then rebuild below.</p>
        <?php endif; ?>

        <form method="post" action="<?= e(url('learning/rebuild-patterns')) ?>" style="margin-top:10px">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="btn btn--scan">Rebuild From Trade Journal</button>
        </form>
    </div>
</div>

<!-- ══ RECOMMENDATION HISTORY ═════════════════════════════════════ -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Recommendation History</h3>
        <span class="card__sub">Historical AI decisions, cross-referenced against any that were logged to your Trade Journal</span>
    </div>
    <?php if (!empty($o->recommendationHistory)): ?>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Time</th><th>Pair</th><th>Decision</th><th>Conf.</th><th>Logged?</th><th>Outcome</th><th>P/L</th></tr></thead>
            <tbody>
            <?php foreach ($o->recommendationHistory as $r):
                $dec = strtoupper($r['decision']);
                $cls = match ($dec) { 'BUY' => 'tr--buy', 'SELL' => 'tr--sell', 'WAIT' => 'tr--wait', default => '' };
            ?>
            <tr class="<?= e($cls) ?>">
                <td class="td--mono td--muted td--nowrap"><?= e(fmt_datetime($r['created_at'])) ?></td>
                <td><span class="badge badge--market"><?= e($r['market_label']) ?></span></td>
                <td class="td--action"><?= e($dec) ?></td>
                <td class="td--conf"><?= $r['confidence'] ?>%</td>
                <td><?= $r['logged'] ? 'Yes' : '—' ?></td>
                <td>
                    <?php if ($r['outcome'] !== null): ?>
                        <?php $ocBadge = match ($r['outcome']) { 'win' => 'badge--outcome-win', 'loss' => 'badge--outcome-loss', 'breakeven' => 'badge--outcome-be', default => 'badge--outcome-skip' }; ?>
                        <span class="badge <?= e($ocBadge) ?>"><?= e(strtoupper($r['outcome'])) ?></span>
                    <?php else: ?>
                        <span class="td--muted">Unknown</span>
                    <?php endif; ?>
                </td>
                <td class="td--mono"><?= $r['profit_loss'] !== null ? $r['profit_loss'] : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="stat-block__lbl" style="margin-top:8px">Only decisions logged to the Trade Journal (via "Log to Journal") show a real outcome — everything else is unlogged, not necessarily wrong.</p>
    <?php else: ?>
    <p class="empty">No AI decisions recorded yet — the scanner produces one every cron cycle.</p>
    <?php endif; ?>
</div>

<p class="stat-block__lbl" style="margin-top:16px">Generated <?= e($o->generatedAt->format('Y-m-d H:i:s')) ?> EAT.</p>
