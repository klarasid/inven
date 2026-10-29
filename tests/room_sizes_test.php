<?php

declare(strict_types=1);

// Daftar area dan luas ruangan: each room with its floor area and its areas, by library location,
// with the totals; the areas outside any room; and what it prints. Runs on SQLite.
require __DIR__ . '/../src/RoomSizes.php';

use SLiMS\Plugins\Inventory\RoomSizes;

function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL ' . $label); echo "ok   $label\n"; }

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([
    'CREATE TABLE mst_location (location_id TEXT PRIMARY KEY, location_name TEXT)',
    'CREATE TABLE inventory_locations (id INTEGER PRIMARY KEY, room_name TEXT, location_code TEXT, area_m2 REAL, slims_location_id TEXT)',
    'CREATE TABLE inventory_room_areas (id INTEGER PRIMARY KEY, location_id INTEGER, slims_location_id TEXT, type TEXT, name TEXT NOT NULL DEFAULT \'\')',
    "INSERT INTO mst_location VALUES ('P01', 'Perpustakaan Pusat'), ('P02', 'Kampus Blora')",
    "INSERT INTO inventory_locations VALUES (1, 'Ruang Baca', '01-RUANG-001', 120.5, 'P01'), (2, 'Gudang <B>', '', NULL, 'P01'), (3, 'Ruang Sirkulasi', '02-RUANG-001', 40, 'P02'), (4, 'Ruang Lama', '', 15, NULL)",
    "INSERT INTO inventory_room_areas (location_id, slims_location_id, type, name) VALUES (1, NULL, 'koleksi', ''), (1, NULL, 'baca', 'Lantai 1'), (3, NULL, 'layanan', ''), (3, NULL, 'tidak_dikenal', ''),
        (NULL, 'P01', 'parkir', 'Parkir motor'), (NULL, 'P02', 'kantin', '')",
] as $sql) $db->exec($sql);

$list = RoomSizes::build($db);
check(array_column($list['locations'], 'name') === ['Kampus Blora', 'Perpustakaan Pusat', 'Lokasi belum ditentukan'], 'ruangan dikelompokkan per lokasi perpustakaan, yang tanpa lokasi terakhir');
check($list['rooms'] === 4 && $list['areas'] === 3 && $list['outside'] === 2 && $list['unsized'] === 1 && abs($list['total_m2'] - 175.5) < 0.001, 'jumlah ruangan, area, area luar ruangan, ruangan tanpa luas, dan luas seluruhnya');
$pusat = $list['locations'][1];
check(abs($pusat['total_m2'] - 120.5) < 0.001 && $pusat['unsized'] === 1 && array_column($pusat['rooms'], 'name') === ['Gudang <B>', 'Ruang Baca'], 'tiap lokasi menjumlah luas ruangannya sendiri');
check(array_column($pusat['rooms'][1]['areas'], 'label') === ['Area koleksi', 'Area baca'] && $pusat['rooms'][1]['areas'][1]['name'] === 'Lantai 1', 'area ruangan berurutan seperti di tab Area, dengan namanya');
check(array_column($list['locations'][0]['rooms'][0]['areas'], 'type') === ['layanan'], 'jenis area yang tidak dikenal dilewati');
check($pusat['outside'] === [['type' => 'parkir', 'label' => 'Area parkir', 'name' => 'Parkir motor']], 'area luar ruangan masuk ke lokasinya sendiri');

$one = RoomSizes::build($db, 'P02');
check(array_column($one['locations'], 'name') === ['Kampus Blora'] && $one['rooms'] === 1 && $one['outside'] === 1, 'daftar dapat dibatasi ke satu lokasi perpustakaan');

$html = RoomSizes::html($list, ['library_name' => 'Perpustakaan Contoh', 'printed_by' => 'Rina']);
check(str_contains($html, 'Daftar Area dan Luas Ruangan') && str_contains($html, '120,50 m²') && str_contains($html, '175,50 m²'), 'luas ruangan dan jumlahnya tercetak');
check(str_contains($html, 'Gudang &lt;B&gt;') && str_contains($html, '1 ruangan belum diisi luasnya'), 'nama ruangan di-escape, dan ruangan tanpa luas disebutkan');
check(str_contains($html, 'Rekap per lokasi') && str_contains($html, 'Area di luar ruangan (1 area)') && !str_contains($html, '<img'), 'rekap per lokasi dan area luar ruangan tercetak, tanpa foto');
check(!str_contains(RoomSizes::html($one), 'Rekap per lokasi'), 'satu lokasi tidak perlu rekap per lokasi');

$db->exec('DELETE FROM inventory_locations');
$db->exec('DELETE FROM inventory_room_areas');
check(str_contains(RoomSizes::html(RoomSizes::build($db)), 'Belum ada ruangan yang dicatat.'), 'tanpa ruangan, dokumen mengatakannya');
echo "ok   done\n";
