<?php
declare(strict_types=1);
namespace App\Reports;

use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Shared CSV/PDF/Excel output for every report — one implementation instead
 * of eight. Each method ends the request itself (sets headers, streams
 * bytes, exits) matching this app's router convention of the controller
 * action being the last thing that runs before `exit` in public/index.php.
 */
final class ReportExporter
{
    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, string|int|float|null>> $rows
     */
    public static function csv(string $filename, array $headers, array $rows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    public static function pdf(string $filename, string $title, string $bodyHtml): never
    {
        $options = new DompdfOptions();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(self::pdfShell($title, $bodyHtml));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
        echo $dompdf->output();
        exit;
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, string|int|float|null>> $rows
     */
    public static function excel(string $filename, string $title, array $headers, array $rows): never
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($title, 0, 31) ?: 'Report');

        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        (new Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    private static function pdfShell(string $title, string $bodyHtml): string
    {
        $generatedAt = e(now_eat()->format('M j, Y H:i \E\A\T'));
        $safeTitle   = e($title);
        $logoHtml    = self::embeddedLogoHtml();
        return <<<HTML
        <!DOCTYPE html>
        <html><head><meta charset="utf-8"><style>
            body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1a2434; }
            .hd { margin-bottom: 14px; }
            .hd img { height: 32px; margin-bottom: 8px; }
            h1 { font-size: 18px; margin-bottom: 2px; }
            .meta { color: #6b7688; font-size: 10px; margin-bottom: 16px; }
            table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            th, td { border-bottom: 1px solid #dde3ee; padding: 5px 8px; text-align: left; font-size: 10.5px; }
            th { background: #f1f4f9; text-transform: uppercase; letter-spacing: .04em; font-size: 8.5px; color: #56637a; }
            .stat-row td { padding: 4px 8px; }
            .label { color: #56637a; }
        </style></head><body>
            <div class="hd">
                {$logoHtml}
                <h1>{$safeTitle}</h1>
                <p class="meta">Market Signal System — generated {$generatedAt}</p>
            </div>
            {$bodyHtml}
        </body></html>
        HTML;
    }

    /** Base64-embedded — dompdf has isRemoteEnabled(false), so a URL <img> would silently fail to render. */
    private static function embeddedLogoHtml(): string
    {
        $path = \App\Branding\BrandingSettings::logoFilesystemPath();
        if ($path === null) {
            return '';
        }
        $mime = (string) mime_content_type($path);
        $data = base64_encode((string) file_get_contents($path));
        return '<img src="data:' . e($mime) . ';base64,' . $data . '" alt="Logo">';
    }
}
