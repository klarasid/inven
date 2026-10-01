<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

require_once __DIR__ . '/PdfLatex.php';
require_once __DIR__ . '/PdfIso.php';
require_once __DIR__ . '/Letterheads.php';
require_once __DIR__ . '/PdfFonts.php';

/**
 * Report style on an institution's letterhead PDF: the template pages are the page background,
 * content is typeset inside the configured area with the LaTeX or ISO body style, and the style's
 * own title block, running header and footer give way to the letterhead.
 * Same static API as PdfLatex/PdfIso; call configure() with the template before rendering.
 */
final class PdfLetterhead
{
    private static array $template = [];

    public static function configure(array $template): void
    {
        self::$template = $template;
    }

    /** @return class-string<PdfLatex>|class-string<PdfIso> */
    private static function body(): string
    {
        return (self::$template['body'] ?? 'latex') === 'iso' ? PdfIso::class : PdfLatex::class;
    }

    public static function e($value): string { return PdfLayout::e($value); }

    public static function begin(): string
    {
        $first = self::$template['first'];
        // Default margins are the continuation pages (set in mpdf()); the first page overrides top/bottom.
        return self::body()::begin() . PdfFonts::css((string) (self::$template['font'] ?? ''))
            . '<style>@page :first { margin-top:' . $first['top'] . 'mm; margin-bottom:' . $first['bottom'] . 'mm; }</style>';
    }

    /** Plain centred title: the letterhead already identifies the institution. */
    public static function titleBlock(string $title, string $author = '', string $date = '', array $meta = []): string
    {
        $iso = self::body() === PdfIso::class;
        $line = trim(implode(' · ', array_filter([
            ($meta['number'] ?? '') !== '' ? 'Nomor: ' . self::e($meta['number']) : '',
            ($meta['revision'] ?? '') !== '' ? 'Revisi: ' . self::e($meta['revision']) : '',
        ])));
        return '<div style="text-align:center;margin:0 0 6mm 0;">'
            . '<div style="font-size:' . ($iso ? '12pt' : '15pt') . ';font-weight:bold;line-height:1.25;' . ($iso ? 'text-transform:uppercase;' : '') . '">' . self::e($title) . '</div>'
            . ($date !== '' ? '<div style="font-size:10pt;margin-top:2mm;">' . $date . '</div>' : '')
            . ($line !== '' ? '<div style="font-size:9pt;margin-top:1mm;">' . $line . '</div>' : '')
            . '</div>';
    }

    public static function abstract(string $html, string $heading = 'Ringkasan'): string { return self::body()::abstract($html, $heading); }
    public static function section(string $title): string { return self::body()::section($title); }
    public static function subsection(string $title): string { return self::body()::subsection($title); }
    public static function paragraph(string $html): string { return self::body()::paragraph($html); }
    public static function table(string $caption, array $headers, array $rows, string $empty = ''): string { return self::body()::table(...func_get_args()); }
    public static function facts(array $pairs): string { return self::body()::facts($pairs); }
    public static function figures(array $images): string { return self::body()::figures($images); }
    public static function signatures(array $columns, string $placeDate): string { return self::body()::signatures($columns, $placeDate); }

    /** The letterhead carries its own footer. */
    public static function footer(): string { return ''; }

    public static function mpdf(string $tempDir, string $title): \Mpdf\Mpdf
    {
        $t = self::$template;
        if (!$t) throw new \RuntimeException('Template kop belum dipilih.');
        [$width, $height] = $t['sizes'][0];
        // The LaTeX body needs its bundled CMU fonts; start from its configuration and swap page geometry.
        $config = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $fonts = (new \Mpdf\Config\FontVariables())->getDefaults();
        $pdf = new \Mpdf\Mpdf([
            // An explicit [width, height] already fixes orientation; adding 'L' would rotate it again.
            'mode' => 'utf-8', 'format' => [$width, $height],
            'tempDir' => $tempDir, 'exposeVersion' => false,
            'fontDir' => array_merge($config['fontDir'], [dirname(__DIR__) . '/assets/fonts/cmu']),
            'fontdata' => $fonts['fontdata'] + ['cmuserif' => ['R' => 'cmunrm.ttf', 'B' => 'cmunbx.ttf', 'I' => 'cmunti.ttf', 'BI' => 'cmunbi.ttf']],
            'default_font' => ($t['font'] ?? '') !== '' ? $t['font'] : ($t['body'] === 'iso' ? 'freesans' : 'cmuserif'),
            'margin_left' => $t['first']['left'], 'margin_right' => $t['first']['right'],
            'margin_top' => $t['next']['top'], 'margin_bottom' => $t['next']['bottom'],
            'margin_header' => 0, 'margin_footer' => 0,
            'use_kwt' => true,
        ]);
        // Without "continue", pages beyond the template file's pages are left as plain paper.
        $pdf->SetDocTemplate(Letterheads::printFile($t, $tempDir), empty($t['first_only']));
        $pdf->SetTitle($title);
        $pdf->SetAuthor(PdfLayout::institution()['name']);
        $pdf->SetCreator('Klaras Inven');
        return $pdf;
    }
}
