<header class="topbar">
    <div class="topbar-left">
        <?php if (is_logged_in()): ?>
        <button type="button" class="sb-toggle" id="sb-toggle" aria-label="Toggle navigation" aria-controls="sidebar" aria-expanded="false">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <?php endif; ?>
        <div class="topbar-brand">
            <?php $topbarLogo = App\Branding\BrandingSettings::logoUrl(); ?>
            <?php if ($topbarLogo): ?>
                <img src="<?= e($topbarLogo) ?>" alt="Market Signal System" class="topbar-logo">
            <?php else: ?>
                <span class="topbar-dot"></span>
            <?php endif; ?>
            <h1>Market Signal System</h1>
        </div>
        <p class="topbar-sub">Trading Intelligence Command Center</p>
        <?php if (is_logged_in()): ?>
        <div class="topbar-markets">
            <?php foreach (configured_markets() as $m): ?>
                <span class="market-pill"><?= e($m['label']) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="topbar-right">
        <?php if (is_logged_in()): ?>
        <button type="button" class="theme-toggle" id="notify-toggle" aria-label="Toggle browser notifications" title="Toggle browser notifications for high-confidence signals and session opens">
            <svg id="notify-icon-on" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <svg id="notify-icon-off" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/><line x1="2" y1="2" x2="22" y2="22"/></svg>
        </button>
        <?php endif; ?>
        <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Toggle light/dark theme" title="Toggle light/dark theme">
            <svg class="theme-toggle__sun" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
            <svg class="theme-toggle__moon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg>
        </button>
        <div class="topbar-clock-wrap">
            <span class="topbar-clock-label">EAT</span>
            <span class="topbar-clock" id="topbar-eat">--:--:--</span>
        </div>
        <?php if (is_logged_in()): ?>
            <a class="btn btn-ghost" href="<?= e(url('logout')) ?>">Logout</a>
        <?php endif; ?>
    </div>
</header>
<script>
(function () {
    var el = document.getElementById('topbar-eat');
    if (!el) return;
    var fmt = new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Dar_es_Salaam', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
    function tick() { el.textContent = fmt.format(new Date()); }
    tick(); setInterval(tick, 1000);
}());
</script>

<?php if (is_logged_in()): ?>
<!--
  Shared browser-notification helper, used by this toggle and by the
  dashboard's own polling loops (High Confidence Signal / Market Open
  triggers). Global on window.MSSNotify so any page's inline script can call
  it without a bundler/module system — matches this app's existing
  no-build-step convention for all its other inline page scripts.
-->
<script>
(function () {
    'use strict';
    var STORAGE_KEY = 'mss-notify-enabled';

    function isEnabled() {
        return localStorage.getItem(STORAGE_KEY) === '1' && window.Notification && Notification.permission === 'granted';
    }

    function updateIcon() {
        var onIcon = document.getElementById('notify-icon-on');
        var offIcon = document.getElementById('notify-icon-off');
        if (!onIcon || !offIcon) return;
        var enabled = isEnabled();
        onIcon.style.display = enabled ? '' : 'none';
        offIcon.style.display = enabled ? 'none' : '';
    }

    function notify(title, body, tag) {
        if (!isEnabled()) return;
        try {
            new Notification(title, { body: body, tag: tag, icon: <?= json_encode(App\Branding\BrandingSettings::hasFavicon() ? App\Branding\BrandingSettings::faviconUrl(64) : '') ?> || undefined });
        } catch (e) { /* Notification constructor can throw on some mobile browsers — silently skip */ }
    }

    window.MSSNotify = { isEnabled: isEnabled, notify: notify };

    var toggle = document.getElementById('notify-toggle');
    if (toggle) {
        toggle.addEventListener('click', function () {
            if (!window.Notification) {
                alert('Browser notifications are not supported in this browser.');
                return;
            }
            if (Notification.permission === 'denied') {
                alert('Notifications are blocked for this site in your browser settings — enable them there to use this.');
                return;
            }
            if (Notification.permission === 'granted') {
                var next = localStorage.getItem(STORAGE_KEY) === '1' ? '0' : '1';
                localStorage.setItem(STORAGE_KEY, next);
                updateIcon();
                return;
            }
            Notification.requestPermission().then(function (perm) {
                if (perm === 'granted') {
                    localStorage.setItem(STORAGE_KEY, '1');
                    notify('Market Signal System', 'Notifications enabled — you\'ll be alerted on high-confidence signals and session opens.', 'mss-enabled');
                }
                updateIcon();
            });
        });
        updateIcon();
    }
}());
</script>
<?php endif; ?>
