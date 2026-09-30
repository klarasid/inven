<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

/**
 * Fonts offered for report content: only families mPDF can typeset with regular, bold, italic and
 * bold-italic faces, so emphasis never falls back to a different font. Each has a preview image
 * (assets/fonts/preview/<key>.png, made by tools/font-previews.php) showing its name in that font.
 */
final class PdfFonts
{
    /** key (mPDF family) => [display name, group, hint, regular TTF path relative to the plugin] */
    public const FONTS = [
        'cmuserif' => ['Computer Modern', 'Serif', 'Huruf khas LaTeX', 'assets/fonts/cmu/cmunrm.ttf'],
        'freeserif' => ['FreeSerif', 'Serif', 'Mirip Times New Roman', 'vendor/mpdf/mpdf/ttfonts/FreeSerif.ttf'],
        'dejavuserif' => ['DejaVu Serif', 'Serif', '', 'vendor/mpdf/mpdf/ttfonts/DejaVuSerif.ttf'],
        'dejavuserifcondensed' => ['DejaVu Serif Condensed', 'Serif', 'Lebih rapat', 'vendor/mpdf/mpdf/ttfonts/DejaVuSerifCondensed.ttf'],
        'freesans' => ['FreeSans', 'Sans-serif', 'Mirip Arial / Helvetica', 'vendor/mpdf/mpdf/ttfonts/FreeSans.ttf'],
        'dejavusans' => ['DejaVu Sans', 'Sans-serif', 'Mirip Verdana', 'vendor/mpdf/mpdf/ttfonts/DejaVuSans.ttf'],
        'dejavusanscondensed' => ['DejaVu Sans Condensed', 'Sans-serif', 'Lebih rapat', 'vendor/mpdf/mpdf/ttfonts/DejaVuSansCondensed.ttf'],
        'freemono' => ['FreeMono', 'Monospace', 'Mirip Courier New', 'vendor/mpdf/mpdf/ttfonts/FreeMono.ttf'],
        'dejavusansmono' => ['DejaVu Sans Mono', 'Monospace', '', 'vendor/mpdf/mpdf/ttfonts/DejaVuSansMono.ttf'],
    ];

    public static function valid(string $key): bool
    {
        return $key === '' || isset(self::FONTS[$key]);
    }

    /** Font list for the settings UI, with the preview image path relative to assets/. */
    public static function catalog(): array
    {
        $list = [];
        foreach (self::FONTS as $key => [$name, $group, $hint]) $list[] = ['value' => $key, 'name' => $name, 'group' => $group, 'hint' => $hint, 'preview' => 'fonts/preview/' . $key . '.png'];
        return $list;
    }

    /** CSS that makes every content element use the family (inline font-family in body styles is rare). */
    public static function css(string $key): string
    {
        if (!isset(self::FONTS[$key])) return '';
        return '<style>body,p,div,span,td,th,li,h1,h2,h3,h4,h5,h6{font-family:' . $key . ';}</style>';
    }
}
