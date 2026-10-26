<?php
declare(strict_types=1);
namespace App\Modules\Core;

use App\Modules\Contracts\ModuleInterface;

final class CoreModule implements ModuleInterface
{
    public function name(): string { return 'core'; }

    public function routes(): array { return ['/', '/login', '/logout']; }

    public function services(): array
    {
        return [
            \App\Core\Database::class, \App\Core\Session::class, \App\Core\Security::class,
            \App\Core\Csrf::class, \App\Services\AuthService::class, \App\Services\LoginThrottleService::class,
        ];
    }

    public function config(): array
    {
        return ['APP_*', 'DB_*', 'SESSION_NAME', 'COOKIE_*', 'LOGIN_MAX_ATTEMPTS', 'LOGIN_WINDOW_MINUTES'];
    }

    public function events(): array { return []; }

    public function permissions(): array { return ['guest']; }
}
