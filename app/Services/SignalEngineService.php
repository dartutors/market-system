<?php
declare(strict_types=1);
namespace App\Services;

use App\Strategies\StrategyManager;

final class SignalEngineService {
    public function __construct(private StrategyManager $strategyManager = new StrategyManager())
    {
    }

    public function evaluate(array $rows, array $sentimentSummary): array {
        $now = now_eat();
        $session = current_session_name($now);
        if ($session === 'off') {
            return [
                'action' => 'WAIT',
                'confidence' => 0,
                'session_name' => 'off',
                'entry_window_start' => null,
                'entry_window_end' => null,
                'hold_hours_min' => 0,
                'hold_hours_max' => 0,
                'stop_loss_hint' => '',
                'take_profit_hint' => '',
                'reasons' => ['Outside allowed session windows.'],
                'risk_warning' => 'No action outside approved sessions.',
            ];
        }
        if ((int) $now->format('N') === 5 && $session === 'new_york') {
            return [
                'action' => 'AVOID',
                'confidence' => 0,
                'session_name' => $session,
                'entry_window_start' => null,
                'entry_window_end' => null,
                'hold_hours_min' => 0,
                'hold_hours_max' => 0,
                'stop_loss_hint' => '',
                'take_profit_hint' => '',
                'reasons' => ['Friday afternoon trades are blocked.'],
                'risk_warning' => 'Friday late-session risk filter is active.',
            ];
        }

        $latest = $rows[count($rows)-1];
        $atr = max((float) $latest['atr_14'], 0.0001);

        // Scoring itself now lives behind the Strategy Engine — this call
        // resolves the active strategy (default: TrendFollowingStrategy,
        // extracted verbatim from what used to be inline here) and returns
        // the exact same action/confidence/reasons shape.
        $strategyResult = $this->strategyManager->evaluate($rows, $sentimentSummary);
        $action     = $strategyResult['action'];
        $confidence = $strategyResult['confidence'];
        $reasons    = $strategyResult['reasons'];

        return [
            'action' => $action,
            'confidence' => $confidence,
            'session_name' => $session,
            'entry_window_start' => $now->modify('+15 minutes')->format('Y-m-d H:i:s'),
            'entry_window_end' => $now->modify('+75 minutes')->format('Y-m-d H:i:s'),
            'hold_hours_min' => 4,
            'hold_hours_max' => 18,
            'stop_loss_hint' => 'Use a stop near 1.2 x ATR (~' . number_format($atr*1.2,5,'.','') . ').',
            'take_profit_hint' => 'Aim for at least 2R relative to risk. ATR reference ~' . number_format($atr,5,'.','') . '.',
            'reasons' => $reasons,
            'risk_warning' => 'Informational signal only. As a new trader, use paper trading first and avoid risking money you cannot lose.',
        ];
    }
}
