<div class="page-header">
    <h2 class="page-title">Institutional Accuracy Dashboard</h2>
    <p class="page-sub">How well each evidence source, and the consensus itself, has actually performed against resolved outcomes — not asserted. <a href="<?= e(url('learning')) ?>">← Back to AI Learning</a></p>
</div>

<!-- ══ OVERALL ═══════════════════════════════════════════════════ -->
<div class="card">
    <div class="card__hd">
        <h3 class="card__title">Overall Consensus Accuracy</h3>
        <span class="card__sub">Every resolved BUY/SELL decision, last 90 days</span>
    </div>
    <div class="bt-stats-grid">
        <div class="stat-block">
            <span class="stat-block__val"><?= (int) $overall['total'] ?></span>
            <span class="stat-block__lbl">Resolved decisions</span>
        </div>
        <div class="stat-block stat-block--green">
            <span class="stat-block__val"><?= (int) $overall['wins'] ?></span>
            <span class="stat-block__lbl">Wins</span>
        </div>
        <div class="stat-block stat-block--red">
            <span class="stat-block__val"><?= (int) $overall['losses'] ?></span>
            <span class="stat-block__lbl">Losses</span>
        </div>
        <div class="stat-block">
            <span class="stat-block__val"><?= (int) $overall['timeouts'] ?></span>
            <span class="stat-block__lbl">Timeouts <span class="u-muted">(never hit target or stop)</span></span>
        </div>
        <div class="stat-block <?= $overall['win_rate'] !== null && $overall['win_rate'] >= 55 ? 'stat-block--green' : '' ?>">
            <span class="stat-block__val"><?= $overall['win_rate'] !== null ? $overall['win_rate'] . '%' : 'N/A' ?></span>
            <span class="stat-block__lbl">Win rate</span>
        </div>
    </div>
    <?php if ($overall['win_rate'] === null): ?>
    <p class="empty" style="margin-top:10px">Not enough resolved decisions yet for an honest win rate — this fills in as decisions resolve over time.</p>
    <?php endif; ?>
</div>

<!-- ══ PER-SOURCE ACCURACY ═══════════════════════════════════════ -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Per-Source Accuracy</h3>
        <span class="card__sub">Did this source's vote match the eventual outcome?</span>
    </div>
    <?php if ($bySource): ?>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Source</th><th>Sample size</th><th>Correct</th><th>Accuracy</th></tr></thead>
            <tbody>
            <?php foreach ($bySource as $s): ?>
            <tr>
                <td><?= e($s['source']) ?></td>
                <td><?= (int) $s['sample_size'] ?></td>
                <td><?= (int) $s['correct'] ?></td>
                <td>
                    <?php if ($s['accuracy'] !== null): ?>
                        <span class="<?= $s['accuracy'] >= 55 ? 'u-mo-up' : ($s['accuracy'] < 45 ? 'u-mo-down' : '') ?>"><?= $s['accuracy'] ?>%</span>
                    <?php else: ?>
                        <span class="u-muted">N/A — <?= e($s['note']) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p class="empty">No resolved decisions with source snapshots yet.</p>
    <?php endif; ?>
</div>

<!-- ══ ACCURACY BY SESSION ═══════════════════════════════════════ -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Accuracy By Session</h3>
        <span class="card__sub">"Are some strategies only effective during London sessions?" — measured, not assumed</span>
    </div>
    <?php if ($bySession): ?>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Session</th><th>Resolved</th><th>Wins</th><th>Losses</th><th>Win rate</th></tr></thead>
            <tbody>
            <?php foreach ($bySession as $s): ?>
            <tr>
                <td><?= e(ucfirst(str_replace('_', ' ', $s['session']))) ?></td>
                <td><?= (int) $s['sample_size'] ?></td>
                <td><?= (int) $s['wins'] ?></td>
                <td><?= (int) $s['losses'] ?></td>
                <td><?= $s['win_rate'] !== null ? $s['win_rate'] . '%' : '<span class="u-muted">N/A — too few resolved decisions in this session</span>' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p class="empty">No resolved decisions with a recorded session yet.</p>
    <?php endif; ?>
</div>

<!-- ══ WEEKLY TREND ═══════════════════════════════════════════════ -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Trend Over Time</h3>
        <span class="card__sub">Weekly win rate and per-source accuracy — gaps mean too few resolved decisions that week, not zero</span>
    </div>
    <?php if ($trend): ?>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead>
                <tr>
                    <th>Week</th><th>Resolved</th><th>Win rate</th>
                    <?php $sourceCols = []; foreach ($trend as $w) { foreach (array_keys($w['by_source']) as $sc) { $sourceCols[$sc] = true; } } $sourceCols = array_keys($sourceCols); ?>
                    <?php foreach ($sourceCols as $sc): ?><th><?= e($sc) ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($trend as $w): ?>
            <tr>
                <td><?= e($w['week']) ?></td>
                <td><?= (int) $w['sample_size'] ?></td>
                <td><?= $w['win_rate'] !== null ? $w['win_rate'] . '%' : '<span class="u-muted">—</span>' ?></td>
                <?php foreach ($sourceCols as $sc): ?>
                <td><?= isset($w['by_source'][$sc]) && $w['by_source'][$sc] !== null ? $w['by_source'][$sc] . '%' : '<span class="u-muted">—</span>' ?></td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p class="empty">No resolved decisions in the trend window yet — this fills in week by week as decisions resolve.</p>
    <?php endif; ?>
</div>

<!-- ══ CONFIDENCE CALIBRATION ═════════════════════════════════════ -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Confidence Calibration</h3>
        <span class="card__sub">Stated confidence vs. actual outcome</span>
    </div>
    <div class="bt-stats-grid">
        <div class="stat-block <?= $calibration['overall_factor'] > 55 ? 'stat-block--green' : ($calibration['overall_factor'] < 45 ? 'stat-block--red' : '') ?>">
            <span class="stat-block__val"><?= $calibration['overall_factor'] ?></span>
            <span class="stat-block__lbl">Calibration factor</span>
        </div>
        <div class="stat-block">
            <span class="stat-block__val"><?= (int) $calibration['sample_size'] ?></span>
            <span class="stat-block__lbl">Sample size</span>
        </div>
        <div class="stat-block <?= $calibration['well_calibrated'] ? 'stat-block--green' : '' ?>">
            <span class="stat-block__val"><?= $calibration['well_calibrated'] ? 'Yes' : 'No' ?></span>
            <span class="stat-block__lbl">Well calibrated?</span>
        </div>
    </div>
    <p class="card__sub" style="margin-top:10px">Reliability calibration trend and per-week confidence calibration are not broken out separately here — with the current resolved-decision sample size, splitting calibration further by week would produce single-digit sample buckets that aren't honestly meaningful. This will be added once enough history accumulates to support it.</p>
</div>

<!-- ══ VISION TIMEFRAME DISCLOSURE ════════════════════════════════ -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">AI Vision — Timeframe Breakdown</h3>
    </div>
    <p class="empty"><?= e($visionNote) ?></p>
</div>
