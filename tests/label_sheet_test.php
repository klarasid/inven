<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/LabelSheet.php';

use SLiMS\Plugins\Inventory\LabelSheet;

$institution = ['name' => 'Perpustakaan Uji', 'subname' => '', 'logo' => null];
$label = fn(int $id, string $name, string $code = '') => [
    'item' => ['id' => $id, 'item_name' => $name, 'item_code' => $code],
    'room' => 'Ruang & Baca', 'library' => 'Kampus 1', 'url' => 'https://example.test/admin/plugin_container.php?mod=stock_take&id=x&qr=' . $id,
];
$labels = [];
for ($i = 1; $i <= 30; $i++) $labels[] = $label($i, 'Barang ' . $i, 'INV-' . $i);

$sheet = LabelSheet::render($labels, 'a4-3x8', 4, $institution);
$positions = [];
preg_match_all('/left:([\d.]+)mm;top:([\d.]+)mm/', $sheet, $positions, PREG_SET_ORDER);
$long = LabelSheet::render([$label(99, str_repeat('Rak buku besi sangat panjang ', 5), '')], 'thermal-50x30', 1, $institution);

$checks = [
    'satu QR per label' => substr_count($sheet, 'type="QR"') === 30,
    'QR berisi tautan pendek barang' => str_contains($sheet, 'qr=17"'),
    'mulai dari label ke-4 (baris kedua, kolom pertama)' => $positions[0][1] === '7.2' && $positions[0][2] === '46.8',
    'label ke-22 pindah ke lembar kedua' => substr_count($sheet, '<pagebreak />') === 1,
    'label pertama lembar kedua di pojok kiri atas' => (function () use ($sheet) {
        $after = substr($sheet, strpos($sheet, '<pagebreak />'));
        return (bool) preg_match('/^<pagebreak \/><div style="position:absolute;left:7\.2mm;top:12\.9mm;/', $after);
    })(),
    'teks di-escape' => str_contains($sheet, 'Ruang &amp; Baca'),
    'kode barang tercetak' => str_contains($sheet, 'INV-30'),
    'tanpa kode memakai ID' => str_contains($long, 'ID 99'),
    'nama panjang dipotong' => str_contains($long, '…'),
    'posisi awal di luar batas dibatasi' => str_contains(LabelSheet::render([$label(1, 'A')], 'a4-2x7', 99, $institution), 'left:106.3mm;top:243.7mm'),
    'preset tidak dikenal memakai A4 3×8' => str_contains(LabelSheet::render([$label(1, 'A')], 'x', 1, $institution), 'left:7.2mm;top:12.9mm'),
];

$failed = false;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'ok   ' : 'FAIL ') . $name . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
