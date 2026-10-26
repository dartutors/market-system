<div class="card small-card">
    <?php $rpLogo = App\Branding\BrandingSettings::logoUrl(); ?>
    <?php if ($rpLogo): ?>
        <img src="<?= e($rpLogo) ?>" alt="Market Signal System" class="login-logo">
    <?php endif; ?>
    <h2>Reset Password</h2>
    <p class="auth-hint">Choose a new password — at least 8 characters.</p>

    <form method="post" action="<?= e(url('reset-password')) ?>">
        <input type="hidden" name="_csrf" value="<?= e((string) $csrf) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <label for="password">New password</label>
        <div class="pw-field">
            <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
            <button type="button" class="pw-toggle" data-target="password" aria-label="Show password" aria-pressed="false">
                <svg class="pw-toggle__eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="pw-toggle__eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" hidden><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a20.3 20.3 0 0 1 5.06-6.06M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a20.3 20.3 0 0 1-3.22 4.5"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
        </div>

        <label for="password_confirm">Confirm new password</label>
        <div class="pw-field">
            <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
            <button type="button" class="pw-toggle" data-target="password_confirm" aria-label="Show password" aria-pressed="false">
                <svg class="pw-toggle__eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="pw-toggle__eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" hidden><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a20.3 20.3 0 0 1 5.06-6.06M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a20.3 20.3 0 0 1-3.22 4.5"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
        </div>

        <button type="submit" class="btn btn-primary">Update Password</button>
    </form>

    <p class="auth-back"><a href="<?= e(url('login')) ?>">← Back to login</a></p>
</div>

<script>
(function () {
    'use strict';
    document.querySelectorAll('.pw-toggle').forEach(function (toggle) {
        var input = document.getElementById(toggle.getAttribute('data-target'));
        if (!input) { return; }
        toggle.addEventListener('click', function () {
            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            toggle.setAttribute('aria-pressed', String(!showing));
            toggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            toggle.querySelector('.pw-toggle__eye').hidden = !showing;
            toggle.querySelector('.pw-toggle__eye-off').hidden = showing;
        });
    });
}());
</script>
