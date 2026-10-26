<div class="page-header">
    <h2 class="page-title">AI Decision Replay</h2>
    <p class="page-sub">Pick any historical decision to see exactly how the system reasoned to it, and what actually happened. <a href="<?= e(url('learning')) ?>">← Back to AI Learning</a></p>
</div>

<div class="card">
    <div class="card__hd">
        <h3 class="card__title">Recent Decisions</h3>
        <span class="card__sub"><?= count($decisions) ?> most recent, across all markets</span>
    </div>
    <?php if ($decisions): ?>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>When</th><th>Market</th><th>Decision</th><th>Confidence</th><th>Agreement</th><th>Outcome</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($decisions as $d):
                $dCls = $d['overall_decision'] === 'BUY' ? 'badge--bullish' : ($d['overall_decision'] === 'SELL' ? 'badge--bearish' : 'badge--neutral');
                $outcome = $d['outcome'];
                $ocCls = match ($outcome) { 'win' => 'badge--outcome-win', 'loss' => 'badge--outcome-loss', 'timeout' => 'badge--outcome-skip', default => '' };
            ?>
            <tr>
                <td class="u-muted"><?= e(fmt_datetime($d['generated_at'])) ?></td>
                <td><span class="badge badge--market"><?= e($d['market_label']) ?></span></td>
                <td><span class="badge <?= e($dCls) ?>"><?= e($d['overall_decision']) ?></span></td>
                <td><?= (int) $d['consensus_score'] ?>%</td>
                <td><?= round((float) $d['agreement_percentage']) ?>%</td>
                <td><?= $outcome !== null ? '<span class="badge ' . e($ocCls) . '">' . e(strtoupper($outcome)) . '</span>' : '<span class="u-muted">pending</span>' ?></td>
                <td><a href="<?= e(url('learning/replay-detail?id=' . (int) $d['id'])) ?>" class="btn btn--scan">Replay →</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p class="empty">No decisions recorded yet — the Decision Intelligence Engine writes one here every time it runs.</p>
    <?php endif; ?>
</div>
