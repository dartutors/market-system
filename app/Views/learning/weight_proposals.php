<div class="page-header">
    <h2 class="page-title">AI Weight Optimizer</h2>
    <p class="page-sub">Simulated, historically-grounded recommendations for evidence-source weights — nothing here ever takes effect without your explicit approval below. <a href="<?= e(url('learning')) ?>">← Back to AI Learning</a></p>
</div>

<?php if ($flashOk): ?>
<div class="flash success"><?= e($flashOk) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card__hd">
        <h3 class="card__title">Current Weights</h3>
        <span class="card__sub">Live, in effect right now — the weight the Decision Intelligence Engine actually applies today</span>
    </div>
    <div class="bt-stats-grid">
        <?php foreach ($currentWeights as $source => $weight): ?>
        <div class="stat-block">
            <span class="stat-block__val"><?= number_format((float) $weight, 3) ?></span>
            <span class="stat-block__lbl"><?= e($source) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <form method="post" action="<?= e(url('learning/weight-proposals/run')) ?>" style="margin-top:14px">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button type="submit" class="btn btn--scan">Run Weight Optimizer Now</button>
    </form>
    <p class="card__sub" style="margin-top:8px">Simulates alternative weights against resolved historical decisions and proposes a change only when a real, sufficiently-sampled net improvement is found. Never applies anything automatically.</p>
</div>

<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Pending Proposals</h3>
        <span class="card__sub"><?= count($pending) ?> awaiting your decision</span>
    </div>
    <?php if ($pending): ?>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Source</th><th>Current →  Proposed</th><th>Sample size</th><th>Rationale</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($pending as $p): ?>
            <tr>
                <td><?= e($p['source_key']) ?></td>
                <td><?= number_format((float) $p['current_weight'], 3) ?> → <strong><?= number_format((float) $p['proposed_weight'], 3) ?></strong></td>
                <td><?= (int) $p['sample_size'] ?></td>
                <td class="u-muted"><?= e($p['rationale']) ?></td>
                <td style="white-space:nowrap">
                    <form method="post" action="<?= e(url('learning/weight-proposals/decide')) ?>" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn--scan">Approve</button>
                    </form>
                    <form method="post" action="<?= e(url('learning/weight-proposals/decide')) ?>" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                        <input type="hidden" name="action" value="reject">
                        <button type="submit" class="btn">Reject</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p class="empty">No pending proposals — either historical sample sizes are still too small (fewer than 30 resolved decisions), or no simulated reweighting has shown a meaningful improvement yet.</p>
    <?php endif; ?>
</div>

<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Decision History</h3>
        <span class="card__sub">Last 30 proposals, approved and rejected</span>
    </div>
    <?php $decided = array_filter($recent, static fn(array $p): bool => $p['status'] !== 'pending'); ?>
    <?php if ($decided): ?>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Source</th><th>Current →  Proposed</th><th>Status</th><th>Decided</th></tr></thead>
            <tbody>
            <?php foreach ($decided as $p): ?>
            <tr>
                <td><?= e($p['source_key']) ?></td>
                <td><?= number_format((float) $p['current_weight'], 3) ?> → <?= number_format((float) $p['proposed_weight'], 3) ?></td>
                <td><span class="badge <?= $p['status'] === 'approved' ? 'badge--outcome-win' : 'badge--outcome-skip' ?>"><?= e(strtoupper($p['status'])) ?></span></td>
                <td class="u-muted"><?= $p['decided_at'] !== null ? e(fmt_datetime($p['decided_at'])) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p class="empty">No decided proposals yet.</p>
    <?php endif; ?>
</div>
