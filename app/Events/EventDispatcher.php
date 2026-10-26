<?php
declare(strict_types=1);
namespace App\Events;

/**
 * Minimal synchronous pub/sub dispatcher sized for this MVC's request
 * lifecycle — listeners are registered once per request/process via
 * bootstrap/events.php, then dispatch() is called later in the same run.
 * A listener exception is caught and logged individually so it can never
 * break the caller (same non-fatal pattern already used throughout
 * MarketScanService).
 */
final class EventDispatcher
{
    /** @var array<string, callable[]> */
    private static array $listeners = [];

    public static function listen(string $eventClass, callable $listener): void
    {
        self::$listeners[$eventClass][] = $listener;
    }

    public static function dispatch(object $event): void
    {
        $eventClass = $event::class;

        foreach (self::$listeners[$eventClass] ?? [] as $listener) {
            try {
                $listener($event);
            } catch (\Throwable $e) {
                error_log("[EventDispatcher] Listener for {$eventClass} failed: " . $e->getMessage());
            }
        }
    }
}
