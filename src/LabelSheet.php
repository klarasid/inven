<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

require_once __DIR__ . '/PdfLayout.php';

/**
 * Item labels with a QR code that opens the item in the inventory app. Labels are placed
 * absolutely in millimetres so they line up with standard label sheets; the thermal preset
 * prints one label per page.
 */
final class LabelSheet
{
    /**
     * Sheet geometry in mm: page size, label size, first-label offset and pitch between labels.
     * A4 presets follow Avery L7159 (3×8) and L7163 (2×7), sold locally under several brands.
     */
    public const PRESETS = [
        'a4-3x8' => ['label' => 'A4 · 3×8 label (64×34 mm)', 'page' => [210, 297], 'size' => [63.5, 33.9], 'origin' => [7.2, 12.9], 'pitch' => [66.0, 33.9], 'grid' => [3, 8], 'font' => [5.6, 9.2, 11, 6.4], 'chars' => [40, 44, 56]],
        'a4-2x7' => ['label' => 'A4 · 2×7 label (99×38 mm)', 'page' => [210, 297], 'size' => [99.1, 38.1], 'origin' => [4.7, 15.1], 'pitch' => [101.6, 38.1], 'grid' => [2, 7], 'font' => [7, 12, 14.5, 8], 'chars' => [58, 60, 80]],
        'thermal-50x30' => ['label' => 'Stiker tunggal 50×30 mm (printer label)', 'page' => [50, 30], 'size' => [50, 30], 'origin' => [0, 0], 'pitch' => [50, 30], 'grid' => [1, 1], 'font' => [5, 8.4, 10, 5.8], 'chars' => [36, 40, 50]],
    ];

    private static function e($value): string
    {
        return PdfLayout::e($value);
    }

    /**
     * @param array<array{item:array,room:string,library:string,url:string}> $labels
     * @param int $start 1-based position of the first free label on the first sheet
     */
    public static function render(array $labels, string $preset, int $start = 1, ?array $institution = null): string
    {
        $p = self::PRESETS[$preset] ?? self::PRESETS['a4-3x8'];
        [$cols, $rows] = $p['grid'];
        $perPage = $cols * $rows;
        $start = max(1, min($perPage, $start));
        [$w, $h] = $p['size'];
        [$fInst, $fName, $fCode, $fRoom] = $p['font'];
        [$cInst, $cName, $cRoom] = $p['chars'];
        // Square QR capped at about a third of the label width so the text keeps room to breathe.
        $pad = $h < 34 ? 1.8 : 2.4;
        $qr = round(min($h - 2 * $pad, $w * 0.36), 1);
        $name = PdfLayout::institution($institution)['name'];

        $html = '<style>body{font-family:dejavusanscondensed;color:#000;}</style>';
        $slot = $start - 1;
        foreach ($labels as $n => $label) {
            if ($slot > 0 && $slot % $perPage === 0) $html .= '<pagebreak />';
            $position = $slot % $perPage;
            $x = $p['origin'][0] + ($position % $cols) * $p['pitch'][0];
            $y = $p['origin'][1] + intdiv($position, $cols) * $p['pitch'][1];
            $item = $label['item'];
            $code = trim((string) ($item['item_code'] ?? ''));
            $html .= '<div style="position:absolute;left:' . $x . 'mm;top:' . $y . 'mm;width:' . $w . 'mm;height:' . $h . 'mm;overflow:hidden;">'
                . '<table style="width:' . $w . 'mm;height:' . $h . 'mm;border-collapse:collapse;"><tr>'
                . '<td style="width:' . ($qr + $pad) . 'mm;padding:' . $pad . 'mm 0 ' . $pad . 'mm ' . $pad . 'mm;vertical-align:middle;">'
                . '<barcode code="' . self::e($label['url']) . '" type="QR" error="L" disableborder="1" size="' . round($qr / 25, 3) . '" />'
                . '</td><td style="padding:' . $pad . 'mm ' . $pad . 'mm ' . $pad . 'mm 1.8mm;vertical-align:middle;">'
                . '<div style="font-size:' . $fInst . 'pt;color:#444;text-transform:uppercase;line-height:1.15;">' . self::e(self::fit($name, $cInst)) . '</div>'
                . '<div style="font-size:' . $fName . 'pt;font-weight:bold;line-height:1.12;margin-top:0.8mm;">' . self::e(self::fit((string) $item['item_name'], $cName)) . '</div>'
                . '<div style="font-family:dejavusansmono;font-size:' . $fCode . 'pt;font-weight:bold;margin-top:0.8mm;">' . self::e($code !== '' ? $code : 'ID ' . (int) $item['id']) . '</div>'
                . '<div style="font-size:' . $fRoom . 'pt;color:#333;line-height:1.15;margin-top:0.8mm;">' . self::e(self::fit($label['room'] . ($label['library'] !== '' ? ' · ' . $label['library'] : ''), $cRoom)) . '</div>'
                . '</td></tr></table></div>';
            $slot++;
        }
        return $html;
    }

    /** Shortens text that would overflow a fixed-size label. */
    private static function fit(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }

    public static function mpdf(string $tempDir, string $preset, string $title): \Mpdf\Mpdf
    {
        $p = self::PRESETS[$preset] ?? self::PRESETS['a4-3x8'];
        $pdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => $p['page'], 'tempDir' => $tempDir, 'exposeVersion' => false,
            'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0, 'margin_header' => 0, 'margin_footer' => 0,
            'default_font' => 'dejavusanscondensed',
        ]);
        $pdf->SetTitle($title);
        $pdf->SetCreator('Inventaris Barang Perpustakaan');
        return $pdf;
    }
}
