<div class="card small-card">
    <?php $loginLogo = App\Branding\BrandingSettings::logoUrl(); ?>
    <?php if ($loginLogo): ?>
        <img src="<?= e($loginLogo) ?>" alt="Market Signal System" class="login-logo">
    <?php endif; ?>
    <h2>Admin Login</h2>

    <form method="post" action="<?= e(url('login')) ?>">
        <input type="hidden" name="_csrf" value="<?= e((string) $csrf) ?>">

        <label for="email">Email address</label>
        <input type="email" id="email" name="email" required autocomplete="email">

        <div class="pw-label-row">
            <label for="password">Password</label>
            <a href="<?= e(url('forgot-password')) ?>" class="pw-forgot-link">Forgot password?</a>
        </div>
        <div class="pw-field">
            <input type="password" id="password" name="password" required autocomplete="current-password">
            <button type="button" class="pw-toggle" id="pw-toggle" aria-label="Show password" aria-pressed="false">
                <svg class="pw-toggle__eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="pw-toggle__eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" hidden><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a20.3 20.3 0 0 1 5.06-6.06M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a20.3 20.3 0 0 1-3.22 4.5"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
        </div>

        <button type="submit" class="btn btn-primary">Sign In</button>
    </form>
</div>

<script>
(function () {
    'use strict';
    var toggle = document.getElementById('pw-toggle');
    var input  = document.getElementById('password');
    if (!toggle || !input) { return; }
    toggle.addEventListener('click', function () {
        var showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        toggle.setAttribute('aria-pressed', String(!showing));
        toggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        toggle.querySelector('.pw-toggle__eye').hidden = !showing;
        toggle.querySelector('.pw-toggle__eye-off').hidden = showing;
    });
}());
</script>
