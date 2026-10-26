<?php
declare(strict_types=1);
namespace App\Indicators;

use App\Indicators\Contracts\IndicatorInterface;

/**
 * Ordered list of active indicators. legacyDefaults() reproduces exactly the
 * 5 indicators IndicatorService used to compute inline — output is
 * unchanged. Register additional indicators (ADX, BollingerBands, VWAP,
 * Ichimoku, or any future one) on a registry instance; IndicatorService
 * never needs to change to pick them up.
 */
final class IndicatorRegistry
{
    /** @var IndicatorInterface[] */
    private array $indicators = [];

    public static function legacyDefaults(): self
    {
        $registry = new self();
        $registry->register(new SMA(20, 'sma_20'));
        $registry->register(new SMA(50, 'sma_50'));
        $registry->register(new EMA(12, 'ema_12'));
        $registry->register(new EMA(26, 'ema_26'));
        $registry->register(new RSI(14, 'rsi_14'));
        $registry->register(new MACD());
        $registry->register(new ATR(14, 'atr_14'));

        return $registry;
    }

    public function register(IndicatorInterface $indicator): self
    {
        $this->indicators[] = $indicator;
        return $this;
    }

    /** @return IndicatorInterface[] */
    public function all(): array
    {
        return $this->indicators;
    }
}
