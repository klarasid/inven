<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

require_once __DIR__ . '/PdfLayout.php';

/**
 * Kartu Inventaris Ruangan (KIR), modern layout. Columns follow the standard government KIR form;
 * typography and totals follow the shared PdfLayout style. The classic layout stays in PdfTemplate.
 */
final class PdfTemplateModern
{
    private static function e($value): string
    {
        return PdfLayout::e($value);
    }

    private static function value(array $data, string $key): string
    {
        $value = trim((string) ($data[$key] ?? ''));
        return $value === '' ? '&nbsp;' : self::e($value);
    }

    private static function price($value): string
    {
        $number = (float) ($value ?? 0);
        return $number > 0 ? self::e(number_format($number, 0, ',', '.')) : '';
    }

    private static function masterLocation(array $location): string
    {
        $id = trim((string) ($location['slims_location_id'] ?? ''));
        $name = trim((string) ($location['slims_location_name'] ?? ''));
        if ($name === '' && $id === '') {
            return '&nbsp;';
        }

        return self::e($name === '' ? $id : $name . ($id === '' ? '' : ' (' . $id . ')'));
    }

    public static function footer(array $location): string
    {
        return '<table width="100%" style="border-top:0.2mm solid #cbd5e1;font-family:dejavusans;font-size:6.8pt;color:#6b7280;"><tr>'
            . '<td style="padding-top:1.5mm;">Kartu Inventaris Ruangan · ' . self::e(trim((string) ($location['room_name'] ?? ''))) . '</td>'
            . '<td style="padding-top:1.5mm;text-align:right;">Halaman {PAGENO} dari {nbpg}</td></tr></table>';
    }

