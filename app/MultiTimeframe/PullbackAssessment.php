<?php
declare(strict_types=1);
namespace App\MultiTimeframe;

final class PullbackAssessment
{
    public function __construct(
        public readonly string $label,
        public readonly bool $isPullback,
        public readonly string $note,
    ) {
    }

    public function toArray(): array
    {
        return [
            'label'       => $this->label,
            'is_pullback' => $this->isPullback,
            'note'        => $this->note,
        ];
    }
}
