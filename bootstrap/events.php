<?php
declare(strict_types=1);

use App\Events\EventDispatcher;
use App\Events\Listeners\LogScanCompletion;
use App\Events\MarketScanCompleted;

EventDispatcher::listen(MarketScanCompleted::class, new LogScanCompletion());

// Future listeners register here — AI Summary, Reports, Notifications,
// Backtesting, Dashboard refresh — without MarketScanService needing to
// know about any of them.