    public static function render(array $location, array $items, ?\DateTimeInterface $printedAt = null): string
    {
        $printedAt = $printedAt ?? new \DateTimeImmutable('now');
        $printDate = self::e(PdfLayout::date($printedAt));
        $city = self::value($location, 'signature_city');

        $rows = '';
        $total = 0.0;
        $conditionCount = ['B' => 0, 'KB' => 0, 'RB' => 0];
        $rowCount = max(13, count($items));
        for ($index = 0; $index < $rowCount; $index++) {
            $item = $items[$index] ?? [];
            $condition = (string) ($item['item_condition'] ?? '');
            if (isset($conditionCount[$condition])) $conditionCount[$condition]++;
            $total += (float) ($item['acquisition_price'] ?? 0);
            $rows .= '<tr' . ($index % 2 ? ' class="alt"' : '') . '>'
                . '<td class="center">' . ($index + 1) . '</td>'
                . '<td class="name">' . self::value($item, 'item_name') . '</td>'
                . '<td>' . self::value($item, 'brand_model') . '</td>'
                . '<td>' . self::value($item, 'serial_number') . '</td>'
                . '<td>' . self::value($item, 'item_size') . '</td>'
                . '<td>' . self::value($item, 'material') . '</td>'
                . '<td class="center">' . self::value($item, 'acquisition_year') . '</td>'
                . '<td class="code">' . self::value($item, 'item_code') . '</td>'
                . '<td class="center">' . self::value($item, 'quantity_register') . '</td>'
                . '<td class="number">' . self::price($item['acquisition_price'] ?? 0) . '</td>'
                . '<td class="center condition">' . ($condition === 'B' ? 'X' : '') . '</td>'
                . '<td class="center condition">' . ($condition === 'KB' ? 'X' : '') . '</td>'
                . '<td class="center condition">' . ($condition === 'RB' ? 'X' : '') . '</td>'
                . '<td>' . self::value($item, 'notes') . '</td>'
                . '</tr>';
        }
        $filled = count($items);

        $css = PdfLayout::css(
            'body{font-family:dejavusanscondensed;font-size:8pt;}'
            . 'h1{text-align:center;font-size:14pt;letter-spacing:1.2pt;margin:0;}'
            . '.kir-sub{text-align:center;font-size:8.4pt;color:#4b5563;margin:0.8mm 0 4mm 0;}'
            . '.identity{width:100%;border-collapse:collapse;margin:0 0 3.5mm;border:0.25mm solid #9ca3af;}'
            . '.identity td{padding:1.1mm 2mm;vertical-align:top;font-size:8.4pt;}'
            . '.identity .label{width:14%;color:#4b5563;}.identity .colon{width:1.5%;text-align:center;color:#4b5563;}.identity .value{width:34.5%;font-weight:bold;color:#111827;}'
            . '.inventory{width:100%;border-collapse:collapse;page-break-inside:auto;}'
            . '.inventory thead{display:table-header-group;}.inventory tr{page-break-inside:avoid;}'
            . '.inventory th,.inventory td{border:0.2mm solid #4b5563;padding:0.9mm 0.9mm;vertical-align:middle;line-height:1.12;}'
            . '.inventory th{text-align:center;font-weight:bold;font-size:6.9pt;background-color:#e5eaf0;color:#111827;}'
            . '.inventory th.idx{background-color:#f3f5f8;font-size:6.2pt;color:#4b5563;font-weight:normal;}'
            . '.inventory tbody td{height:5.4mm;font-size:7.3pt;}.inventory tr.alt td{background-color:#f8fafc;}'
            . '.inventory td.name{font-weight:bold;}.inventory td.code{font-size:6.9pt;}'
            . '.inventory tfoot td{background-color:#e5eaf0;font-weight:bold;font-size:7.4pt;}'
            . '.number{text-align:right;}.condition{font-weight:bold;font-size:8.4pt;}'
            . '.legend{font-size:7pt;color:#4b5563;margin-top:1.5mm;}'
            . '.sign td{font-size:8.6pt;}'
        );

        $identity = '<table class="identity">'
            . '<tr><td class="label">Provinsi</td><td class="colon">:</td><td class="value">' . self::value($location, 'province') . '</td>'
            . '<td class="label">No. kode lokasi</td><td class="colon">:</td><td class="value">' . self::value($location, 'location_code') . '</td></tr>'
            . '<tr><td class="label">Kabupaten/kota</td><td class="colon">:</td><td class="value">' . self::value($location, 'regency_city') . '</td>'
            . '<td class="label">Ruangan</td><td class="colon">:</td><td class="value">' . self::value($location, 'room_name') . '</td></tr>'
            . '<tr><td class="label">Unit</td><td class="colon">:</td><td class="value">' . self::value($location, 'unit_name') . '</td>'
            . '<td class="label">Lokasi</td><td class="colon">:</td><td class="value">' . self::masterLocation($location) . '</td></tr>'
            . '<tr><td class="label">Satuan kerja</td><td class="colon">:</td><td class="value" colspan="4">' . self::value($location, 'work_unit') . '</td></tr></table>';

        $head = '<thead><tr>'
            . '<th width="3.2%" rowspan="2">NO</th><th width="15.2%" rowspan="2">JENIS BARANG/<br>NAMA BARANG</th><th width="6.5%" rowspan="2">MERK/<br>MODEL</th><th width="5.8%" rowspan="2">NO. SERI<br>PABRIK</th><th width="7.4%" rowspan="2">UKURAN</th><th width="7.1%" rowspan="2">BAHAN</th>'
            . '<th width="8.1%" rowspan="2">TAHUN PEMBUATAN/<br>PEMBELIAN</th><th width="6.5%" rowspan="2">NO. KODE<br>BARANG</th><th width="6.5%" rowspan="2">JUMLAH BARANG/<br>REGISTER</th><th width="8.1%" rowspan="2">HARGA BELI/<br>PEROLEHAN (Rp)</th>'
            . '<th width="16.4%" colspan="3">KEADAAN BARANG *)</th><th width="9.3%" rowspan="2">KETERANGAN</th></tr>'
            . '<tr><th width="4.2%">BAIK<br>(B)</th><th width="6.4%">KURANG BAIK<br>(KB)</th><th width="5.8%">RUSAK BERAT<br>(RB)</th></tr>'
            . '<tr>' . implode('', array_map(fn($n) => '<th class="idx">' . $n . '</th>', range(1, 14))) . '</tr></thead>';

        $foot = '<tfoot><tr><td colspan="9" class="number">JUMLAH (' . $filled . ' barang)</td>'
            . '<td class="number">' . ($total > 0 ? self::e(number_format($total, 0, ',', '.')) : '') . '</td>'
            . '<td class="center">' . $conditionCount['B'] . '</td><td class="center">' . $conditionCount['KB'] . '</td><td class="center">' . $conditionCount['RB'] . '</td><td></td></tr></tfoot>';

        $name = 'font-weight:bold;text-decoration:underline;';
        $signatures = '<table class="sign" style="margin-top:5mm;page-break-inside:avoid">'
            . '<tr><td></td><td style="padding-bottom:1mm">' . $city . ', ' . $printDate . '</td></tr>'
            . '<tr><td>Mengetahui,<br>' . self::value($location, 'knowing_title') . '</td><td><br>' . self::value($location, 'manager_title') . '</td></tr>'
            . '<tr><td style="height:18mm"></td><td style="height:18mm"></td></tr>'
            . '<tr><td><span style="' . $name . '">' . self::value($location, 'knowing_name') . '</span><br><span style="font-size:7.6pt">' . self::value($location, 'knowing_identity') . '</span></td>'
            . '<td><span style="' . $name . '">' . self::value($location, 'manager_name') . '</span><br><span style="font-size:7.6pt">' . self::value($location, 'manager_identity') . '</span></td></tr></table>';

        return '<!doctype html><html lang="id"><head><meta charset="utf-8">' . $css . '</head><body>'
            . '<h1>KARTU INVENTARIS RUANGAN</h1>'
            . '<p class="kir-sub">' . self::value($location, 'room_name') . ' · Keadaan per ' . $printDate . '</p>'
            . $identity
            . '<table class="inventory">' . $head . '<tbody>' . $rows . '</tbody>' . $foot . '</table>'
            . '<p class="legend">*) Beri tanda X pada kolom keadaan barang: B = Baik, KB = Kurang Baik, RB = Rusak Berat.</p>'
            . $signatures
            . '</body></html>';
    }
}
