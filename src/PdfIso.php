<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

require_once __DIR__ . '/PdfLayout.php';

/**
 * ISO-style controlled document (as in ISO 9001 quality documentation): a document-control
 * header box on every page (institution, title, document number, revision, issue date, page x of y),
 * numbered upper-case sections, fully ruled tables with grey heads and "Tabel n —" captions,
 * and an approval matrix. Same public API as PdfLatex so WatchPdf can typeset either style.
 */
final class PdfIso
{
    private const FONT = 'freesans';
    private const LINE = '0.5pt solid #000';
    private const HEAD_BG = '#d9d9d9';
    private const LABEL_BG = '#f2f2f2';

    private static int $section = 0;
    private static int $subsection = 0;
    private static int $table = 0;
    private static int $figure = 0;

    public static function e($value): string
    {
        return PdfLayout::e($value);
    }

    public static function begin(): string
    {
        self::$section = self::$subsection = self::$table = self::$figure = 0;
        return '<style>'
            . 'body{font-family:' . self::FONT . ';font-size:9.8pt;line-height:1.4;color:#000;}'
            . 'p{margin:0 0 2.2mm 0;text-align:justify;}'
            . 'h2{font-size:10.5pt;font-weight:bold;text-transform:uppercase;margin:6mm 0 2.5mm 0;}'
            . 'h3{font-size:10pt;font-weight:bold;margin:4mm 0 2mm 0;}'
            . '.small{font-size:8.4pt;}'
            . '</style>';
    }

    /**
     * Registers the running document-control header (repeated on every page) and returns it.
     * @param array{number?:string,revision?:string,date?:string} $meta
     */
    public static function titleBlock(string $title, string $author = '', string $date = '', array $meta = []): string
    {
        $i = PdfLayout::institution();
        $cell = 'border:' . self::LINE . ';padding:1.2mm 2mm;vertical-align:middle;';
        $row = fn(string $label, string $value) => '<tr><td style="' . $cell . 'width:38%;font-size:8pt;white-space:nowrap;">' . $label . '</td><td style="' . $cell . 'font-size:8pt;font-weight:bold;">' . $value . '</td></tr>';
        $header = '<table style="width:100%;border-collapse:collapse;font-family:' . self::FONT . ';"><tr>'
            . '<td style="' . $cell . 'width:27%;text-align:center;">'
            . ($i['logo'] ? '<img src="' . self::e($i['logo']) . '" style="height:12mm;"><br>' : '')
            . '<b style="font-size:9pt;">' . self::e(strtoupper($i['name'])) . '</b>'
            . ($i['subname'] !== '' ? '<br><span style="font-size:7.6pt;">' . self::e($i['subname']) . '</span>' : '') . '</td>'
            . '<td style="' . $cell . 'width:38%;text-align:center;font-size:11pt;font-weight:bold;text-transform:uppercase;">' . self::e($title) . '</td>'
            . '<td style="border:' . self::LINE . ';padding:0;width:35%;"><table style="width:100%;border-collapse:collapse;">'
            . $row('No. Dokumen', self::e($meta['number'] ?? '—'))
            . $row('Revisi', self::e($meta['revision'] ?? '00'))
            . $row('Tgl. Terbit', self::e($meta['date'] ?? PdfLayout::date(new \DateTimeImmutable('now'))))
            . $row('Halaman', '{PAGENO} dari {nbpg}')
            . '</table></td></tr></table>';
        // Number and preparer already sit in the control header and approval matrix; only the scope line remains.
        $subtitle = $date;
        return '<htmlpageheader name="isoHeader">' . $header . '</htmlpageheader>'
            . '<sethtmlpageheader name="isoHeader" value="on" show-this-page="1" />'
            . ($subtitle !== '' ? '<p style="text-align:center;font-size:9pt;margin:0 0 4mm 0;">' . $subtitle . '</p>' : '');
    }

    /** ISO documents open with a numbered summary section rather than an abstract. */
    public static function abstract(string $html, string $heading = 'Ringkasan'): string
    {
        return self::section($heading) . self::paragraph($html);
    }

    public static function section(string $title): string
    {
        self::$subsection = 0;
        return '<h2>' . (++self::$section) . '.&nbsp;&nbsp;' . self::e($title) . '</h2>';
    }

    public static function subsection(string $title): string
    {
        return '<h3>' . self::$section . '.' . (++self::$subsection) . '&nbsp;&nbsp;' . self::e($title) . '</h3>';
    }

    public static function paragraph(string $html): string
    {
        return '<p>' . $html . '</p>';
    }

