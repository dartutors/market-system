<?php
declare(strict_types=1);
namespace App\Indicators\Contracts;

/**
 * Common contract every indicator implements. IndicatorService iterates a
 * registry of these instead of calling hardcoded private methods — adding a
 * new indicator means adding a class and registering it, not editing
 * IndicatorService.
 */
interface IndicatorInterface
{
    /** Identifies this indicator for registry listing/logging. */
    public function key(): string;

    /**
     * Takes the OHLC row series and returns it with this indicator's own
     * output column(s) appended to every row. Must not remove or overwrite
     * keys it doesn't own.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public function apply(array $rows): array;
}
