<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Reports\AiUsageReport;
use App\Reports\AiVisionReport;
use App\Reports\CurrencyStrengthReport;
use App\Reports\EconomicCalendarReport;
use App\Reports\MarketScannerReport;
use App\Reports\NewsIntelligenceReport;
use App\Reports\ReportCatalog;
use App\Reports\ReportExporter;
use App\Reports\SystemHealthReport;
use App\Reports\TradingPerformanceReport;
use DateTimeImmutable;

final class ReportController extends Controller
{
    public function index(): void
    {
        require_login();

        $this->view('reports/index', [
            'title'   => 'Reports',
            'reports' => ReportCatalog::all(),
        ]);
    }

    public function show(string $slug): void
    {
        require_login();

        if (!ReportCatalog::isValid($slug)) {
            http_response_code(404);
            echo 'Unknown report.';
            return;
        }

        [$from, $to] = $this->resolveDateRange();

        try {
            $report = $this->build($slug, $from, $to);
        } catch (\Throwable $e) {
            error_log("[ReportController] report '{$slug}' failed: " . $e->getMessage());
            http_response_code(503);
            $this->view('reports/show', [
                'title'  => ReportCatalog::label($slug) . ' — Reports',
                'slug'   => $slug,
                'label'  => ReportCatalog::label($slug),
                'report' => ['unavailable' => true],
                'from'   => $from,
                'to'     => $to,
                'error'  => 'This report is temporarily unavailable. Please try again shortly.',
            ]);
            return;
        }

        $export = strtolower((string) ($_GET['export'] ?? ''));
        if ($export !== '') {
            $this->export($slug, $export, $report);
            return;
        }

        $this->view('reports/show', [
            'title'  => ReportCatalog::label($slug) . ' — Reports',
            'slug'   => $slug,
            'label'  => ReportCatalog::label($slug),
            'report' => $report,
            'from'   => $from,
            'to'     => $to,
        ]);
    }

    /** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} */
    private function resolveDateRange(): array
    {
        $toStr   = trim((string) ($_GET['to']   ?? ''));
        $fromStr = trim((string) ($_GET['from'] ?? ''));

        try {
            $to = $toStr !== '' ? new DateTimeImmutable($toStr . ' 23:59:59') : new DateTimeImmutable('now');
        } catch (\Throwable) {
            $to = new DateTimeImmutable('now');
        }
        try {
            $from = $fromStr !== '' ? new DateTimeImmutable($fromStr . ' 00:00:00') : $to->modify('-30 days');
        } catch (\Throwable) {
            $from = $to->modify('-30 days');
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $maxSpan = new \DateInterval('P2Y');
        if ($from < $to->sub($maxSpan)) {
            $from = $to->sub($maxSpan);
        }

        return [$from, $to];
    }

    private function build(string $slug, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return match ($slug) {
            'trading-performance' => TradingPerformanceReport::build($from, $to),
            'ai-usage'            => AiUsageReport::build($from, $to),
            'system-health'       => SystemHealthReport::build(),
            'scanner'             => MarketScannerReport::build($from, $to),
            'news'                => NewsIntelligenceReport::build($from, $to),
            'calendar'            => EconomicCalendarReport::build($from, $to),
            'currency-strength'   => CurrencyStrengthReport::build(),
            'ai-vision'           => AiVisionReport::build($from, $to),
            default               => [],
        };
    }

    private function export(string $slug, string $format, array $report): void
    {
        $tableSource = match ($slug) {
            'trading-performance' => TradingPerformanceReport::toTable($report),
            'ai-usage'            => AiUsageReport::toTable($report),
            'scanner'             => MarketScannerReport::toTable($report),
            'news'                => NewsIntelligenceReport::toTable($report),
            'calendar'            => EconomicCalendarReport::toTable($report),
            'currency-strength'   => CurrencyStrengthReport::toTable($report),
            'ai-vision'           => AiVisionReport::toTable($report),
            'system-health'       => SystemHealthReport::toTable($report),
            default               => ['headers' => [], 'rows' => []],
        };

        $filename = 'mss-' . $slug . '-' . now_eat()->format('Y-m-d');
        $label    = ReportCatalog::label($slug);

        match ($format) {
            'csv'   => ReportExporter::csv($filename, $tableSource['headers'], $tableSource['rows']),
            'excel' => ReportExporter::excel($filename, $label, $tableSource['headers'], $tableSource['rows']),
            'pdf'   => ReportExporter::pdf($filename, $label, $this->tableToHtml($tableSource)),
            default => null,
        };
    }

    private function tableToHtml(array $tableSource): string
    {
        $html = '<table><thead><tr>';
        foreach ($tableSource['headers'] as $h) {
            $html .= '<th>' . e((string) $h) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($tableSource['rows'] as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . e((string) $cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }
}
