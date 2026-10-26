<?php
declare(strict_types=1);
namespace App\MarketData\Exceptions;

/** Thrown when a provider is asked for a timeframe it structurally cannot serve (e.g. Deriv has no native Weekly/Monthly candle granularity). */
final class UnsupportedTimeframeException extends MarketDataException
{
}
