<?php
declare(strict_types=1);

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['user_email']);
}
function require_login(): void {
    if (!is_logged_in()) {
        redirect(url('login'));
    }
}
function is_admin(): bool {
    return is_logged_in() && !empty($_SESSION['is_admin']);
}
function require_admin(): void {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('Administrator access required.');
    }
}
