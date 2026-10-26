<?php
declare(strict_types=1);
namespace App\Decision;

final class ScoreResult
{
    public function __construct(
        public readonly float $confidence,
        public readonly array $breakdown,
        public readonly array $weights,
    ) {
    }
}
