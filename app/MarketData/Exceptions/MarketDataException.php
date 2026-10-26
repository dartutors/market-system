<?php
declare(strict_types=1);
namespace App\MarketData\Exceptions;

use RuntimeException;

/**
 * Common base every App\MarketData\Providers\* class throws, so
 * App\MarketData\Manager\MarketDataManager can catch exactly one exception
 * type across Deriv/Yahoo/cache instead of providers each throwing plain
 * RuntimeException (which is what the old
 * App\Services\MarketData\YahooFinanceProvider still does, and continues to
 * do — this hierarchy is additive, not a change to that class).
 */
class MarketDataException extends RuntimeException
{
}
