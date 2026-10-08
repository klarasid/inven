<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

/**
 * Shared letterhead, typography and footer for every PDF the plugin prints,
 * so the room card, period report and inspection document read as one document family.
 * Markup stays within what mPDF renders reliably: tables, borders and background colours.
 */
final class PdfLayout
{
    public const ACCENT = '#1e3a5f';
    private const MONTHS = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /**
     * Where a long document may be handed to mPDF in pieces (Documents::write). mPDF refuses a
     * piece of HTML longer than pcre.backtrack_limit, 1 MB unless the server raised it.
     */
    public const CHUNK = '<!--chunk-->';

    public static function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** "12 Februari 2026"; accepts Y-m-d, datetime strings or DateTimeInterface. */
    public static function date($value, bool $withTime = false): string
    {
        if ($value === null || $value === '') return '—';
        try {
            $date = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string) $value);
        } catch (\Exception $e) {
            return self::e($value);
        }
        return $date->format('j') . ' ' . self::MONTHS[(int) $date->format('n')] . ' ' . $date->format('Y') . ($withTime ? ', ' . $date->format('H.i') : '');
    }

    /** Compact date for table cells: "12 Feb 2026". */
    public static function shortDate($value): string
    {
        if ($value === null || $value === '') return '—';
        try {
            $date = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string) $value);
        } catch (\Exception $e) {
            return self::e($value);
        }
        return $date->format('j') . '&nbsp;' . substr(self::MONTHS[(int) $date->format('n')], 0, 3) . '&nbsp;' . $date->format('Y');
    }

    public static function money($value): string
    {
        $number = (float) ($value ?? 0);
        return $number > 0 ? 'Rp ' . number_format($number, 0, ',', '.') : '—';
    }

    /** Library identity from SLiMS settings; tests pass it explicitly. */
    public static function institution(?array $override = null): array
    {
        if ($override !== null) return $override + ['name' => '', 'subname' => '', 'logo' => null];
        $sysconf = $GLOBALS['sysconf'] ?? [];
        $logo = null;
        if (!empty($sysconf['logo_image']) && defined('SB')) {
            $path = SB . 'images' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . basename((string) $sysconf['logo_image']);
            if (is_file($path) && is_readable($path) && filesize($path) < 2 * 1024 * 1024) $logo = $path;
        }
        return ['name' => (string) ($sysconf['library_name'] ?? 'Perpustakaan'), 'subname' => (string) ($sysconf['library_subname'] ?? ''), 'logo' => $logo];
    }

    public static function css(string $extra = ''): string
    {
        $a = self::ACCENT;
        return '<style>'
            . 'body{font-family:dejavusans;font-size:8.6pt;color:#1f2937;line-height:1.35;}'
            . '.letterhead{width:100%;border-collapse:collapse;border-bottom:0.6mm solid ' . $a . ';margin-bottom:1mm;}'
            . '.letterhead td{vertical-align:middle;padding:0 0 2.5mm 0;}'
            . '.lh-logo{width:15mm;}.lh-logo img{width:13mm;}'
            . '.lh-name{font-size:12pt;font-weight:bold;color:' . $a . ';}.lh-sub{font-size:8pt;color:#4b5563;letter-spacing:0.3pt;}'
            . '.lh-doc{text-align:right;font-size:7.5pt;color:#4b5563;}.lh-docno{font-size:9pt;font-weight:bold;color:#111827;}'
            . '.rule{border-bottom:0.2mm solid ' . $a . ';margin-bottom:4mm;}'
            . 'h1{font-size:13.5pt;font-weight:bold;color:#111827;margin:0 0 1mm 0;letter-spacing:0.4pt;}'
            . '.subtitle{font-size:9pt;color:#4b5563;margin:0 0 4mm 0;}'
            . 'h2{font-size:10pt;font-weight:bold;color:' . $a . ';margin:5.5mm 0 2mm 0;padding-bottom:1mm;border-bottom:0.2mm solid #cbd5e1;text-transform:uppercase;letter-spacing:0.4pt;}'
            . 'h3{font-size:9pt;font-weight:bold;margin:3mm 0 1.5mm 0;color:#111827;}'
            . 'p{margin:0 0 1.5mm 0;}'
            . '.muted{color:#6b7280;}.small{font-size:7.4pt;}.strong{font-weight:bold;}.right{text-align:right;}.center{text-align:center;}'
            . '.meta{width:100%;border-collapse:collapse;margin-bottom:2mm;}'
            . '.meta td{padding:1.1mm 2mm;vertical-align:top;border-bottom:0.15mm solid #e5e7eb;}'
            . '.meta .k{width:21%;color:#6b7280;}.meta .v{width:29%;font-weight:bold;color:#111827;}'
            . '.grid{width:100%;border-collapse:collapse;margin-bottom:2mm;}'
            . '.grid th{background-color:' . $a . ';color:#ffffff;font-size:7.6pt;font-weight:bold;text-align:left;padding:1.6mm 1.8mm;border:0.15mm solid ' . $a . ';}'
            . '.grid td{padding:1.4mm 1.8mm;border:0.15mm solid #d1d5db;vertical-align:top;font-size:8pt;}'
            . '.grid tr.alt td{background-color:#f8fafc;}'
            . '.grid td.num,.grid th.num{text-align:right;}.grid td.no,.grid th.no{text-align:center;width:7mm;}'
            . '.kpi{width:100%;border-collapse:separate;border-spacing:2mm 0;margin:0 -2mm 3mm -2mm;}'
            . '.kpi td{border:0.2mm solid #d1d5db;border-top:0.8mm solid ' . $a . ';padding:2.2mm 3mm;vertical-align:top;background-color:#f8fafc;}'
            . '.kpi .label{font-size:7.2pt;color:#4b5563;text-transform:uppercase;letter-spacing:0.3pt;}'
            . '.kpi .value{font-size:15pt;font-weight:bold;color:#111827;}'
            . '.kpi .hint{font-size:7pt;color:#6b7280;}'
            . '.tag{font-size:7.2pt;font-weight:bold;padding:0.3mm 1.5mm;}'
            . '.t-good{color:#166534;}.t-bad{color:#b91c1c;}.t-warn{color:#b45309;}.t-muted{color:#4b5563;}'
            . '.note{background-color:#f8fafc;border-left:0.8mm solid #94a3b8;padding:2mm 3mm;font-size:7.6pt;color:#4b5563;margin:2mm 0;}'
            . '.card{padding:1mm 0 1mm 0;margin-bottom:4mm;}'
            . '.photos{border-collapse:collapse;margin:1.5mm 0 2mm 0;}.photos td{padding:0 2mm 2mm 0;vertical-align:top;font-size:6.8pt;color:#6b7280;}'
            . '.photos img{width:40mm;border:0.2mm solid #d1d5db;}'
            . '.sign{width:100%;border-collapse:collapse;margin-top:8mm;page-break-inside:avoid;}'
            . '.sign td{width:50%;text-align:center;vertical-align:top;padding:0 8mm;font-size:8.6pt;}'
            . '.sign .space{height:17mm;}.sign .name{font-weight:bold;text-decoration:underline;}'
            . $extra . '</style>';
    }

    /** Letterhead with the document number on the right, then the document title block. */
    public static function header(string $title, string $subtitle = '', string $docLabel = '', string $docNumber = '', ?array $institution = null): string
    {
        $i = self::institution($institution);
        $logo = $i['logo'] ? '<td class="lh-logo"><img src="' . self::e($i['logo']) . '"></td>' : '';
        return '<table class="letterhead"><tr>' . $logo
            . '<td><div class="lh-name">' . self::e(strtoupper($i['name'])) . '</div>'
            . ($i['subname'] !== '' ? '<div class="lh-sub">' . self::e($i['subname']) . '</div>' : '') . '</td>'
            . '<td class="lh-doc">' . ($docLabel !== '' ? self::e($docLabel) . '<br><span class="lh-docno">' . self::e($docNumber) . '</span>' : '') . '</td>'
            . '</tr></table><div class="rule"></div>'
            . ($title !== '' ? '<h1>' . self::e($title) . '</h1>' : '')
            . ($subtitle !== '' ? '<p class="subtitle">' . $subtitle . '</p>' : '');
    }

    /** Two label/value pairs per row. */
    public static function meta(array $pairs): string
    {
        $cells = [];
        foreach ($pairs as $label => $value) $cells[] = '<td class="k">' . self::e($label) . '</td><td class="v">' . $value . '</td>';
        if (count($cells) % 2) $cells[] = '<td class="k"></td><td class="v"></td>';
        $html = '<table class="meta">';
        foreach (array_chunk($cells, 2) as $row) $html .= '<tr>' . implode('', $row) . '</tr>';
        return $html . '</table>';
    }

    /** @param array<array{0:string,1:string|int,2?:string}> $items label, value, hint */
    public static function kpis(array $items): string
    {
        $html = '<table class="kpi"><tr>';
        foreach ($items as $item) {
            $html .= '<td width="' . round(100 / count($items), 2) . '%">'
                . '<div style="font-size:6.9pt;color:#4b5563;text-transform:uppercase;letter-spacing:0.3pt;">' . self::e($item[0]) . '</div>'
                . '<div style="font-size:16pt;font-weight:bold;color:#111827;line-height:1.15;">' . self::e($item[1]) . '</div>'
                . (!empty($item[2]) ? '<div style="font-size:6.9pt;color:#6b7280;">' . self::e($item[2]) . '</div>' : '') . '</td>';
        }
        return $html . '</tr></table>';
    }

    /** @param string[] $headers @param array<array<string>> $rows already-escaped cells */
    public static function table(array $headers, array $rows, string $empty = 'Tidak ada data.'): string
    {
        if (!$rows) return '<p class="muted">' . self::e($empty) . '</p>';
        $html = '<table class="grid"><thead><tr>';
        foreach ($headers as $header) {
            [$label, $class] = is_array($header) ? $header : [$header, ''];
            $html .= '<th' . ($class ? ' class="' . $class . '"' : '') . '>' . self::e($label) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach (array_values($rows) as $n => $row) {
            $html .= '<tr' . ($n % 2 ? ' class="alt"' : '') . '>';
            foreach ($row as $index => $cell) {
                $class = is_array($headers[$index] ?? null) ? $headers[$index][1] : '';
                $html .= '<td' . ($class ? ' class="' . $class . '"' : '') . '>' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    /** Signature block; each column: [caption, title, name, identity]. */
    public static function signatures(array $columns, string $placeDate = ''): string
    {
        $html = '<table class="sign" style="page-break-inside:avoid">';
        if ($placeDate !== '') $html .= '<tr>' . str_repeat('<td></td>', count($columns) - 1) . '<td style="padding-bottom:1mm">' . $placeDate . '</td></tr>';
        $html .= '<tr>';
        foreach ($columns as $c) $html .= '<td>' . self::e($c[0]) . '<br>' . ($c[1] !== '' ? self::e($c[1]) : '&nbsp;') . '</td>';
        $html .= '</tr><tr>';
        foreach ($columns as $c) $html .= '<td style="height:18mm"></td>';
        $html .= '</tr><tr>';
        foreach ($columns as $c) $html .= '<td><span style="font-weight:bold;text-decoration:underline;">' . ($c[2] !== '' ? self::e($c[2]) : str_repeat('&nbsp;', 40)) . '</span>'
            . (($c[3] ?? '') !== '' ? '<br><span style="font-size:7.6pt;">' . self::e($c[3]) . '</span>' : '') . '</td>';
        return $html . '</tr></table>';
    }

    public static function footer(string $label, ?array $institution = null): string
    {
        $i = self::institution($institution);
        return '<table width="100%" style="border-top:0.2mm solid #cbd5e1;font-family:dejavusans;font-size:6.8pt;color:#6b7280;"><tr>'
            . '<td style="padding-top:1.5mm;">' . self::e($label) . ' · ' . self::e($i['name']) . '</td>'
            . '<td style="padding-top:1.5mm;text-align:right;">Dicetak ' . self::date(new \DateTimeImmutable('now'), true) . ' · Halaman {PAGENO} dari {nbpg}</td>'
            . '</tr></table>';
    }

    /** Configured mPDF instance; callers add HTML and output. */
    public static function mpdf(string $tempDir, string $title, string $footer, array $config = []): \Mpdf\Mpdf
    {
        $pdf = new \Mpdf\Mpdf($config + [
            'mode' => 'utf-8', 'format' => 'A4', 'default_font' => 'dejavusans',
            'margin_left' => 16, 'margin_right' => 16, 'margin_top' => 14, 'margin_bottom' => 16, 'margin_footer' => 7,
            'tempDir' => $tempDir, 'exposeVersion' => false,
            // Keep section headings on the same page as the table that follows them.
            'use_kwt' => true,
        ]);
        $pdf->SetTitle($title);
        $pdf->SetAuthor(self::institution()['name']);
        $pdf->SetCreator('Klaras Inven');
        $pdf->SetHTMLFooter($footer);
        return $pdf;
    }
}
