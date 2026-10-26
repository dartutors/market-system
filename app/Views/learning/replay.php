<?php
$d = $decision;
$dCls = $d['overall_decision'] === 'BUY' ? 'badge--bullish' : ($d['overall_decision'] === 'SELL' ? 'badge--bearish' : 'badge--neutral');
$byName = [];
foreach ($sources as $s) {
    $byName[$s['source']] = $s;
}
// The flow order the user asked for explicitly: News -> Calendar -> Technical -> AI Vision -> Smart Money -> Consensus -> Final Decision -> Actual Outcome.
$flowOrder = ['News', 'Calendar', 'Technical', 'Vision', 'SmartMoney'];
$flowLabels = ['News' => 'News', 'Calendar' => 'Economic Calendar', 'Technical' => 'Technical', 'Vision' => 'AI Vision', 'SmartMoney' => 'Smart Money Concepts'];
$remaining = array_filter($sources, static fn(array $s): bool => !\in_array($s['source'], $flowOrder, true));

function replay_step_reading(array $s): array {
    if ($s['is_directional']) {
        $reading = $s['available'] ? (string) $s['direction'] : 'N/A';
        $cls = $s['direction'] === 'BUY' ? 'badge--bullish' : ($s['direction'] === 'SELL' ? 'badge--bearish' : 'badge--neutral');
    } else {
        $text = (string) $s['reasoning'];
        $cut = strcspn($text, '(,.');
        $reading = $s['available'] ? mb_strtoupper(trim(mb_substr($text, 0, $cut))) : 'N/A';
        $cls = $s['source'] === 'Calendar' && !empty($s['is_blocking']) ? 'badge--bearish' : 'badge--market';
    }
    return [$reading, $cls];
}
?>
<div class="page-header">
    <h2 class="page-title">Decision Replay</h2>
    <p class="page-sub"><?= e($d['market_label']) ?> · <?= e(fmt_datetime($d['generated_at'])) ?> <a href="<?= e(url('learning/replay')) ?>">← Back to Decision Replay</a></p>
</div>

<div class="card">
    <div class="card__hd">
        <h3 class="card__title">Step-by-Step Replay</h3>
        <span class="card__sub">The evidence, in the order it was gathered, exactly as it was seen at decision time</span>
    </div>

    <div class="replay-flow">
        <?php foreach ($flowOrder as $key):
            $s = $byName[$key] ?? null;
            if ($s === null) { continue; }
            [$reading, $cls] = replay_step_reading($s);
        ?>
        <div class="replay-step">
            <div class="replay-step__hd">
                <span class="replay-step__name"><?= e($flowLabels[$key]) ?></span>
                <span class="badge <?= e($cls) ?>"><?= e($reading) ?></span>
            </div>
            <p class="card__sub"><?= $s['available'] ? (int) round((float) $s['confidence']) . '% confidence — ' : '' ?><?= e((string) $s['reasoning']) ?></p>
        </div>
        <div class="replay-arrow">↓</div>
        <?php endforeach; ?>

        <div class="replay-step replay-step--consensus">
            <div class="replay-step__hd">
                <span class="replay-step__name">Consensus</span>
                <span class="badge badge--market"><?= round((float) $d['agreement_percentage']) ?>% agreement</span>
            </div>
            <p class="card__sub">Consensus score <?= (int) $d['consensus_score'] ?>% · Market quality <?= (int) $d['market_quality_score'] ?>/100 · Risk: <?= e((string) $d['risk_level']) ?></p>
            <?php if (!empty($disagreement['reasons'])): ?>
            <p class="card__sub"><strong>Dissenting:</strong> <?= e(implode(' · ', array_map(static fn(array $r): string => $r['source'] . ' (' . $r['direction'] . ')', $disagreement['reasons']))) ?></p>
            <?php endif; ?>
            <?php if (!empty($disagreement['blocked_reason'])): ?>
            <p class="card__sub"><strong>Blocked:</strong> <?= e((string) $disagreement['blocked_reason']) ?></p>
            <?php endif; ?>
            <?php if ($conflicts): ?>
            <p class="card__sub"><strong>Conflicts detected:</strong> <?= e(implode(' · ', array_map(static fn(array $c): string => $c['description'], $conflicts))) ?></p>
            <?php endif; ?>
        </div>
        <div class="replay-arrow">↓</div>

        <div class="replay-step replay-step--final">
            <div class="replay-step__hd">
                <span class="replay-step__name">Final Decision</span>
                <span class="badge <?= e($dCls) ?>"><?= e($d['overall_decision']) ?></span>
            </div>
            <p class="card__sub">Reliability grade: <?= e((string) ($d['reliability_grade'] ?? 'N/A')) ?> (<?= (int) ($d['reliability_sample_size'] ?? 0) ?> historical decisions)</p>
        </div>
        <div class="replay-arrow">↓</div>

        <div class="replay-step replay-step--outcome">
            <div class="replay-step__hd">
                <span class="replay-step__name">Actual Market Outcome</span>
                <?php if ($d['outcome'] !== null):
                    $ocCls = match ($d['outcome']) { 'win' => 'badge--outcome-win', 'loss' => 'badge--outcome-loss', default => 'badge--outcome-skip' };
                ?>
                <span class="badge <?= e($ocCls) ?>"><?= e(strtoupper($d['outcome'])) ?></span>
                <?php else: ?>
                <span class="u-muted">Still pending — not yet past its expected review window</span>
                <?php endif; ?>
            </div>
            <?php if ($d['resolved_at'] !== null): ?>
            <p class="card__sub">Resolved <?= e(fmt_datetime($d['resolved_at'])) ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($tradePlan !== null): ?>