    /**
     * Fully ruled table with a grey head and a numbered caption above it.
     * @param array<string|array{0:string,1:string}> $headers label, or [label, 'l'|'c'|'r']
     * @param array<array<string>> $rows already-escaped cells
     */
    public static function table(string $caption, array $headers, array $rows, string $empty = '', string $size = '8.8pt'): string
    {
        if (!$rows) return $empty !== '' ? self::paragraph($empty) : '';
        $align = [];
        // Caption as <h6>: mPDF's keep-with-table (use_kwt) only binds headings to the table that follows.
        $html = '<h6 style="text-align:center;font-size:9pt;font-weight:bold;margin:3.5mm 0 1.5mm 0;">Tabel ' . (++self::$table) . ' — ' . self::e($caption) . '</h6>'
            . '<table style="width:100%;border-collapse:collapse;font-size:' . $size . ';margin-bottom:3.5mm;" autosize="1"><thead><tr>';
        foreach ($headers as $i => $header) {
            [$label, $a] = is_array($header) ? $header : [$header, 'l'];
            $align[$i] = ['l' => 'left', 'c' => 'center', 'r' => 'right'][$a] ?? 'left';
            $html .= '<th style="border:' . self::LINE . ';background-color:' . self::HEAD_BG . ';text-align:center;font-weight:bold;padding:1.4mm 1.8mm;">' . self::e($label) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach (array_values($row) as $i => $cell) {
                $html .= '<td style="border:' . self::LINE . ';text-align:' . ($align[$i] ?? 'left') . ';padding:1.2mm 1.8mm;vertical-align:top;">' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    /** Label/value pairs, two pairs per row, labels on a light grey ground. */
    public static function facts(array $pairs): string
    {
        $cells = [];
        foreach ($pairs as $label => $value) $cells[] = [$label, $value];
        if (count($cells) % 2) $cells[] = ['', ''];
        $html = '<table style="width:100%;border-collapse:collapse;font-size:9pt;margin:0 0 4mm 0;">';
        foreach (array_chunk($cells, 2) as $row) {
            $html .= '<tr>';
            foreach ($row as $pair) {
                $html .= '<td style="border:' . self::LINE . ';background-color:' . self::LABEL_BG . ';padding:1.3mm 2mm;width:18%;font-weight:bold;vertical-align:top;">' . self::e($pair[0]) . '</td>'
                    . '<td style="border:' . self::LINE . ';padding:1.3mm 2mm;width:32%;vertical-align:top;">' . $pair[1] . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</table>';
    }

    /** @param array<array{0:string,1:string}> $images [data URI, caption] */
    public static function figures(array $images): string
    {
        if (!$images) return '';
        $html = '<table style="width:100%;border-collapse:collapse;margin:2mm 0 3mm 0;" autosize="1">';
        foreach (array_chunk($images, 2) as $row) {
            $html .= '<tr>';
            foreach ($row as [$src, $caption]) {
                $html .= '<td style="width:50%;text-align:center;vertical-align:top;padding:0 2mm 4mm 2mm;">'
                    . '<img src="' . $src . '" style="width:68mm;border:' . self::LINE . ';">'
                    . '<div style="font-size:8.6pt;font-weight:bold;margin-top:1.5mm;">Gambar ' . (++self::$figure) . ' — ' . self::e($caption) . '</div></td>';
            }
            if (count($row) === 1) $html .= '<td style="width:50%;"></td>';
            $html .= '</tr>';
        }
        return $html . '</table>';
    }

    /**
     * Approval matrix (Disusun / Diperiksa / Disetujui) instead of free-standing signatures.
     * @param array<array{0:string,1:string,2:string}> $columns [caption, title, name]
     */
    public static function signatures(array $columns, string $placeDate): string
    {
        $cell = 'border:' . self::LINE . ';padding:1.4mm 2mm;vertical-align:top;';
        $label = $cell . 'background-color:' . self::LABEL_BG . ';font-weight:bold;width:16%;';
        $width = round(84 / count($columns), 2);
        $html = self::section('Pengesahan')
            . '<table style="width:100%;border-collapse:collapse;font-size:9pt;page-break-inside:avoid;"><tr><td style="' . $label . '"></td>';
        foreach ($columns as $c) $html .= '<td style="' . $cell . 'background-color:' . self::HEAD_BG . ';font-weight:bold;text-align:center;width:' . $width . '%;">' . self::e(rtrim($c[0], ',')) . '</td>';
        $html .= '</tr><tr><td style="' . $label . '">Nama</td>';
        foreach ($columns as $c) $html .= '<td style="' . $cell . '">' . self::e($c[2]) . '</td>';
        $html .= '</tr><tr><td style="' . $label . '">Jabatan</td>';
        foreach ($columns as $c) $html .= '<td style="' . $cell . '">' . self::e($c[1]) . '</td>';
        $html .= '</tr><tr><td style="' . $label . 'height:18mm;">Tanda tangan</td>';
        foreach ($columns as $c) $html .= '<td style="' . $cell . '"></td>';
        $html .= '</tr><tr><td style="' . $label . '">Tanggal</td>';
        foreach ($columns as $c) $html .= '<td style="' . $cell . '"></td>';
        return $html . '</tr></table>';
    }

    public static function footer(): string
    {
        return '<table width="100%" style="border-top:' . self::LINE . ';font-family:' . self::FONT . ';font-size:7.4pt;"><tr>'
            . '<td style="padding-top:1.2mm;">Dokumen terkendali. Dilarang menggandakan atau mengubah tanpa izin.</td>'
            . '<td style="padding-top:1.2mm;text-align:right;">Dicetak dari sistem: ' . PdfLayout::date(new \DateTimeImmutable('now'), true) . '</td>'
            . '</tr></table>';
    }

    public static function mpdf(string $tempDir, string $title): \Mpdf\Mpdf
    {
        $pdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $tempDir, 'exposeVersion' => false,
            'default_font' => self::FONT,
            'margin_left' => 20, 'margin_right' => 20, 'margin_top' => 46, 'margin_bottom' => 20, 'margin_header' => 10, 'margin_footer' => 10,
            'use_kwt' => true,
        ]);
        $pdf->SetTitle($title);
        $pdf->SetAuthor(PdfLayout::institution()['name']);
        $pdf->SetCreator('Inventaris Barang Perpustakaan');
        $pdf->SetHTMLFooter(self::footer());
        return $pdf;
    }
}
