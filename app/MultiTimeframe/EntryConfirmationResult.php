<?php
declare(strict_types=1);
namespace App\MultiTimeframe;

final class EntryConfirmationResult
{
    /**
     * @param string $state 'no_setup'|'pullback_only'|'confirmed'
     * @param array<string> $confirmingTimeframes Lower timeframes showing resumption in the bias direction.
     * @param array<string> $pullbackTimeframes Timeframes currently assessed as a healthy pullback.
     */
    public function __construct(
        public readonly bool $confirmed,
        public readonly string $state,
        public readonly string $reason,
        public readonly array $confirmingTimeframes,
        public readonly array $pullbackTimeframes,
    ) {
    }

    public function toArray(): array
    {
        return [
            'confirmed'             => $this->confirmed,
            'state'                 => $this->state,
            'reason'                => $this->reason,
            'confirming_timeframes' => $this->confirmingTimeframes,
            'pullback_timeframes'   => $this->pullbackTimeframes,
        ];
    }
}
