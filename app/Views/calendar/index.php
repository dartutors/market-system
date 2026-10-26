<div class="page-header">
    <h2 class="page-title">Economic Calendar</h2>
    <p class="page-sub">Manage high-impact events. Scheduled events block BUY/SELL signals within the configured risk window.</p>
</div>

<!-- ── Action row: Add form + Refresh button ───────────────────────── -->
<div class="cal-action-row">

    <!-- Add event form -->
    <div class="card card--accent" style="flex:1 1 auto">
        <h3 class="card__title">Add New Event</h3>
        <form method="post" action="<?= e(url('calendar/store')) ?>" class="cal-form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <div class="cal-form__fields">
                <div class="form-group">
                    <label for="cal-title">Event title</label>
                    <input type="text" id="cal-title" name="title" required maxlength="255"
                           placeholder="e.g. US CPI (Consumer Price Index)" class="form-input">
                </div>

                <div class="form-group">
                    <label for="cal-currency">Currency</label>
                    <select id="cal-currency" name="currency" class="form-select">
                        <?php foreach ($currencies as $c): ?>
                            <option value="<?= e($c) ?>"><?= e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="cal-impact">Impact</label>
                    <select id="cal-impact" name="impact" class="form-select">
                        <?php foreach ($impacts as $imp): ?>
                            <option value="<?= e($imp) ?>" <?= $imp === 'high' ? 'selected' : '' ?>>
                                <?= e(ucfirst($imp)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="cal-dt">Date &amp; Time (EAT)</label>
                    <input type="datetime-local" id="cal-dt" name="scheduled_at" required class="form-input">
                </div>

                <button type="submit" class="btn btn--scan">Add Event</button>
            </div>
        </form>
    </div>

    <!-- Refresh from config card -->
    <div class="card" style="flex:0 0 280px;align-self:flex-start">
        <h3 class="card__title">Sync from Config</h3>
        <p class="card__sub" style="margin-bottom:14px">
            Re-seeds from <code>EconomicCalendar.php</code>. Inserts missing events,
            skips duplicates, removes events older than 30&nbsp;days.
        </p>
        <form method="post" action="<?= e(url('calendar/refresh')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="btn btn--scan" style="width:100%;justify-content:center"
                    onclick="return confirm('Refresh calendar from config? Expired events (>30 days) will be deleted.')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="23 4 23 10 17 10"/>
                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                </svg>
                Refresh Calendar
            </button>
        </form>
    </div>

</div>

<!-- ── Event list ─────────────────────────────────────────────────── -->
<div class="card" style="margin-top:20px">
    <div class="card__hd">
        <h3 class="card__title">Scheduled Events</h3>
        <span class="card__count"><?= count($events) ?> events</span>
    </div>

    <?php if ($events): ?>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>CCY</th>
                    <th>Impact</th>
                    <th>EAT Time</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($events as $ev):
                $eatFmt = '';
                try {
                    $eatFmt = (new DateTimeImmutable((string) $ev['scheduled_at']))->format('M j, Y  H:i');
                } catch (Throwable) {
                    $eatFmt = (string) $ev['scheduled_at'];
                }
                $impactCls = match (strtolower((string) $ev['impact'])) {
                    'high'   => 'badge--high',
                    'medium' => 'badge--medium',
                    default  => 'badge--low',
                };
                // Highlight events that are in the past (may want to delete)
                $isPast = strtotime((string) $ev['scheduled_at']) < time();
            ?>
            <tr class="<?= $isPast ? 'tr--muted-row' : '' ?>">
                <td><?= e((string) $ev['title']) ?></td>
                <td class="td--ccy"><?= e((string) $ev['currency']) ?></td>
                <td>
                    <span class="badge badge--impact <?= e($impactCls) ?>">
                        <?= e(strtoupper((string) $ev['impact'])) ?>
                    </span>
                </td>
                <td class="td--mono td--muted">
                    <?= e($eatFmt) ?>
                    <?php if ($isPast): ?>
                        <span class="muted" style="font-size:0.65rem"> (past)</span>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" action="<?= e(url('calendar/delete')) ?>"
                          onsubmit="return confirm('Delete this event?')">
                        <input type="hidden" name="_csrf"    value="<?= e(App\Core\Csrf::token()) ?>">
                        <input type="hidden" name="event_id" value="<?= (int) $ev['id'] ?>">
                        <button type="submit" class="btn-del">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <p class="empty">No events yet. Add one above or click <strong>Refresh Calendar</strong> to load from config.</p>
    <?php endif; ?>
</div>

<style>
.cal-action-row { display:flex; gap:20px; flex-wrap:wrap; align-items:flex-start; }
.tr--muted-row td { opacity:.55; }
</style>
