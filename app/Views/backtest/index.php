<div class="page-header">
    <h2 class="page-title">Backtest Engine</h2>
    <p class="page-sub">Simulates signals on historical OHLCV data. Educational only — no real trading.</p>
</div>

<!-- ── Run form ─────────────────────────────────────────────────────── -->
<div class="card card--accent">
    <form method="post" action="<?= e(url('backtest/run')) ?>" class="bt-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <div class="bt-form__fields">
            <div class="form-group">
                <label for="bt-symbol">Market pair</label>
                <select id="bt-symbol" name="symbol" class="form-select">
                    <?php foreach ($markets as $m): ?>
                    <option value="<?= e($m['symbol']) ?>"
                        <?= isset($selected) && $selected['symbol'] === $m['symbol'] ? 'selected' : '' ?>>
                        <?= e($m['label']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="bt-timeframe">Timeframe</label>
                <select id="bt-timeframe" name="timeframe" class="form-select">
                    <option value="60m" <?= isset($selected) && $selected['timeframe'] === '60m' ? 'selected' : '' ?>>1 Hour (60m)</option>
                    <option value="4h"  <?= isset($selected) && $selected['timeframe'] === '4h'  ? 'selected' : '' ?>>4 Hours</option>
                </select>
            </div>

            <div class="form-group">
                <label for="bt-days">History period</label>
                <select id="bt-days" name="days" class="form-select">
                    <?php foreach ([30, 60, 90] as $d): ?>
                    <option value="<?= $d ?>" <?= isset($selected) && $selected['days'] === $d ? 'selected' : '' ?>>
                        <?= $d ?> days
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn--scan">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 4 23 10 17 10"/>
                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                </svg>
                Run Backtest
            </button>
        </div>
    </form>
</div>

<?php if (isset($result)): ?>

    <?php if (isset($result['error'])): ?>
    <div class="flash error"><?= e($result['error']) ?></div>

    <?php else: ?>

    <!-- ── Summary stats grid ─────────────────────────────────────────── -->
    <div class="bt-stats-grid">
        <?php
            $isReliable = ($result['total_signals'] >= 30 && $result['win_rate'] >= 60.0);
            $statBlocks = [
                ['label' => 'Total Signals', 'value' => $result['total_signals'],          'cls' => ''],
                ['label' => 'Win Rate',       'value' => $result['win_rate'] . '%',         'cls' => 'stat--green'],
                ['label' => 'Loss Rate',      'value' => $result['loss_rate'] . '%',        'cls' => 'stat--red'],
                ['label' => 'Avg Confidence', 'value' => $result['avg_confidence'] . '%',  'cls' => ''],
                ['label' => 'Wins',           'value' => $result['wins'],                  'cls' => 'stat--green'],
                ['label' => 'Losses',         'value' => $result['losses'],                'cls' => 'stat--red'],
                ['label' => 'Breakevens',     'value' => $result['breakevens'],            'cls' => ''],
                ['label' => 'Max Drawdown',   'value' => $result['max_drawdown_r'] . 'R',  'cls' => 'stat--red'],
                ['label' => 'BUY Win Rate',   'value' => $result['buy_win_rate']  . '%',   'cls' => 'stat--green'],
                ['label' => 'SELL Win Rate',  'value' => $result['sell_win_rate'] . '%',   'cls' => 'stat--green'],
            ];
        ?>
        <?php foreach ($statBlocks as $sb): ?>
        <div class="stat-block <?= e($sb['cls']) ?>">
            <span class="stat-block__val"><?= e((string) $sb['value']) ?></span>
            <span class="stat-block__lbl"><?= e($sb['label']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Reliability badge ─────────────────────────────────────────── -->
    <div class="bt-reliability">
        <?php if ($isReliable): ?>
            <span class="reliability-badge reliability-badge--valid">
                ✓ Strategy: RELIABLE
            </span>
            <span class="reliability-hint">≥30 signals + ≥60% win rate threshold met</span>
        <?php else: ?>
            <span class="reliability-badge reliability-badge--not">
                ✗ Strategy: NOT VALIDATED
            </span>
            <span class="reliability-hint">
                Need ≥30 signals (have <?= (int) $result['total_signals'] ?>) and ≥60% win rate (have <?= $result['win_rate'] ?>%)
            </span>
        <?php endif; ?>
    </div>

    <p class="bt-note"><?= e($result['note']) ?></p>

    <!-- ══ AI BACKTEST SUMMARY ════════════════════════════════════════ -->
    <?php if (isset($aiSummary) && $aiSummary !== null): ?>
    <?php
        $decision    = strtoupper((string) ($aiSummary['decision']   ?? 'WAIT'));
        $aiConf      = (int)                ($aiSummary['confidence'] ?? 0);
        $aiSummText  = (string)             ($aiSummary['summary']    ?? '');
        $aiReasons   = \is_array($aiSummary['reasons'] ?? null) ? $aiSummary['reasons'] : [];
        $aiProvider  = strtolower((string) ($aiSummary['provider']   ?? 'rule_based'));
        $aiReliable  = (bool)               ($aiSummary['reliable']   ?? false);

        $decCls = match ($decision) {
            'VALID'   => 'decision--valid',
            'IMPROVE' => 'decision--improve',
            default   => 'decision--wait',
        };
        $decIcon = match ($decision) {
            'VALID'   => '✓',
            'IMPROVE' => '↑',
            default   => '⏸',
        };
        $decDesc = match ($decision) {
            'VALID'   => 'Strategy showing statistical reliability',
            'IMPROVE' => 'Strategy inconsistent — refine and retest',
            default   => 'Not enough data — collect more signals',
        };
        $provLabel = match ($aiProvider) {
            'claude' => 'Claude AI', 'openai' => 'OpenAI', default => 'Rule-based',
        };
        $confBar = max(4, $aiConf);
    ?>
    <div class="card bt-ai-card">
        <div class="card__hd">
            <h3 class="card__title">AI Backtest Summary</h3>
            <span class="badge badge--ai badge--ai-<?= e($aiProvider) ?>"><?= e($provLabel) ?></span>
        </div>

        <!-- Decision banner -->
        <div class="bt-decision <?= e($decCls) ?>">
            <span class="bt-decision__icon"><?= $decIcon ?></span>
            <div class="bt-decision__body">
                <span class="bt-decision__label"><?= e($decision) ?></span>
                <span class="bt-decision__desc"><?= e($decDesc) ?></span>
            </div>
            <div class="bt-decision__conf">
                <span class="bt-decision__conf-label">AI Confidence</span>
                <div class="bt-conf-bar">
                    <div class="bt-conf-fill bt-conf-fill--<?= e(strtolower($decision)) ?>"
                         style="width:<?= $confBar ?>%"></div>
                </div>
                <span class="bt-decision__conf-pct"><?= $aiConf ?>%</span>
            </div>
        </div>

        <!-- Summary text -->
        <?php if ($aiSummText !== ''): ?>
        <p class="bt-ai-summary"><?= e($aiSummText) ?></p>
        <?php endif; ?>

        <!-- Reason bullets -->
        <?php if (!empty($aiReasons)): ?>
        <ul class="bt-ai-reasons">
            <?php foreach ($aiReasons as $reason): ?>
            <li><?= e((string) $reason) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <!-- Recommendation footer -->
        <div class="bt-recommendation">
            <?php if ($decision === 'WAIT'): ?>
                <strong>Recommendation:</strong> Run the backtest over a longer period (60–90 days) to collect enough data for meaningful analysis.
            <?php elseif ($decision === 'IMPROVE'): ?>
                <strong>Recommendation:</strong> Review your entry conditions, tighten filters, and paper-trade while collecting more data.
            <?php else: ?>
                <strong>Recommendation:</strong> Strategy passes initial reliability checks. Continue with paper trading and strict risk management before considering real positions.
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Trade log ─────────────────────────────────────────────────── -->
    <?php if (!empty($result['trades'])): ?>
    <div class="card" style="margin-top:20px">
        <div class="card__hd">
            <h3 class="card__title">
                Simulated Trades — <?= e($selected['label']) ?> / <?= e($selected['timeframe']) ?> / <?= e((string) $selected['days']) ?>d
            </h3>
            <span class="card__count"><?= count($result['trades']) ?> trades</span>
        </div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Action</th>
                        <th>Entry</th>
                        <th>ATR</th>
                        <th>Conf.</th>
                        <th>Score</th>
                        <th>Outcome</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($result['trades'] as $i => $t):
                    $outCls = match ($t['outcome']) {
                        'win'       => 'tr--buy',
                        'loss'      => 'tr--sell',
                        'breakeven' => 'tr--wait',
                        default     => '',
                    };
                ?>
                <tr class="<?= e($outCls) ?>">
                    <td class="td--muted"><?= $i + 1 ?></td>
                    <td class="td--action td--nowrap"><?= e($t['action']) ?></td>
                    <td class="td--mono"><?= e((string) $t['entry_price']) ?></td>
                    <td class="td--mono td--muted"><?= e((string) $t['atr']) ?></td>
                    <td><?= e((string) $t['confidence']) ?>%</td>
                    <td class="td--mono"><?= e((string) $t['score']) ?></td>
                    <td class="td--action"><?= e(strtoupper($t['outcome'])) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php else: ?>
    <p class="empty">No signals met the minimum score threshold in this period.</p>
    <?php endif; ?>

    <?php endif; ?>
<?php endif; ?>
