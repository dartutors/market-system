<?php
declare(strict_types=1);
namespace App\Modules\Contracts;

/**
 * Descriptive metadata only — implementations report what they already own
 * (real routes, real services) for documentation/inspection purposes. This
 * sprint creates the registry infrastructure only; the actual router in
 * public/index.php and actual service instantiation are untouched.
 */
interface ModuleInterface
{
    public function name(): string;

    /** @return array<string> Real route paths this module owns today */
    public function routes(): array;

    /** @return array<string> Fully-qualified class names this module provides */
    public function services(): array;

    /** @return array<string> Env/config keys this module owns */
    public function config(): array;

    /** @return array<string> Event class names this module dispatches or listens to */
    public function events(): array;

    /** @return array<string> Access requirements (e.g. 'authenticated', 'admin') */
    public function permissions(): array;
}
