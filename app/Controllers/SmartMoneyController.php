<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\MarketData\Manager\MarketDataManager;
use App\MarketData\Manager\TimeframeCatalog;
use App\MarketData\Models\Candle;
use App\SmartMoney\MarketStructureEngine;

final class SmartMoneyController extends Controller
{
    public function index(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        $this->view('smart-money/index', [
            'title'      => 'Institutional Analysis',
            'csrf'       => Csrf::token(),
            'markets'    => configured_markets(),
            'timeframes' => array_keys(TimeframeCatalog::all()),
            'result'     => null,
        ]);
    }

    public function analyze(): void
    {
        require_login();

        if (!$this->ensureFeatureEnabled()) {
            return;
        }

        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }

        $validSymbols = array_column(configured_markets(), 'symbol');
        $symbol       = trim((string) ($_POST['symbol'] ?? ''));
        if (!\in_array($symbol, $validSymbols, true)) {
            $symbol = $validSymbols[0] ?? 'EURUSD=X';
        }
        $label = market_label_from_symbol($symbol);

        $validTimeframes = array_keys(TimeframeCatalog::all());
        $timeframe       = trim((string) ($_POST['timeframe'] ?? 'H1'));
        if (!\in_array($timeframe, $validTimeframes, true)) {
            $timeframe = 'H1';
        }

        $result = null;

        try {
            $candles = (new MarketDataManager())->getCandles($symbol, $timeframe);
            if (count($candles) < 20) {
                throw new \RuntimeException('Not enough candle data returned for this market/timeframe yet. Try again shortly.');
            }

            $rows    = array_map(static fn(Candle $c): array => $c->toLegacyRow(), $candles);
            $grouped = (new MarketStructureEngine())->analyzeGrouped($rows);

            $result = [
                'label'     => $label,
                'symbol'    => $symbol,
                'timeframe' => $timeframe,
                'bars_used' => count($rows),
                'grouped'   => $grouped,
            ];
        } catch (\Throwable $e) {
            error_log('[SmartMoneyController] Analysis failed: ' . $e->getMessage());
            $result = ['error' => $e->getMessage()];
        }

        $this->view('smart-money/index', [
            'title'             => 'Institutional Analysis',
            'csrf'              => Csrf::token(),
            'markets'           => configured_markets(),
            'timeframes'        => array_keys(TimeframeCatalog::all()),
            'result'            => $result,
            'selectedSymbol'    => $symbol,
            'selectedTimeframe' => $timeframe,
        ]);
    }

    private function ensureFeatureEnabled(): bool
    {
        if (feature_enabled('SMART_MONEY')) {
            return true;
        }

        $_SESSION['flash_error'] = 'Institutional Analysis is not enabled on this installation yet.';
        redirect(url('dashboard'));
    }
}
