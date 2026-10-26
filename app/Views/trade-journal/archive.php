<div class="page-header">
    <h2 class="page-title">AI Recommendation Archive</h2>
    <p class="page-sub">Historical decisions produced automatically by the scanner's Decision Engine. Log any of them to your journal to track how they played out.</p>
</div>

<div class="card card--accent">
    <form method="get" action="<?= e(url('trade-journal/archive')) ?>" class="bt-form">
        <div class="bt-form__fields">
            <div class="form-group">
                <label for="archive-market">Filter by pair</label>
                <select id="archive-market" name="market_label" class="form-select" onchange="this.form.submit()">
                    <option value="">All markets</option>
                    <?php foreach ($markets as $m): ?>
                    <option value="<?= e($m['label']) ?>" <?= $selected === $m['label'] ? 'selected' : '' ?>><?= e($m['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>
</div>

<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Decisions</h3>
        <?php if ($decisions): ?><span class="card__count"><?= count($decisions) ?> records</span><?php endif; ?>
    </div>

    <?php if ($decisions): ?>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Time</th><th>Pair</th><th>Decision</th><th>Conf.</th><th>Risk</th><th>Reasoning</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($decisions as $d):
                $dec = strtoupper((string) $d['decision']);
                $cls = match ($dec) { 'BUY' => 'tr--buy', 'SELL' => 'tr--sell', 'WAIT' => 'tr--wait', default => '' };
            ?>
            <tr class="<?= e($cls) ?>">
                <td class="td--mono td--muted td--nowrap"><?= e(fmt_datetime((string) $d['created_at'])) ?></td>
                <td><span class="badge badge--market"><?= e((string) $d['market_label']) ?></span></td>
                <td class="td--action"><?= e($dec) ?></td>
                <td class="td--conf"><?= e((string) $d['confidence']) ?>%</td>
                <td class="td--muted"><?= e(ucfirst((string) $d['risk_level'])) ?></td>
                <td><?= e(mb_substr((string) $d['reasoning'], 0, 140)) ?></td>
                <td class="td--nowrap">
                    <?php if (\in_array($dec, ['BUY', 'SELL'], true)): ?>
                    <a href="<?= e(url('trade-journal')) ?>?log_decision_id=<?= (int) $d['id'] ?>" class="btn-journal-save">Log to Journal</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p class="empty">No AI decisions recorded yet — the scanner produces one every cron cycle.</p>
    <?php endif; ?>
</div>
