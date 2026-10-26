<?php
declare(strict_types=1);
namespace App\MarketData\Exceptions;

/** Thrown when a specific provider (connection, timeout, HTTP/WS error) cannot serve a request right now. */
final class ProviderUnavailableException extends MarketDataException
{
}
