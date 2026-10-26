<?php
declare(strict_types=1);
namespace App\SmartMoney\Support;

/**
 * Shared "last opposite candle before an impulsive move" finder — one
 * geometric definition consumed by two complementary detectors:
 * OrderBlockDetector reports the still-fresh (unmitigated) zones,
 * MitigationBlockDetector reports the ones price has since traded back
 * through. An impulsive move is IMPULSE_BARS consecutive bars whose total
 * % change clears IMPULSE_MIN_MOVE_PCT.
 */
final class OrderBlockFinder
{
    private const IMPULSE_BARS = 3;
    private const IMPULSE_MIN_MOVE_PCT = 0.015; // 1.5% over IMPULSE_BARS bars

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{index:int, direction:string, high:float, low:float, impulseEndIndex:int, mitigatedAtIndex:?int}>
     */
    public static function findAll(array $rows): array
    {
        $count   = count($rows);
        $results = [];
        if ($count < self::IMPULSE_BARS + 2) {
            return $results;
        }

        for ($i = 0; $i < $count - self::IMPULSE_BARS - 1; $i++) {
            $impulseStart = $i + 1;
            $impulseEnd   = $impulseStart + self::IMPULSE_BARS - 1;
            if ($impulseEnd >= $count) {
                break;
            }

            $openOfImpulse  = (float) $rows[$impulseStart]['open'];
            $closeOfImpulse = (float) $rows[$impulseEnd]['close'];
            if ($openOfImpulse <= 0.0) {
                continue;
            }
            $movePct = ($closeOfImpulse - $openOfImpulse) / $openOfImpulse;

            $candidate       = $rows[$i];
            $candidateOpen   = (float) $candidate['open'];
            $candidateClose  = (float) $candidate['close'];
            $isBearishCandle = $candidateClose < $candidateOpen;
            $isBullishCandle = $candidateClose > $candidateOpen;

            $direction = null;
            if ($movePct >= self::IMPULSE_MIN_MOVE_PCT && $isBearishCandle) {
                $direction = 'bullish'; // last down candle before a strong up move
            } elseif ($movePct <= -self::IMPULSE_MIN_MOVE_PCT && $isBullishCandle) {
                $direction = 'bearish'; // last up candle before a strong down move
            }
            if ($direction === null) {
                continue;
            }

            $zoneHigh = (float) $candidate['high'];
            $zoneLow  = (float) $candidate['low'];

            $mitigatedAtIndex = null;
            for ($j = $impulseEnd + 1; $j < $count; $j++) {
                $laterHigh        = (float) $rows[$j]['high'];
                $laterLow         = (float) $rows[$j]['low'];
                $returnedIntoZone = $direction === 'bullish'
                    ? $laterLow <= $zoneHigh
                    : $laterHigh >= $zoneLow;
                if ($returnedIntoZone) {
                    $mitigatedAtIndex = $j;
                    break;
                }
            }

            $results[] = [
                'index'            => $i,
                'direction'        => $direction,
                'high'             => $zoneHigh,
                'low'              => $zoneLow,
                'impulseEndIndex'  => $impulseEnd,
                'mitigatedAtIndex' => $mitigatedAtIndex,
            ];
        }

        return $results;
    }
}
