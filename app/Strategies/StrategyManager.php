<?php
declare(strict_types=1);
namespace App\Strategies;

/**
 * The scanner's entry point into the Strategy Engine. SignalEngineService
 * delegates its scoring to this class, which resolves the active (or
 * explicitly named) strategy via the registry.
 */
final class StrategyManager
{
    public function __construct(private StrategyRegistry $registry = new StrategyRegistry())
    {
    }

    public function evaluate(array $rows, array $sentimentSummary, ?string $strategyName = null): array
    {
        $strategy = $this->registry->get($strategyName ?? $this->registry->defaultName());

        return $strategy->evaluate($rows, $sentimentSummary);
    }
}
