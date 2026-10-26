<?php
declare(strict_types=1);
namespace App\Modules;

use App\Modules\Contracts\ModuleInterface;

/**
 * Static registry, same pattern as App\Events\EventDispatcher — populated
 * once per request via bootstrap/modules.php, inspectable for the rest of
 * that request. Nothing reads from this yet; it exists so future work can
 * query "what does the Scanner module own" without grepping the codebase.
 */
final class ModuleRegistry
{
    /** @var array<string, ModuleInterface> */
    private static array $modules = [];

    public static function register(ModuleInterface $module): void
    {
        self::$modules[$module->name()] = $module;
    }

    public static function find(string $name): ?ModuleInterface
    {
        return self::$modules[$name] ?? null;
    }

    /** @return array<string, ModuleInterface> */
    public static function all(): array
    {
        return self::$modules;
    }
}
