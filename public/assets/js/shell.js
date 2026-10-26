/**
 * Global chrome behavior: mobile sidebar drawer + light/dark theme toggle.
 * Vanilla JS, no dependencies — matches the rest of this codebase's approach.
 */
(function () {
    'use strict';

    // ── Mobile sidebar drawer ───────────────────────────────────────────
    var toggle = document.getElementById('sb-toggle');
    var sidebar = document.getElementById('sidebar');
    var scrim = document.getElementById('sb-scrim');

    function closeSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('is-open');
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
    }
    function openSidebar() {
        if (!sidebar) return;
        sidebar.classList.add('is-open');
        if (toggle) toggle.setAttribute('aria-expanded', 'true');
    }
    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            sidebar.classList.contains('is-open') ? closeSidebar() : openSidebar();
        });
    }
    if (scrim) { scrim.addEventListener('click', closeSidebar); }
    if (sidebar) {
        sidebar.querySelectorAll('a.sb-link').forEach(function (a) {
            a.addEventListener('click', closeSidebar);
        });
    }

    // ── Theme toggle (persists in localStorage, default dark) ──────────
    var themeBtn = document.getElementById('theme-toggle');
    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            var isLight = document.documentElement.getAttribute('data-theme') === 'light';
            if (isLight) {
                document.documentElement.removeAttribute('data-theme');
                try { localStorage.setItem('mss-theme', 'dark'); } catch (e) {}
            } else {
                document.documentElement.setAttribute('data-theme', 'light');
                try { localStorage.setItem('mss-theme', 'light'); } catch (e) {}
            }
        });
    }
}());
