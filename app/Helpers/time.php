<?php
declare(strict_types=1);

function now_eat(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone((string) env('APP_TIMEZONE', 'Africa/Dar_es_Salaam')));
}

// Delegates to App\Services\TradingSessionService — same windows and Friday
// rule as before, just centralized so future modules can reference the
// service directly instead of recalculating sessions independently.
function current_session_name(?DateTimeImmutable $dt = null): string {
    return (new App\Services\TradingSessionService())->legacySessionName($dt);
}

function max_signals_for_day(?DateTimeImmutable $dt = null): int {
    return (new App\Services\TradingSessionService())->maxSignalsForDay($dt);
}
