<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

require_once __DIR__ . '/RoomSpecimens.php';

final class PdfTemplate
{
    private const MONTHS = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    private static function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

    /**
     * @param array<string,string> $specimens  signature specimens as data URIs by signer (RoomSpecimens::dataUris), printed in the signature space
     */
    public static function render(array $location, array $items, ?\DateTimeInterface $printedAt = null, array $specimens = []): string
    {
        $printedAt = $printedAt ?? new \DateTimeImmutable('now');
        $month = self::MONTHS[(int) $printedAt->format('n')];
        $printDate = self::e($printedAt->format('j') . ' ' . $month . ' ' . $printedAt->format('Y'));
        $city = self::value($location, 'signature_city');

        $rows = '';
        $minimumRows = 13;
        $rowCount = max($minimumRows, count($items));
        for ($index = 0; $index < $rowCount; $index++) {
            $item = $items[$index] ?? [];
            $condition = (string) ($item['item_condition'] ?? '');
            $rows .= '<tr>'
                . '<td class="center">' . ($index + 1) . '</td>'
                . '<td>' . self::value($item, 'item_name') . '</td>'
                . '<td>' . self::value($item, 'brand_model') . '</td>'
                . '<td>' . self::value($item, 'serial_number') . '</td>'
                . '<td>' . self::value($item, 'item_size') . '</td>'
                . '<td>' . self::value($item, 'material') . '</td>'
                . '<td class="center">' . self::value($item, 'acquisition_year') . '</td>'
                . '<td>' . self::value($item, 'item_code') . '</td>'
                . '<td class="center">' . self::value($item, 'quantity_register') . '</td>'
                . '<td class="number">' . self::price($item['acquisition_price'] ?? 0) . '</td>'
                . '<td class="center condition">' . ($condition === 'B' ? 'X' : '') . '</td>'
                . '<td class="center condition">' . ($condition === 'KB' ? 'X' : '') . '</td>'
                . '<td class="center condition">' . ($condition === 'RB' ? 'X' : '') . '</td>'
                . '<td>' . self::value($item, 'notes') . '</td>'
                . '</tr>';
        }

        return '<!doctype html><html lang="id"><head><meta charset="utf-8"><style>'
            . '@page{margin:4mm 10mm 7mm 10mm;} body{font-family:"Times New Roman",serif;font-size:8pt;color:#000;}'
            . 'h1{text-align:center;font-size:17pt;margin:0 0 7mm;font-weight:bold;}'
            . '.identity{width:100%;border-collapse:collapse;margin:0 0 4mm;table-layout:fixed;font-size:9pt;}'
            . '.identity td{border:0;padding:0.5mm 1mm;vertical-align:top;}.identity .label{width:18%;}.identity .colon{width:2%;text-align:center;}.identity .value{width:30%;font-weight:bold;}'
            . '.inventory{width:100%;border-collapse:collapse;table-layout:fixed;page-break-inside:auto;}'
            . '.inventory thead{display:table-header-group;}.inventory tr{page-break-inside:avoid;}'
            . '.inventory th,.inventory td{border:0.25mm solid #000;padding:0.7mm 0.6mm;vertical-align:middle;overflow-wrap:anywhere;line-height:1.08;}'
            . '.inventory th{text-align:center;font-weight:bold;font-size:7.2pt;height:10mm;}.inventory tbody td{height:5.2mm;font-size:7.2pt;}'
            . '.center{text-align:center;}.number{text-align:right;}.condition{font-weight:bold;font-size:8pt;}'
            . '.sign-date{text-align:center;margin:8mm 0 1.5mm 72%;font-size:9pt;}'
            . '.signatures{width:100%;border-collapse:collapse;table-layout:fixed;font-size:9pt;}.signatures td{width:50%;border:0;text-align:center;vertical-align:top;padding:0 7mm;}'
            . '.signature-space{height:14mm;}.signature-name{font-weight:bold;text-decoration:underline;}.identity-number{font-size:8pt;}'
            . '</style></head><body>'
            . '<h1>KARTU INVENTARIS RUANGAN</h1>'
            . '<table class="identity"><tr><td class="label">PROVINSI</td><td class="colon">:</td><td class="value">' . self::value($location, 'province') . '</td>'
            . '<td class="label">NO KODE LOKASI</td><td class="colon">:</td><td class="value">' . self::value($location, 'location_code') . '</td></tr>'
            . '<tr><td class="label">KABUPATEN/KOTA</td><td class="colon">:</td><td class="value">' . self::value($location, 'regency_city') . '</td>'
            . '<td class="label">RUANGAN</td><td class="colon">:</td><td class="value">' . self::value($location, 'room_name') . '</td></tr>'
            . '<tr><td class="label">UNIT</td><td class="colon">:</td><td class="value">' . self::value($location, 'unit_name') . '</td>'
            . '<td class="label">LOKASI</td><td class="colon">:</td><td class="value">' . self::masterLocation($location) . '</td></tr>'
            . '<tr><td class="label">SATUAN KERJA</td><td class="colon">:</td><td class="value" colspan="4">' . self::value($location, 'work_unit') . '</td></tr></table>'
            . '<table class="inventory"><thead><tr>'
            . '<th width="3.2%" rowspan="2">NO</th><th width="15.2%" rowspan="2">JENIS BARANG/<br>NAMA BARANG</th><th width="6.5%" rowspan="2">MERK/<br>MODEL</th><th width="5.8%" rowspan="2">NO. SERI<br>PABRIK</th><th width="7.4%" rowspan="2">UKURAN</th><th width="7.1%" rowspan="2">BAHAN</th>'
            . '<th width="8.1%" rowspan="2">TAHUN PEMBUATAN/<br>PEMBELIAN</th><th width="6.5%" rowspan="2">NO. KODE<br>BARANG</th><th width="6.5%" rowspan="2">JUMLAH BARANG/<br>REGISTER</th><th width="8.1%" rowspan="2">HARGA BELI/<br>PEROLEHAN</th>'
            . '<th width="16.4%" colspan="3">KEADAAN BARANG *)</th><th width="9.3%" rowspan="2">KETERANGAN</th></tr><tr><th width="4.2%">BAIK<br>(B)</th><th width="6.4%">KURANG BAIK<br>(KB)</th><th width="5.8%">RUSAK BERAT<br>(RB)</th></tr></thead><tbody>'
            . $rows . '</tbody></table>'
            . '<div class="sign-date">' . $city . ', ' . $printDate . '</div>'
            . '<table class="signatures"><tr><td>MENGETAHUI:<br>' . self::value($location, 'knowing_title') . '</td><td>' . self::value($location, 'manager_title') . '</td></tr>'
            . '<tr><td class="signature-space">' . RoomSpecimens::image($specimens, 'knowing', '14mm') . '</td><td class="signature-space">' . RoomSpecimens::image($specimens, 'manager', '14mm') . '</td></tr>'
            . '<tr><td><span class="signature-name">' . self::value($location, 'knowing_name') . '</span><br><span class="identity-number">' . self::value($location, 'knowing_identity') . '</span></td>'
            . '<td><span class="signature-name">' . self::value($location, 'manager_name') . '</span><br><span class="identity-number">' . self::value($location, 'manager_identity') . '</span></td></tr></table>'
            . '</body></html>';
    }
}
