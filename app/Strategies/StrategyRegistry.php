<?php
declare(strict_types=1);
namespace App\Strategies;

use App\Strategies\Contracts\StrategyInterface;

final class StrategyRegistry
{
    /** @var array<string, StrategyInterface> */
    private array $strategies = [];

    public function __construct()
    {
        $this->register(new TrendFollowingStrategy());
        $this->register(new ScalpingStrategy());
        $this->register(new SwingStrategy());
        $this->register(new BreakoutStrategy());
    }

    public function register(StrategyInterface $strategy): void
    {
        $this->strategies[$strategy->name()] = $strategy;
    }

    public function get(string $name): StrategyInterface
    {
        if (isset($this->strategies[$name])) {
            return $this->strategies[$name];
        }

        // Guard against a misconfigured ACTIVE_STRATEGY env value — always
        // fall back to the one strategy guaranteed to be registered.
        return $this->strategies[$this->defaultName()] ?? $this->strategies['trend_following'];
    }

    /** Falls back to trend_following — the exact behavior this codebase always had. */
    public function defaultName(): string
    {
        return (string) env('ACTIVE_STRATEGY', 'trend_following');
    }

    /** @return array<string, StrategyInterface> */
    public function all(): array
    {
        return $this->strategies;
    }
}