<div class="card" style="margin-top:20px">
    <div class="card__hd"><h3 class="card__title">Trade Plan At The Time</h3></div>
    <div class="bt-stats-grid" style="grid-template-columns:repeat(4,1fr)">
        <div class="stat-block"><span class="stat-block__val"><?= number_format((float) $tradePlan['entry_price'], 5) ?></span><span class="stat-block__lbl">Entry</span></div>
        <div class="stat-block stat-block--red"><span class="stat-block__val"><?= number_format((float) $tradePlan['stop_loss'], 5) ?></span><span class="stat-block__lbl">Stop Loss</span></div>
        <div class="stat-block stat-block--green"><span class="stat-block__val"><?= $tradePlan['take_profit_1'] !== null ? number_format((float) $tradePlan['take_profit_1'], 5) : '—' ?></span><span class="stat-block__lbl">Take Profit 1</span></div>
        <div class="stat-block stat-block--green"><span class="stat-block__val"><?= $tradePlan['take_profit_2'] !== null ? number_format((float) $tradePlan['take_profit_2'], 5) : '—' ?></span><span class="stat-block__lbl">Take Profit 2</span></div>
    </div>
</div>
<?php endif; ?>

<?php if ($invalidation): ?>
<div class="card" style="margin-top:20px">
    <div class="card__hd"><h3 class="card__title">Invalidation Conditions</h3></div>
    <ul>
        <?php foreach ($invalidation as $i): ?>
        <li class="card__sub"><?= e($i) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($remaining): ?>
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Additional Evidence</h3>
        <span class="card__sub">Currency Strength, Session, Volatility, Trend Alignment, Liquidity, Strategy Lab, Historical Accuracy, Trade Journal, Claude, OpenAI</span>
    </div>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Source</th><th>Reading</th><th>Confidence</th><th>Reasoning</th></tr></thead>
            <tbody>
            <?php foreach ($remaining as $s): [$reading, $cls] = replay_step_reading($s); ?>
                <tr>
                    <td><?= e((string) $s['source']) ?></td>
                    <td><span class="badge <?= e($cls) ?>"><?= e($reading) ?></span></td>
                    <td><?= $s['available'] ? (int) round((float) $s['confidence']) . '%' : '—' ?></td>
                    <td class="u-muted"><?= e(mb_substr((string) $s['reasoning'], 0, 120)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
