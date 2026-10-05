<?php

declare(strict_types=1);

// Daftar inventaris berfoto: which items it lists, how it groups them, and what it prints.
require __DIR__ . '/../src/InventoryCatalog.php';

use SLiMS\Plugins\Inventory\InventoryCatalog;

function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL ' . $label); echo "ok   $label\n"; }
function rejects(callable $run, string $label, string $message): void
{
    try { $run(); } catch (RuntimeException $error) { check(str_contains($error->getMessage(), $message), $label); return; }
    check(false, $label);
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([
    'CREATE TABLE inventory_locations (id INTEGER PRIMARY KEY, room_name TEXT, slims_location_id TEXT)',
    'CREATE TABLE inventory_room_areas (id INTEGER PRIMARY KEY, location_id INTEGER, type TEXT, name TEXT NOT NULL DEFAULT \'\')',
    'CREATE TABLE inventory_items (id INTEGER PRIMARY KEY, location_id INTEGER, item_name TEXT, brand_model TEXT NOT NULL DEFAULT \'\', item_code TEXT, quantity_register TEXT NOT NULL DEFAULT \'1\', item_condition TEXT, category TEXT, item_type TEXT NOT NULL DEFAULT \'\')',
    'CREATE TABLE inventory_item_photos (id INTEGER PRIMARY KEY, item_id INTEGER, filename TEXT)',
    "INSERT INTO inventory_locations VALUES (1, 'Ruang Baca', 'P01'), (2, 'Halaman', 'P01'), (3, 'Gudang Kampus 2', 'P02')",
    // The reading room is also where the collection stands; the yard holds a gazebo; the store has no area.
    "INSERT INTO inventory_room_areas (location_id, type) VALUES (1, 'koleksi'), (1, 'baca'), (2, 'gazebo')",
    "INSERT INTO inventory_items (id, location_id, item_name, item_code, item_condition, category, item_type) VALUES
        (1, 1, 'Meja baca', 'INV-1', 'B', 'perabot', 'Meja baca'),
        (2, 1, 'Komputer OPAC', 'INV-2', 'KB', 'komputer,multimedia', 'Kiosk OPAC'),
        (3, 2, 'Bangku gazebo', 'INV-3', 'B', 'perabot', ''),
        (4, 2, 'Pot bunga', 'INV-4', 'B', NULL, ''),
        (5, 3, 'Troli buku', 'INV-5', 'RB', 'peralatan', 'Troli buku')",
    "INSERT INTO inventory_item_photos (item_id, filename) VALUES (1, 'meja-a.jpg'), (1, 'meja-b.jpg'), (3, 'bangku.jpg')",
] as $sql) {
    $db->exec($sql);
}
$names = static fn (array $catalog): array => array_map(static fn ($group) => [$group['label'] => array_column($group['items'], 'item_name')], $catalog['groups']);

// By category.
$all = InventoryCatalog::build($db, 'category', []);
check($all['items'] === 5 && $all['with_photos'] === 2, 'semua barang masuk daftar, dan barang berfoto dihitung sekali');
check($names($all) === [
    ['Perabot (meja, kursi, rak)' => ['Bangku gazebo', 'Meja baca']],
    ['Peralatan perpustakaan' => ['Troli buku']],
    ['Komputer' => ['Komputer OPAC']],
    ['Perangkat multimedia' => ['Komputer OPAC']],
    ['Belum berkategori' => ['Pot bunga']],
], 'barang dikelompokkan per kategori; barang berkategori ganda tercantum di tiap kategorinya, kelompok kosong dilewati');
check($all['groups'][0]['items'][1]['photos'] === ['meja-a.jpg'] && $all['photo_count'] === 2, 'satu foto per barang: foto pertamanya');
$every = InventoryCatalog::build($db, 'category', ['perabot'], '', 'all');
check($every['groups'][0]['items'][1]['photos'] === ['meja-a.jpg', 'meja-b.jpg'] && $every['photo_count'] === 3 && $every['with_photos'] === 2, 'semua foto: tiap foto barang ikut, barang berfoto tetap dihitung sekali');
$furniture = InventoryCatalog::build($db, 'category', ['peralatan', 'perabot'], 'P01');
check($furniture['items'] === 2 && $furniture['categories'] === ['perabot', 'peralatan'] && $names($furniture) === [['Perabot (meja, kursi, rak)' => ['Bangku gazebo', 'Meja baca']]],
    'saringan kategori dan lokasi perpustakaan mempersempit daftar');

// By area.
$areas = InventoryCatalog::build($db, 'area', ['perabot', 'peralatan']);
check($names($areas) === [
    ['Area koleksi' => ['Meja baca']],
    ['Area baca' => ['Meja baca']],
    ['Gazebo' => ['Bangku gazebo']],
    ['Ruangan tanpa area' => ['Troli buku']],
], 'barang tercantum di tiap area ruangannya, dan ruangan tanpa area punya kelompok sendiri');
$summary = InventoryCatalog::summary($areas);
check($summary['items'] === 3 && $summary['groups'][2] === ['label' => 'Gazebo', 'items' => 1, 'with_photos' => 1] && $summary['groups'][3]['with_photos'] === 0, 'ringkasan menghitung barang dan foto tiap kelompok');

// What is refused.
rejects(static fn () => InventoryCatalog::build($db, 'ruangan', []), 'pengelompokan lain ditolak', 'kategori atau area');
rejects(static fn () => InventoryCatalog::build($db, 'category', ['mebel']), 'kategori yang tidak dikenal ditolak', 'Kategori barang tidak valid');
rejects(static fn () => InventoryCatalog::build($db, 'category', [], '', 'dua'), 'pilihan foto lain ditolak', 'satu foto per barang atau semua foto');
$db->exec('INSERT INTO inventory_items (id, location_id, item_name, item_code, item_condition) WITH RECURSIVE n(i) AS (SELECT 100 UNION ALL SELECT i + 1 FROM n WHERE i < 600) SELECT i, 3, \'Kursi\', \'K-\' || i, \'B\' FROM n');
rejects(static fn () => InventoryCatalog::build($db, 'category', []), 'daftar di atas 500 barang ditolak dengan saran menyaring', 'Saring menurut kategori');
check(InventoryCatalog::build($db, 'category', ['perabot'])['items'] === 2, 'daftar yang disaring tetap dapat dibuat');

// What is printed.
$picture = imagecreatetruecolor(800, 600);
ob_start(); imagejpeg($picture); $jpeg = (string) ob_get_clean();
$read = [];
$html = InventoryCatalog::html($areas, static function (string $filename) use (&$read, $jpeg): ?string { $read[] = $filename; return $filename === 'meja-a.jpg' ? $jpeg : null; }, ['library_name' => 'Perpustakaan <Pusat>', 'printed_by' => 'Rina']);
check(str_contains($html, 'Area koleksi (1 barang)') && str_contains($html, 'Gazebo (1 barang)') && str_contains($html, 'Perpustakaan &lt;Pusat&gt;'), 'tiap kelompok dicetak dengan jumlah barangnya, dan teks di-escape');
check($read === ['meja-a.jpg', 'bangku.jpg'] && substr_count($html, 'data:image/jpeg;base64,') === 2, 'foto dibaca sekali per barang walau tercantum di dua area');
preg_match('/src="data:image\/jpeg;base64,([^"]+)"/', $html, $found);
$size = getimagesizefromstring((string) base64_decode($found[1]));
check($size[0] === 320 && $size[1] === 240, 'foto diperkecil sebelum dicetak');
check(substr_count($html, 'Belum ada foto') === 2, 'barang tanpa foto, atau yang fotonya tidak terbaca, ditandai');
$html = InventoryCatalog::html($every, static fn (string $filename): ?string => $jpeg);
check(substr_count($html, 'data:image/jpeg;base64,') === 3 && str_contains($html, '3 foto'), 'semua foto barang dicetak berdampingan');
$db->exec('INSERT INTO inventory_item_photos (item_id, filename) WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 500) SELECT 1, \'banyak-\' || i || \'.jpg\' FROM n');
rejects(static fn () => InventoryCatalog::build($db, 'category', ['perabot'], '', 'all'), 'daftar di atas 500 foto ditolak dengan saran', 'cetak satu foto per barang');
check(InventoryCatalog::build($db, 'category', ['perabot'])['photo_count'] === 2, 'satu foto per barang tetap dapat dibuat');
echo "ok   done\n";
