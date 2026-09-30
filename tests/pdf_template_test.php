<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/PdfTemplate.php';
require_once __DIR__ . '/../src/PdfTemplateModern.php';

use SLiMS\Plugins\Inventory\PdfTemplate;
use SLiMS\Plugins\Inventory\PdfTemplateModern;

$location = [
    'location_code' => 'SMG-01',
    'slims_location_id' => 'SL',
    'slims_location_name' => 'Perpustakaan Utama',
    'room_name' => 'Ruang & Referensi',
    'province' => 'JAWA TENGAH',
    'regency_city' => 'SEMARANG',
    'unit_name' => 'PERPUSTAKAAN',
    'work_unit' => 'POLTEKKES KEMENKES SEMARANG',
    'signature_city' => 'Semarang',
    'knowing_title' => 'Direktur',
    'knowing_name' => 'Nama Direktur',
    'knowing_identity' => 'NIP. 1',
    'manager_title' => 'Pengurus Barang Inventaris',
    'manager_name' => 'Nama Pengurus',
    'manager_identity' => 'NIP. 2',
];
$items = [[
    'item_name' => '<Meja baca>',
    'brand_model' => 'Contoh',
    'serial_number' => 'SN-1',
    'item_size' => '120 cm',
    'material' => 'Kayu',
    'acquisition_year' => 2026,
    'item_code' => 'P01-INV-000001',
    'quantity_register' => '1 / 001',
    'acquisition_price' => 1500000,
    'item_condition' => 'KB',
    'notes' => 'Perlu perawatan',
]];

$html = PdfTemplate::render($location, $items, new DateTimeImmutable('2026-02-12'));
$checks = [
    'kode barang otomatis ditampilkan' => str_contains($html, 'P01-INV-000001'),
    'judul kartu' => str_contains($html, 'KARTU INVENTARIS RUANGAN'),
    'lokasi di-escape' => str_contains($html, 'Ruang &amp; Referensi'),
    'master lokasi SLiMS ditampilkan' => str_contains($html, 'Perpustakaan Utama (SL)'),
    'nama barang di-escape' => str_contains($html, '&lt;Meja baca&gt;'),
    'tanggal Indonesia' => str_contains($html, '12 Februari 2026'),
    'harga Indonesia' => str_contains($html, '1.500.000'),
    'kolom kondisi gabungan' => str_contains($html, 'colspan="3">KEADAAN BARANG'),
    'kondisi KB ditandai' => str_contains($html, '<td class="center condition"></td><td class="center condition">X</td>'),
    'minimal 13 baris' => substr_count($html, '<td class="center">') >= 13,
];

$modern = PdfTemplateModern::render($location, $items, new DateTimeImmutable('2026-02-12'));
$checks += [
    'modern: judul kartu' => str_contains($modern, 'KARTU INVENTARIS RUANGAN'),
    'modern: tanpa kop nama perpustakaan' => !str_contains($modern, 'class="letterhead"'),
    'modern: kolom kondisi gabungan' => str_contains($modern, 'colspan="3">KEADAAN BARANG'),
    'modern: baris jumlah' => str_contains($modern, 'JUMLAH ('),
    'modern: nama barang di-escape' => str_contains($modern, '&lt;Meja baca&gt;'),
];
$failed = false;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
