<?php
declare(strict_types=1);
namespace App\Modules\Calendar;

use App\Modules\Contracts\ModuleInterface;

final class CalendarModule implements ModuleInterface
{
    public function name(): string { return 'calendar'; }

    public function routes(): array
    {
        return ['/calendar', '/calendar/store', '/calendar/refresh', '/calendar/delete'];
    }

    public function services(): array
    {
        return [\App\Services\EconomicCalendarService::class, \App\Services\CalendarRefreshService::class];
    }

    public function config(): array
    {
        return ['USE_ECONOMIC_CALENDAR', 'HIGH_IMPACT_BLOCK_MINUTES_BEFORE', 'HIGH_IMPACT_BLOCK_MINUTES_AFTER'];
    }

    public function events(): array { return []; }

    public function permissions(): array { return ['authenticated']; }
}
