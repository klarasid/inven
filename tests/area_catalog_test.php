<?php

declare(strict_types=1);

// Daftar area dan fasilitas: which areas it lists, under which group, and what it prints.
require __DIR__ . '/../src/AreaCatalog.php';

use SLiMS\Plugins\Inventory\AreaCatalog;

function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL ' . $label); echo "ok   $label\n"; }
function rejects(callable $run, string $label, string $message): void
{
    try { $run(); } catch (RuntimeException $error) { check(str_contains($error->getMessage(), $message), $label); return; }
    check(false, $label);
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([
    'CREATE TABLE inventory_locations (id INTEGER PRIMARY KEY, room_name TEXT, area_m2 REAL, slims_location_id TEXT)',
    'CREATE TABLE inventory_room_areas (id INTEGER PRIMARY KEY, location_id INTEGER, type TEXT, name TEXT NOT NULL DEFAULT \'\')',
    'CREATE TABLE inventory_area_photos (id INTEGER PRIMARY KEY, area_id INTEGER, filename TEXT, created_by INTEGER, created_at TEXT)',
    'CREATE TABLE inventory_items (id INTEGER PRIMARY KEY, location_id INTEGER, item_name TEXT, brand_model TEXT NOT NULL DEFAULT \'\', item_code TEXT, quantity_register TEXT NOT NULL DEFAULT \'1\', item_condition TEXT, category TEXT, item_type TEXT NOT NULL DEFAULT \'\')',
    'CREATE TABLE inventory_item_photos (id INTEGER PRIMARY KEY, item_id INTEGER, filename TEXT)',
    "INSERT INTO inventory_locations VALUES (1, 'Ruang Baca', 120.5, 'P01'), (2, 'Lantai Dasar', NULL, 'P01'), (3, 'Halaman Kampus 2', 400, 'P02')",
    "INSERT INTO inventory_room_areas (id, location_id, type, name) VALUES (1, 1, 'baca', ''), (2, 1, 'koleksi', ''), (3, 2, 'toilet', 'Toilet <pria>'), (4, 2, 'musala', ''), (5, 3, 'parkir', ''), (6, 3, 'gazebo', ''), (7, 2, 'tidak_dikenal', '')",
    "INSERT INTO inventory_area_photos (area_id, filename, created_at) VALUES (3, 'toilet-a.jpg', '2026-10-01'), (3, 'toilet-b.jpg', '2026-10-01'), (1, 'baca.jpg', '2026-10-01')",
    "INSERT INTO inventory_items (id, location_id, item_name, item_code, item_condition, category, item_type) VALUES (1, 2, 'Papan petunjuk lantai', 'INV-1', 'B', 'fasilitas_umum', 'Penunjuk arah / papan petunjuk'), (2, 1, 'Meja baca', 'INV-2', 'B', 'perabot', '')",
    "INSERT INTO inventory_item_photos (item_id, filename) VALUES (1, 'papan.jpg')",
] as $sql) {
    $db->exec($sql);
}
$names = static fn (array $catalog): array => array_map(static fn ($group) => [$group['label'] => array_map(static fn ($area) => $area['label'] . ' @ ' . $area['room_name'], $group['areas'])], $catalog['groups']);

$all = AreaCatalog::build($db);
check($names($all) === [
    ['Area layanan dasar' => ['Area koleksi @ Ruang Baca', 'Area baca @ Ruang Baca']],
    ['Area pendukung' => ['Gazebo @ Halaman Kampus 2']],
    ['Fasilitas umum' => ['Toilet @ Lantai Dasar', 'Musala @ Lantai Dasar', 'Area parkir @ Halaman Kampus 2']],
], 'area dikelompokkan seperti di tab Area, menurut jenisnya; jenis yang tidak dikenal dilewati');
check($all['areas'] === 6 && $all['with_photos'] === 2 && $all['photo_count'] === 3 && $all['groups'][2]['areas'][0]['photos'] === ['toilet-a.jpg', 'toilet-b.jpg'], 'tiap area membawa fotonya, dan area berfoto dihitung sekali');
check(array_column($all['groups'][2]['items'], 'item_name') === ['Papan petunjuk lantai'] && $all['groups'][0]['items'] === [], 'barang berkategori fasilitas umum ikut di kelompok fasilitas umum saja');

$public = AreaCatalog::build($db, 'umum', 'P01');
check($names($public) === [['Fasilitas umum' => ['Toilet @ Lantai Dasar', 'Musala @ Lantai Dasar']]] && $public['areas'] === 2, 'daftar dapat dibatasi ke satu kelompok dan satu lokasi perpustakaan');
$summary = AreaCatalog::summary($all);
check($summary['groups'][2] === ['key' => 'umum', 'label' => 'Fasilitas umum', 'areas' => 3, 'with_photos' => 1, 'kinds' => [['label' => 'Toilet', 'count' => 1], ['label' => 'Musala', 'count' => 1], ['label' => 'Area parkir', 'count' => 1]], 'items' => 1],
    'ringkasan menghitung area per jenis, area berfoto, dan barang fasilitas umum');
check(AreaCatalog::build($db, 'pendukung', 'P01')['groups'] === [] , 'kelompok tanpa area tidak dicantumkan');
rejects(static fn () => AreaCatalog::build($db, 'gedung'), 'kelompok lain ditolak', 'Pilih kelompok area');

$picture = imagecreatetruecolor(800, 600);
ob_start(); imagejpeg($picture); $jpeg = (string) ob_get_clean();
$read = ['area' => [], 'item' => []];
$html = AreaCatalog::html($all, static function (string $f) use (&$read, $jpeg): ?string { $read['area'][] = $f; return $f === 'baca.jpg' ? null : $jpeg; }, static function (string $f) use (&$read, $jpeg): ?string { $read['item'][] = $f; return $jpeg; }, ['library_name' => 'Perpustakaan Pusat', 'printed_by' => 'Rina']);
check($read === ['area' => ['baca.jpg', 'toilet-a.jpg', 'toilet-b.jpg'], 'item' => ['papan.jpg']], 'foto area dan foto barang dibaca dari tempatnya masing-masing');
check(str_contains($html, 'Fasilitas umum (3 area)') && str_contains($html, 'Barang fasilitas umum (1 barang)') && str_contains($html, 'Toilet &lt;pria&gt;') && str_contains($html, '120,50 m²'), 'tiap kelompok dicetak dengan jumlahnya, nama area di-escape, dan luas ruangannya tercantum');
check(substr_count($html, 'data:image/jpeg;base64,') === 3 && substr_count($html, 'Belum ada foto') === 5, 'foto dicetak, dan area tanpa foto atau yang fotonya tidak terbaca ditandai');
echo "ok   done\n";
