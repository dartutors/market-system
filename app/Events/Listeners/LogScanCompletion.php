<?php
declare(strict_types=1);
namespace App\Events\Listeners;

use App\Events\MarketScanCompleted;

/**
 * Proof-of-concept listener — confirms the dispatch mechanism works
 * end-to-end without changing any observable scan output. Future listeners
 * (AI Summary, Reports, Notifications, Backtesting, Dashboard refresh)
 * register alongside this one in bootstrap/events.php.
 */
final class LogScanCompletion
{
    public function __invoke(MarketScanCompleted $event): void
    {
        $status = (string) ($event->result['status'] ?? 'unknown');
        error_log(
            '[EventDispatcher] MarketScanCompleted — status=' . $status
            . ' at ' . $event->completedAt->format('Y-m-d H:i:s')
        );
    }
}
