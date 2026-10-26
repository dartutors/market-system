<?php
use App\Backtesting\Support\SvgChartRenderer;
use App\Backtesting\Models\TradeResult;
?>
<div class="page-header">
    <h2 class="page-title">Advanced Backtesting</h2>
    <p class="page-sub">Strategy comparison, walk-forward testing, Monte Carlo simulation, and parameter optimization on real historical data. Educational only — no real trading.</p>
</div>

<!-- ── Run form ─────────────────────────────────────────────────────── -->
<div class="card card--accent">
    <form method="post" action="<?= e(url('backtest/advanced/run')) ?>" class="bt-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <div class="bt-form__fields">
            <div class="form-group">
                <label for="abt-symbol">Market pair</label>
                <select id="abt-symbol" name="symbol" class="form-select">
                    <?php foreach ($markets as $m): ?>
                    <option value="<?= e($m['symbol']) ?>"
                        <?= isset($selected) && $selected['symbol'] === $m['symbol'] ? 'selected' : '' ?>>
                        <?= e($m['label']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="abt-timeframe">Timeframe</label>
                <select id="abt-timeframe" name="timeframe" class="form-select">
                    <?php foreach ($timeframes as $tf): ?>
                    <option value="<?= e($tf) ?>" <?= isset($selected) && $selected['timeframe'] === $tf ? 'selected' : '' ?>><?= e($tf) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="abt-bars">Bars analyzed</label>
                <select id="abt-bars" name="bars_back" class="form-select">
                    <?php foreach ($barsOptions as $b): ?>
                    <option value="<?= $b ?>" <?= isset($selected) && $selected['barsBack'] === $b ? 'selected' : '' ?>><?= $b ?> bars</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="abt-strategy">Strategy</label>
                <select id="abt-strategy" name="strategy" class="form-select">
                    <option value="compare_all" <?= isset($selected) && $selected['strategy'] === 'compare_all' ? 'selected' : '' ?>>Compare All Strategies</option>
                    <?php foreach ($strategies as $s): ?>
                    <option value="<?= e($s) ?>" <?= isset($selected) && $selected['strategy'] === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn--scan">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 4 23 10 17 10"/>
                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                </svg>
                Run Advanced Backtest
            </button>
        </div>
    </form>
</div>

<?php if (isset($result)): ?>
<?php if (isset($result['error'])): ?>
    <div class="flash error"><?= e($result['error']) ?></div>
<?php else:
    $primary       = $result['primary'];
    $primarySum    = $primary['summary'];
    $comparison    = $result['comparison'];
    $walkForward   = $result['walk_forward'];
    $monteCarlo    = $result['monte_carlo'];
    $optimization  = $result['optimization'];
    /** @var array<int, TradeResult> $trades */
    $trades        = $primary['trades'];
?>

    <!-- ── Primary run stat grid ───────────────────────────────────────── -->
    <div class="bt-stats-grid">
        <?php
            $statBlocks = [
                ['label' => 'Strategy',       'value' => ucwords(str_replace('_', ' ', $primary['strategy'])), 'cls' => ''],
                ['label' => 'Total Signals',  'value' => $primarySum['total_signals'],       'cls' => ''],
                ['label' => 'Win Rate',       'value' => $primarySum['win_rate'] . '%',       'cls' => 'stat-block--green'],
                ['label' => 'Profit Factor',  'value' => $primarySum['profit_factor'],        'cls' => ''],
                ['label' => 'Final Equity',   'value' => $primarySum['final_equity_r'] . 'R', 'cls' => $primarySum['final_equity_r'] >= 0 ? 'stat-block--green' : 'stat-block--red'],
                ['label' => 'Max Drawdown',   'value' => $primarySum['max_drawdown_r'] . 'R', 'cls' => 'stat-block--red'],
                ['label' => 'BUY Win Rate',   'value' => $primarySum['buy_win_rate']  . '%',  'cls' => 'stat-block--green'],
                ['label' => 'SELL Win Rate',  'value' => $primarySum['sell_win_rate'] . '%',  'cls' => 'stat-block--green'],
                ['label' => 'Avg Risk/Reward', 'value' => '1:' . $primarySum['avg_risk_reward'], 'cls' => ''],
                ['label' => 'Avg Trade Duration', 'value' => $primarySum['avg_trade_duration_bars'] !== null ? $primarySum['avg_trade_duration_bars'] . ' bars' : '—', 'cls' => ''],
                ['label' => 'Reliability',    'value' => $primarySum['reliability']['label'], 'cls' => $primarySum['reliability']['label'] === 'High' ? 'stat-block--green' : ($primarySum['reliability']['label'] === 'Low' ? 'stat-block--red' : '')],
            ];
        ?>
        <?php foreach ($statBlocks as $sb): ?>
        <div class="stat-block <?= e($sb['cls']) ?>">
            <span class="stat-block__val"><?= e((string) $sb['value']) ?></span>
            <span class="stat-block__lbl"><?= e($sb['label']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="bt-note"><?= e($primarySum['note']) ?></p>

    <!-- ── Equity curve ─────────────────────────────────────────────────── -->
    <div class="card abt-card">
        <div class="card__hd"><h3 class="card__title">Equity Curve — <?= e($primary['strategy']) ?></h3></div>
        <?= SvgChartRenderer::equityCurve($primary['equity_curve']) ?>
    </div>

    <!-- ── Strategy comparison ─────────────────────────────────────────── -->
    <div class="card abt-card">
        <div class="card__hd">
            <h3 class="card__title">Strategy Comparison</h3>
            <span class="card__count"><?= count($comparison) ?> strategies</span>
        </div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Strategy</th><th>Signals</th><th>Win Rate</th><th>Profit Factor</th><th>Max DD</th><th>Avg R:R</th><th>Avg Duration</th><th>Reliability</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($comparison as $row): ?>
                <tr class="<?= $row['best'] ? 'tr--buy' : '' ?>">
                    <td class="td--action"><?= e(ucwords(str_replace('_', ' ', $row['strategy']))) ?></td>
                    <td class="td--mono"><?= $row['summary']['total_signals'] ?></td>
                    <td><?= $row['summary']['win_rate'] ?>%</td>
                    <td class="td--mono"><?= $row['summary']['profit_factor'] ?></td>
                    <td class="td--mono td--muted"><?= $row['summary']['max_drawdown_r'] ?>R</td>
                    <td class="td--mono td--muted">1:<?= $row['summary']['avg_risk_reward'] ?></td>
                    <td class="td--mono td--muted"><?= $row['summary']['avg_trade_duration_bars'] !== null ? $row['summary']['avg_trade_duration_bars'] . ' bars' : '—' ?></td>
                    <td class="td--muted"><?= e($row['summary']['reliability']['label']) ?></td>
                    <td><?= $row['best'] ? '<span class="abt-best-badge">BEST</span>' : '' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Walk-forward testing ────────────────────────────────────────── -->
    <div class="card abt-card">
        <div class="card__hd"><h3 class="card__title">Walk-Forward Testing</h3></div>
        <?php if (isset($walkForward['error'])): ?>
        <p class="empty"><?= e($walkForward['error']) ?></p>
        <?php else: ?>
        <div class="abt-stability abt-stability--<?= e($walkForward['stability']) ?>">
            <?= $walkForward['stability'] === 'stable' ? '✓ STABLE' : '⚠ UNSTABLE' ?>
            — dominant strategy: <strong><?= e(ucwords(str_replace('_', ' ', (string) $walkForward['dominant_strategy']))) ?></strong>,
            avg out-of-sample win rate <strong><?= $walkForward['avg_out_of_sample_win_rate'] ?>%</strong>
        </div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Window</th><th>In-Sample Winner</th><th>Out-of-Sample Win Rate</th><th>Out-of-Sample Profit Factor</th><th>Signals</th></tr></thead>
                <tbody>
                <?php foreach ($walkForward['windows'] as $w): ?>
                <tr>
                    <td class="td--mono"><?= $w['window'] ?></td>
                    <td><?= e(ucwords(str_replace('_', ' ', $w['in_sample_winner']))) ?></td>
                    <td><?= $w['out_of_sample_win_rate'] ?>%</td>
                    <td class="td--mono"><?= $w['out_of_sample_profit_factor'] ?></td>
                    <td class="td--mono td--muted"><?= $w['out_of_sample_signals'] ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Monte Carlo simulation ──────────────────────────────────────── -->
    <div class="card abt-card">
        <div class="card__hd"><h3 class="card__title">Monte Carlo Simulation</h3></div>
        <?php if (isset($monteCarlo['error'])): ?>
        <p class="empty"><?= e($monteCarlo['error']) ?></p>
        <?php else: $fe = $monteCarlo['final_equity_percentiles']; ?>
        <p class="abt-mc-sub"><?= $monteCarlo['iterations'] ?> resamples of the <?= $monteCarlo['trade_count'] ?> simulated trades — final equity distribution:</p>
        <?= SvgChartRenderer::percentileBar($fe) ?>
        <div class="bt-stats-grid">
            <div class="stat-block"><span class="stat-block__val">P5</span><span class="stat-block__lbl"><?= $fe['p5'] ?>R</span></div>
            <div class="stat-block"><span class="stat-block__val">P25</span><span class="stat-block__lbl"><?= $fe['p25'] ?>R</span></div>
            <div class="stat-block"><span class="stat-block__val">P50 (Median)</span><span class="stat-block__lbl"><?= $fe['p50'] ?>R</span></div>
            <div class="stat-block"><span class="stat-block__val">P75</span><span class="stat-block__lbl"><?= $fe['p75'] ?>R</span></div>
            <div class="stat-block"><span class="stat-block__val">P95</span><span class="stat-block__lbl"><?= $fe['p95'] ?>R</span></div>
            <div class="stat-block stat-block--red"><span class="stat-block__val"><?= $monteCarlo['probability_of_loss'] ?>%</span><span class="stat-block__lbl">Probability of Loss</span></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Parameter optimization ──────────────────────────────────────── -->
    <div class="card abt-card">
        <div class="card__hd">
            <h3 class="card__title">Parameter Optimization</h3>
            <span class="card__count">target/stop ATR grid</span>
        </div>
        <?php if (isset($optimization['error'])): ?>
        <p class="empty"><?= e($optimization['error']) ?></p>
        <?php else: ?>
        <?php if ($optimization['baseline']): ?>
        <p class="abt-mc-sub">Default baseline (2.0x target / 1.2x stop): <strong><?= $optimization['baseline']['summary']['win_rate'] ?>%</strong> win rate, profit factor <strong><?= $optimization['baseline']['summary']['profit_factor'] ?></strong>.</p>
        <?php endif; ?>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Target x ATR</th><th>Stop x ATR</th><th>Signals</th><th>Win Rate</th><th>Profit Factor</th><th></th></tr></thead>
                <tbody>
                <?php foreach (array_slice($optimization['grid'], 0, 8) as $g): ?>
                <tr class="<?= $g['best'] ? 'tr--buy' : '' ?>">
                    <td class="td--mono"><?= $g['target_atr_mult'] ?>x</td>
                    <td class="td--mono"><?= $g['stop_atr_mult'] ?>x</td>
                    <td class="td--mono td--muted"><?= $g['summary']['total_signals'] ?></td>
                    <td><?= $g['summary']['win_rate'] ?>%</td>
                    <td class="td--mono"><?= $g['summary']['profit_factor'] ?></td>
                    <td><?= $g['best'] ? '<span class="abt-best-badge">BEST</span>' : '' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Trade / Historical replay ───────────────────────────────────── -->
    <?php if (!empty($trades)): ?>
    <div class="card abt-card">
        <div class="card__hd">
            <h3 class="card__title">Trade Replay</h3>
            <span class="card__count"><?= count($trades) ?> trades</span>
        </div>
        <div class="abt-replay">
            <div class="abt-replay__nav">
                <button type="button" id="abt-replay-prev" class="btn btn-ghost">&larr; Prev</button>
                <span id="abt-replay-pos">1 / <?= count($trades) ?></span>
                <button type="button" id="abt-replay-next" class="btn btn-ghost">Next &rarr;</button>
            </div>
            <?php foreach ($trades as $i => $trade):
                /** @var TradeResult $trade */
                $windowStart     = max(0, $trade->index - 12);
                $windowEnd       = min(count($enrichedRows), $trade->index + 4);
                $window          = array_slice($enrichedRows, $windowStart, $windowEnd - $windowStart);
                $entryLocalIndex = $trade->index - $windowStart;
                $target = $trade->action === 'BUY' ? $trade->entryPrice + $trade->atr * 2.0 : $trade->entryPrice - $trade->atr * 2.0;
                $stop   = $trade->action === 'BUY' ? $trade->entryPrice - $trade->atr * 1.2 : $trade->entryPrice + $trade->atr * 1.2;
                $outCls = match ($trade->outcome) { 'win' => 'tr--buy', 'loss' => 'tr--sell', default => '' };
            ?>
            <div class="abt-replay__frame <?= $outCls ?>" data-abt-frame="<?= $i ?>" <?= $i > 0 ? 'style="display:none"' : '' ?>>
                <?= SvgChartRenderer::candleWindow($window, $entryLocalIndex, $trade->action, $trade->entryPrice, $target, $stop) ?>
                <div class="abt-replay__meta">
                    <span class="td--action"><?= e($trade->action) ?></span>
                    <span>Entry <?= round($trade->entryPrice, 5) ?></span>
                    <span>Confidence <?= round($trade->confidence, 1) ?>%</span>
                    <span class="td--action"><?= e(strtoupper($trade->outcome)) ?></span>
                </div>
                <?php if (!empty($trade->reasons)): ?>
                <ul class="bt-ai-reasons">
                    <?php foreach ($trade->reasons as $reason): ?><li><?= e((string) $reason) ?></li><?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <script>
    (function () {
        var frames = document.querySelectorAll('[data-abt-frame]');
        var pos    = document.getElementById('abt-replay-pos');
        var idx    = 0;
        function show(n) {
            if (n < 0 || n >= frames.length) return;
            frames[idx].style.display = 'none';
            idx = n;
            frames[idx].style.display = '';
            if (pos) pos.textContent = (idx + 1) + ' / ' + frames.length;
        }
        var prevBtn = document.getElementById('abt-replay-prev');
        var nextBtn = document.getElementById('abt-replay-next');
        if (prevBtn) prevBtn.addEventListener('click', function () { show(idx - 1); });
        if (nextBtn) nextBtn.addEventListener('click', function () { show(idx + 1); });
    })();
    </script>
    <?php endif; ?>

    <!-- ══ AI ADVANCED BACKTEST EXPLANATION ═══════════════════════════════ -->
    <?php if (isset($aiExplanation) && $aiExplanation !== null): ?>
    <?php
        $decision   = strtoupper((string) ($aiExplanation['decision']   ?? 'WAIT'));
        $aiConf     = (int)                ($aiExplanation['confidence'] ?? 0);
        $aiText     = (string)             ($aiExplanation['summary']    ?? '');
        $aiReasons  = \is_array($aiExplanation['reasons'] ?? null) ? $aiExplanation['reasons'] : [];
        $aiProvider = strtolower((string) ($aiExplanation['provider']   ?? 'rule_based'));
        $decCls = match ($decision) { 'VALID' => 'decision--valid', 'IMPROVE' => 'decision--improve', default => 'decision--wait' };
        $decIcon = match ($decision) { 'VALID' => '✓', 'IMPROVE' => '↑', default => '⏸' };
        $provLabel = match ($aiProvider) { 'claude' => 'Claude AI', 'openai' => 'OpenAI', default => 'Rule-based' };
    ?>
    <div class="card bt-ai-card">
        <div class="card__hd">
            <h3 class="card__title">AI Backtest Explanation</h3>
            <span class="badge badge--ai badge--ai-<?= e($aiProvider) ?>"><?= e($provLabel) ?></span>
        </div>
        <div class="bt-decision <?= e($decCls) ?>">
            <span class="bt-decision__icon"><?= $decIcon ?></span>
            <div class="bt-decision__body">
                <span class="bt-decision__label"><?= e($decision) ?></span>
            </div>
            <div class="bt-decision__conf">
                <span class="bt-decision__conf-label">AI Confidence</span>
                <div class="bt-conf-bar"><div class="bt-conf-fill bt-conf-fill--<?= e(strtolower($decision)) ?>" style="width:<?= max(4, $aiConf) ?>%"></div></div>
                <span class="bt-decision__conf-pct"><?= $aiConf ?>%</span>
            </div>
        </div>
        <?php if ($aiText !== ''): ?><p class="bt-ai-summary"><?= e($aiText) ?></p><?php endif; ?>
        <?php if (!empty($aiReasons)): ?>
        <ul class="bt-ai-reasons"><?php foreach ($aiReasons as $reason): ?><li><?= e((string) $reason) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<?php endif; ?>
<?php endif; ?>
