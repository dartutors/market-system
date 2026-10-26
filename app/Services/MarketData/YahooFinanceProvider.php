<?php
declare(strict_types=1);
namespace App\Services\MarketData;

use App\Contracts\MarketDataProviderInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Single canonical Yahoo Finance client — extracted from the two
 * near-identical Guzzle blocks that used to live independently in
 * MarketDataService::fetchCandles() and BacktestService::fetchCandles().
 *
 * Note: the two originals used slightly different Guzzle timeouts (20s vs
 * 25s) and slightly different exception wording. This merged version uses
 * 25s/10s and one consistent message — a disclosed minor convergence, not a
 * change to the data shape either call site depends on.
 */
final class YahooFinanceProvider implements MarketDataProviderInterface
{
    public function fetchCandles(string $symbol, string $timeframe, string $range): array
    {
        $url = 'https://query1.finance.yahoo.com/v8/finance/chart/'
             . urlencode($symbol)
             . '?interval=' . urlencode($timeframe)
             . '&range=' . urlencode($range);

        $client = new Client([
            'timeout'         => 25,
            'connect_timeout' => 10,
            'http_errors'     => false,
            'headers'         => [
                'User-Agent' => 'Mozilla/5.0 MarketSignalSystem/1.0',
                'Accept'     => 'application/json',
            ],
        ]);

        try {
            $response = $client->get($url);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Yahoo Finance unreachable for ' . $symbol . ': ' . $e->getMessage(), 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Yahoo Finance returned HTTP ' . $response->getStatusCode() . ' for ' . $symbol . '.');
        }

        $payload = json_decode((string) $response->getBody(), true);
        $result  = $payload['chart']['result'][0] ?? null;

        if (!$result) {
            throw new RuntimeException('No chart data returned from Yahoo Finance for ' . $symbol . '.');
        }

        $timestamps = $result['timestamp'] ?? [];
        $quote      = $result['indicators']['quote'][0] ?? [];
        $rows       = [];

        foreach ($timestamps as $i => $ts) {
            $o = $quote['open'][$i]  ?? null;
            $h = $quote['high'][$i]  ?? null;
            $l = $quote['low'][$i]   ?? null;
            $c = $quote['close'][$i] ?? null;

            if ($o === null || $h === null || $l === null || $c === null) {
                continue;
            }

            $rows[] = [
                'timestamp' => (int) $ts,
                'open'      => (float) $o,
                'high'      => (float) $h,
                'low'       => (float) $l,
                'close'     => (float) $c,
                'volume'    => (float) ($quote['volume'][$i] ?? 0),
            ];
        }

        return array_values($rows);
    }
}
