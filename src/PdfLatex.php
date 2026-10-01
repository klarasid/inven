<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

require_once __DIR__ . '/PdfLayout.php';

/**
 * LaTeX "article" look for the plugin's reports: Computer Modern (CMU Serif), centred title block,
 * numbered sections, booktabs tables with numbered captions, numbered figures and a plain
 * centred page number. Rules are written inline because mPDF ignores descendant selectors in tables.
 */
final class PdfLatex
{
    private const FONT = 'cmuserif';
    private const TOP = 'border-top:0.9pt solid #000;';
    private const MID = 'border-bottom:0.5pt solid #000;';
    private const BOTTOM = 'border-bottom:0.9pt solid #000;';

    private static int $section = 0;
    private static int $subsection = 0;
    private static int $table = 0;
    private static int $figure = 0;

    public static function e($value): string
    {
        return PdfLayout::e($value);
    }

    /** Resets section, table and figure counters for a new document. */
    public static function begin(): string
    {
        self::$section = self::$subsection = self::$table = self::$figure = 0;
        return '<style>'
            . 'body{font-family:' . self::FONT . ';font-size:10.5pt;line-height:1.38;color:#000;}'
            . 'p{margin:0 0 2.4mm 0;text-align:justify;}'
            . 'h2{font-size:14pt;font-weight:bold;margin:7mm 0 2.8mm 0;}'
            . 'h3{font-size:11.5pt;font-weight:bold;margin:5mm 0 2mm 0;}'
            . '.small{font-size:9pt;}'
            . '</style>';
    }

    /** $meta (document number etc.) is used by the ISO style's control header; the article style shows none. */
    public static function titleBlock(string $title, string $author = '', string $date = '', array $meta = []): string
    {
        $institution = PdfLayout::institution();
        return '<div style="text-align:center;margin-bottom:8mm;">'
            . '<div style="font-size:10pt;font-variant:small-caps;letter-spacing:0.6pt;">' . self::e($institution['name']) . '</div>'
            . '<div style="font-size:17.5pt;line-height:1.25;margin-top:7mm;">' . self::e($title) . '</div>'
            . ($author !== '' ? '<div style="font-size:12pt;margin-top:5mm;">' . $author . '</div>' : '')
            . ($date !== '' ? '<div style="font-size:12pt;margin-top:2mm;">' . $date . '</div>' : '')
            . '</div>';
    }

    /** Indented, smaller "Abstract"-style summary under the title. */
    public static function abstract(string $html, string $heading = 'Ringkasan'): string
    {
        return '<div style="margin:0 12mm 6mm 12mm;font-size:9.8pt;">'
            . '<div style="text-align:center;font-weight:bold;font-size:10pt;margin-bottom:1.5mm;">' . self::e($heading) . '</div>'
            . '<p style="text-align:justify;">' . $html . '</p></div>';
    }

    public static function section(string $title): string
    {
        self::$subsection = 0;
        return '<h2>' . (++self::$section) . '&nbsp;&nbsp;&nbsp;' . self::e($title) . '</h2>';
    }

    public static function subsection(string $title): string
    {
        return '<h3>' . self::$section . '.' . (++self::$subsection) . '&nbsp;&nbsp;&nbsp;' . self::e($title) . '</h3>';
    }

    public static function paragraph(string $html): string
    {
        return '<p>' . $html . '</p>';
    }

