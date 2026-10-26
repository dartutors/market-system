<?php
declare(strict_types=1);
namespace App\Events;

use DateTimeImmutable;

final class MarketScanCompleted
{
    public function __construct(
        public readonly array $result,
        public readonly DateTimeImmutable $completedAt,
    ) {
    }
}