    /**
     * Booktabs table with a numbered caption above it.
     * @param array<string|array{0:string,1:string}> $headers label, or [label, 'l'|'c'|'r']
     * @param array<array<string>> $rows already-escaped cells
     */
    public static function table(string $caption, array $headers, array $rows, string $empty = '', string $size = '9.4pt'): string
    {
        if (!$rows) return $empty !== '' ? self::paragraph($empty) : '';
        $align = [];
        // Caption as <h6>: mPDF's keep-with-table (use_kwt) only binds headings to the table that follows.
        $html = '<h6 style="text-align:center;font-size:10pt;font-weight:normal;margin:4mm 0 1.6mm 0;">Tabel ' . (++self::$table) . ': ' . self::e($caption) . '</h6>'
            . '<table style="width:100%;border-collapse:collapse;font-size:' . $size . ';margin-bottom:4mm;" autosize="1"><thead><tr>';
        foreach ($headers as $i => $header) {
            [$label, $a] = is_array($header) ? $header : [$header, 'l'];
            $align[$i] = ['l' => 'left', 'c' => 'center', 'r' => 'right'][$a] ?? 'left';
            $html .= '<th style="' . self::TOP . self::MID . 'text-align:' . $align[$i] . ';font-weight:bold;padding:1.4mm 2.2mm;vertical-align:bottom;">' . self::e($label) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        $last = count($rows) - 1;
        foreach (array_values($rows) as $n => $row) {
            $html .= '<tr>';
            foreach (array_values($row) as $i => $cell) {
                $html .= '<td style="' . ($n === $last ? self::BOTTOM : '') . 'text-align:' . ($align[$i] ?? 'left') . ';padding:' . ($n === 0 ? '1.6mm' : '0.9mm') . ' 2.2mm ' . ($n === $last ? '1.6mm' : '0.9mm') . ' 2.2mm;vertical-align:top;">' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    /** Label/value pairs as a two-column-pair tabular framed by top and bottom rules. */
    public static function facts(array $pairs): string
    {
        $cells = [];
        foreach ($pairs as $label => $value) $cells[] = [$label, $value];
        if (count($cells) % 2) $cells[] = ['', ''];
        $rows = array_chunk($cells, 2);
        $last = count($rows) - 1;
        $html = '<table style="width:100%;border-collapse:collapse;font-size:10pt;margin:0 0 5mm 0;">';
        foreach ($rows as $n => $row) {
            $border = ($n === 0 ? self::TOP : '') . ($n === $last ? self::BOTTOM : '');
            $pad = 'padding:' . ($n === 0 ? '1.8mm' : '0.8mm') . ' 2mm ' . ($n === $last ? '1.8mm' : '0.8mm') . ' 2mm;vertical-align:top;';
            $html .= '<tr>';
            foreach ($row as $pair) {
                $html .= '<td style="' . $border . $pad . 'width:18%;font-style:italic;">' . self::e($pair[0]) . '</td>'
                    . '<td style="' . $border . $pad . 'width:32%;">' . $pair[1] . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</table>';
    }

    /** @param array<array{0:string,1:string}> $images [data URI, caption] shown two per row with numbered captions. */
    public static function figures(array $images): string
    {
        if (!$images) return '';
        $html = '<table style="width:100%;border-collapse:collapse;margin:3mm 0 3mm 0;" autosize="1">';
        foreach (array_chunk($images, 2) as $row) {
            $html .= '<tr>';
            foreach ($row as [$src, $caption]) {
                $html .= '<td style="width:50%;text-align:center;vertical-align:top;padding:0 3mm 4mm 3mm;">'
                    . '<img src="' . $src . '" style="width:66mm;">'
                    . '<div style="font-size:9.4pt;margin-top:1.5mm;text-align:center;">Gambar ' . (++self::$figure) . ': ' . self::e($caption) . '</div></td>';
            }
            if (count($row) === 1) $html .= '<td style="width:50%;"></td>';
            $html .= '</tr>';
        }
        return $html . '</table>';
    }

    /** @param array<array{0:string,1:string,2:string}> $columns [caption, title, name] */
    public static function signatures(array $columns, string $placeDate): string
    {
        $html = '<table style="width:100%;border-collapse:collapse;margin-top:10mm;page-break-inside:avoid;font-size:10.5pt;">'
            . '<tr>' . str_repeat('<td></td>', count($columns) - 1) . '<td style="text-align:center;padding-bottom:1mm;">' . $placeDate . '</td></tr><tr>';
        foreach ($columns as $c) $html .= '<td style="width:' . round(100 / count($columns), 2) . '%;text-align:center;vertical-align:top;">' . self::e($c[0]) . '<br>' . ($c[1] !== '' ? self::e($c[1]) : '&nbsp;') . '</td>';
        $html .= '</tr><tr>';
        foreach ($columns as $c) $html .= '<td style="height:20mm;"></td>';
        $html .= '</tr><tr>';
        foreach ($columns as $c) $html .= '<td style="text-align:center;">' . ($c[2] !== '' ? '<span style="text-decoration:underline;">' . self::e($c[2]) . '</span>' : str_repeat('.', 42)) . '</td>';
        return $html . '</tr></table>';
    }

    /** Plain page style: centred page number only. */
    public static function footer(): string
    {
        return '<div style="text-align:center;font-family:' . self::FONT . ';font-size:10pt;">{PAGENO}</div>';
    }

    public static function mpdf(string $tempDir, string $title): \Mpdf\Mpdf
    {
        $config = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $fonts = (new \Mpdf\Config\FontVariables())->getDefaults();
        $pdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $tempDir, 'exposeVersion' => false,
            'fontDir' => array_merge($config['fontDir'], [dirname(__DIR__) . '/assets/fonts/cmu']),
            'fontdata' => $fonts['fontdata'] + [self::FONT => ['R' => 'cmunrm.ttf', 'B' => 'cmunbx.ttf', 'I' => 'cmunti.ttf', 'BI' => 'cmunbi.ttf']],
            'default_font' => self::FONT,
            'margin_left' => 25, 'margin_right' => 25, 'margin_top' => 25, 'margin_bottom' => 25, 'margin_footer' => 12,
            'use_kwt' => true,
        ]);
        $pdf->SetTitle($title);
        $pdf->SetAuthor(PdfLayout::institution()['name']);
        $pdf->SetCreator('Klaras Inven');
        $pdf->SetHTMLFooter(self::footer());
        return $pdf;
    }
}
