<?php

declare(strict_types=1);
// The Area and Denah tabs of a room: what may be recorded, what the old ticked functions become,
// and how Rekap Sarpras counts areas. Runs on an in-memory SQLite database; needs no MySQL.

define('SB', sys_get_temp_dir() . '/inventory-room-test-' . bin2hex(random_bytes(6)) . '/');
require __DIR__ . '/../src/PhotoStorage.php';
require __DIR__ . '/../src/WatchRecurrence.php';
require __DIR__ . '/../src/Supervision.php';
require __DIR__ . '/../src/PdfLayout.php';
require __DIR__ . '/../src/Sarpras.php';
require_once __DIR__ . '/../src/RoomAreas.php';
require_once __DIR__ . '/../src/RoomPlans.php';

use SLiMS\Plugins\Inventory\PhotoStorage;
use SLiMS\Plugins\Inventory\RoomAreas;
use SLiMS\Plugins\Inventory\RoomPlans;
use SLiMS\Plugins\Inventory\Sarpras;
use SLiMS\Plugins\Inventory\Supervision;

function check(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'ok   ' . $label . PHP_EOL;
}
function rejects(callable $operation, string $label): void
{
    try { $operation(); } catch (RuntimeException $e) { check(!$e instanceof PDOException, $label); return; }
    throw new RuntimeException('Tidak ditolak: ' . $label);
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([
    'CREATE TABLE setting (setting_name TEXT PRIMARY KEY, setting_value TEXT)',
    'CREATE TABLE mst_location (location_id TEXT PRIMARY KEY, location_name TEXT)',
    'CREATE TABLE inventory_locations (id INTEGER PRIMARY KEY, room_name TEXT, location_code TEXT, area_m2 REAL, room_functions TEXT NOT NULL DEFAULT \'\', slims_location_id TEXT)',
    'CREATE TABLE inventory_room_areas (id INTEGER PRIMARY KEY, location_id INTEGER, type TEXT, name TEXT NOT NULL DEFAULT \'\', created_at TEXT, updated_at TEXT)',
    'CREATE TABLE inventory_room_plans (id INTEGER PRIMARY KEY, location_id INTEGER, title TEXT, filename TEXT, mime TEXT, created_by INTEGER, created_at TEXT)',
    'CREATE TABLE inventory_items (id INTEGER PRIMARY KEY, location_id INTEGER, item_name TEXT, category TEXT, item_type TEXT NOT NULL DEFAULT \'\', item_condition TEXT)',
    'CREATE TABLE inventory_software (id INTEGER PRIMARY KEY, name TEXT, version TEXT, licence TEXT, valid_until TEXT)',
    'CREATE TABLE inventory_watch_schedules (id INTEGER PRIMARY KEY, location_id INTEGER, snapshot TEXT, frequency TEXT, start_date TEXT, end_date TEXT, active INTEGER)',
    'CREATE TABLE inventory_watch_inspections (id INTEGER PRIMARY KEY, schedule_id INTEGER, library_code TEXT, room_key INTEGER, kind TEXT, status TEXT, due_date TEXT)',
    'CREATE TABLE inventory_watch_results (id INTEGER PRIMARY KEY, inspection_id INTEGER, outcome TEXT)',
    'CREATE TABLE inventory_watch_findings (id INTEGER PRIMARY KEY, inspection_id INTEGER, status TEXT, deadline TEXT)',
    'CREATE TABLE inventory_watch_actions (id INTEGER PRIMARY KEY, finding_id INTEGER, submitted_at TEXT)',
    "INSERT INTO mst_location VALUES ('00', 'Kampus Utama'), ('10', 'Kampus Dua')",
    // Rooms 1 and 2 had functions ticked before areas existed; room 3 is a toilet; room 4 is at another location.
    "INSERT INTO inventory_locations (id, room_name, room_functions, slims_location_id) VALUES
        (1, 'Ruang Perpustakaan', 'koleksi,baca,kerja,layanan,diskusi', '00'),
        (2, 'Ruang Multimedia', 'multimedia,tidak_dikenal', '00'),
        (3, 'Toilet Lantai 1', '', '00'),
        (4, 'Ruang Baca Kampus Dua', '', '10')",
] as $sql) {
    $db->exec($sql);
}
$now = '2026-10-02 09:00:00';
$types = static fn(int $room): array => array_column(RoomAreas::of($db, $room), 'type');

// The old ticked functions become areas ----------------------------------------------------------
check(RoomAreas::importFunctions($db, $now) === 6, 'tiap fungsi yang dulu dicentang menjadi satu area');
check($types(1) === ['koleksi', 'baca', 'kerja', 'layanan', 'diskusi'] && $types(2) === ['multimedia'], 'area hasil pindahan mengikuti fungsi ruangannya; kode yang tidak dikenal dilewati');
check(RoomAreas::importFunctions($db, $now) === 0 && count($types(1)) === 5, 'pemindahan yang dijalankan lagi tidak menambah area');

// Recording areas ------------------------------------------------------------------------------
$toilet = RoomAreas::save($db, 3, 0, ['type' => 'toilet', 'name' => ''], $now);
check($types(3) === ['toilet'], 'ruangan toilet dicatat sebagai ruangan dengan satu area');
$corner = RoomAreas::save($db, 1, 0, ['type' => 'baca', 'name' => 'Pojok baca anak'], $now);
check(RoomAreas::of($db, 1)[2] === ['id' => $corner, 'location_id' => 1, 'type' => 'baca', 'name' => 'Pojok baca anak'], 'area sejenis boleh lebih dari satu bila diberi nama, dan tampil berurutan menurut jenisnya');
RoomAreas::save($db, 1, $corner, ['type' => 'literasi', 'name' => 'Pojok baca anak'], $now);
check(in_array('literasi', $types(1), true) && count($types(1)) === 6, 'area dapat diubah jenisnya');
rejects(static fn () => RoomAreas::save($db, 1, 0, ['type' => 'baca', 'name' => ''], $now), 'area yang sama tidak dicatat dua kali');
rejects(static fn () => RoomAreas::save($db, 1, 0, ['type' => 'kolam', 'name' => ''], $now), 'jenis area di luar daftar ditolak');
rejects(static fn () => RoomAreas::save($db, 1, 0, ['type' => ['baca'], 'name' => ''], $now), 'bentuk jenis area yang tidak wajar ditolak');
rejects(static fn () => RoomAreas::save($db, 1, 0, ['type' => 'gudang', 'name' => str_repeat('a', 151)], $now), 'nama area yang terlalu panjang ditolak');
rejects(static fn () => RoomAreas::save($db, 99, 0, ['type' => 'baca', 'name' => ''], $now), 'area untuk ruangan yang tidak ada ditolak');
rejects(static fn () => RoomAreas::save($db, 2, $toilet, ['type' => 'toilet', 'name' => ''], $now), 'area ruangan lain tidak dapat diubah dari ruangan ini');
rejects(static fn () => RoomAreas::delete($db, 2, $toilet), 'area ruangan lain tidak dapat dihapus dari ruangan ini');
check($types(3) === ['toilet'], 'area ruangan lain tetap utuh');

// What the recap counts -------------------------------------------------------------------------
RoomAreas::save($db, 4, 0, ['type' => 'baca', 'name' => ''], $now);
RoomAreas::save($db, 4, 0, ['type' => 'musala', 'name' => ''], $now);
$db->exec("INSERT INTO inventory_items (id, location_id, item_name, category, item_type, item_condition) VALUES
    (1, 1, 'PC layanan', 'komputer', 'PC', 'B'),
    (2, 3, 'Dispenser', 'fasilitas_umum', 'Dispenser air minum', 'B'),
    (3, 3, 'Toilet (catatan lama)', 'fasilitas_umum', 'Toilet', 'B'),
    (4, 3, 'Komputer rusak', 'komputer', 'PC', 'B')");
$watch = new Supervision($db, new PhotoStorage(SB . 'unused'));
$aspect = static function (array $recap, int $no): array {
    foreach ($recap['aspects'] as $aspect) if ($aspect['no'] === $no) return $aspect;
    throw new RuntimeException('Aspek ' . $no . ' tidak ada.');
};
$main = Sarpras::recap($db, $watch, '00');
check($aspect($main, 2)['value'] === '4 dari 4 area dasar · 3 area pendukung' && $aspect($main, 2)['level'] === 'a', 'area layanan dihitung dari area di tiap ruangan');
check($aspect($main, 2)['rows'][2] === ['Toilet Lantai 1', 'Toilet'] && str_contains($aspect($main, 2)['rows'][1][1], 'Area literasi / pojok baca (Pojok baca anak)'), 'rincian menampilkan area tiap ruangan beserta namanya');
check(!in_array('Toilet', array_column($aspect($main, 5)['checks'], 'label'), true) && str_contains($aspect($main, 5)['basis'], 'dari 7 fungsi layanan'), 'toilet bukan fungsi layanan: tidak dituntut memiliki komputer');
check($aspect($main, 10)['rows'] === [['Dispenser air minum', '1'], ['Toilet', '1']] && $aspect($main, 10)['value'] === '2 jenis', 'fasilitas umum dihitung dari area dan barang, dan yang tercatat dua kali dihitung sekali');
check($main['counts']['unclassified_rooms'] === 0, 'ruangan yang memiliki area tidak dihitung belum dicatat');
$second = Sarpras::recap($db, $watch, '10');
check($aspect($second, 10)['rows'] === [['Musala', '1']] && $aspect($second, 2)['value'] === '1 dari 4 area dasar · 0 area pendukung', 'tiap lokasi hanya menghitung area ruangannya sendiri');

// Deleting a room ------------------------------------------------------------------------------
RoomAreas::deleteRoom($db, 4);
check($types(4) === [] && count($types(1)) === 6, 'area ikut terhapus bersama ruangannya saja');

// Floor plans ----------------------------------------------------------------------------------
$work = SB;
mkdir($work . 'images/inventaris-barang/denah', 0700, true);
try {
    $png = $work . 'plan.png';
    $image = imagecreatetruecolor(20, 10);
    imagepng($image, $png);
    imagedestroy($image);
    $pdf = $work . 'plan.pdf';
    file_put_contents($pdf, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    $html = $work . 'plan.html';
    file_put_contents($html, '<html><script>alert(1)</script></html>');
    $fake = $work . 'fake.png';
    file_put_contents($fake, "\x89PNG\r\n\x1a\n" . str_repeat('x', 64));
    check(RoomPlans::mime($png) === 'image/png' && RoomPlans::mime($pdf) === 'application/pdf', 'denah berupa gambar atau PDF diterima');
    rejects(static fn () => RoomPlans::mime($html), 'berkas yang bukan gambar atau PDF ditolak');
    rejects(static fn () => RoomPlans::mime($fake), 'berkas yang hanya berkepala PNG ditolak');
    rejects(static fn () => RoomPlans::mime($work . 'tidak-ada.png'), 'berkas yang tidak ada ditolak');
    check(RoomPlans::title('', 'Denah  Lantai 1.pdf') === 'Denah Lantai 1' && RoomPlans::title(' Lantai 2 ', 'x.pdf') === 'Lantai 2' && RoomPlans::title('', '') === 'Denah', 'judul denah diambil dari isian, lalu nama berkas');
    rejects(static fn () => RoomPlans::title(str_repeat('a', 151), 'x.pdf'), 'judul denah yang terlalu panjang ditolak');

    $stored = 'denah-' . str_repeat('ab', 16) . '.png';
    copy($png, RoomPlans::directory() . '/' . $stored);
    $db->prepare('INSERT INTO inventory_room_plans (location_id, title, filename, mime, created_at) VALUES (?, ?, ?, ?, ?)')->execute([1, 'Lantai 1 "utama"', $stored, 'image/png', $now]);
    $db->prepare('INSERT INTO inventory_room_plans (location_id, title, filename, mime, created_at) VALUES (?, ?, ?, ?, ?)')->execute([1, 'Jalur', '../../../sysconfig.local.inc.php', 'image/png', $now]);
    $file = RoomPlans::file($db, 1);
    check($file !== null && $file['mime'] === 'image/png' && $file['name'] === 'lantai-1-utama.png' && is_file($file['path']), 'denah disajikan dengan nama unduhan yang aman untuk header');
    check(RoomPlans::file($db, 2) === null && RoomPlans::file($db, 99) === null, 'nama berkas di luar pola denah tidak pernah disajikan');
    check(array_column(RoomPlans::of($db, 1), 'title') === ['Lantai 1 "utama"', 'Jalur'] && !array_key_exists('filename', RoomPlans::of($db, 1)[0]), 'daftar denah tidak membuka nama berkasnya');
    rejects(static fn () => RoomPlans::delete($db, 2, 1), 'denah ruangan lain tidak dapat dihapus dari ruangan ini');
    RoomPlans::delete($db, 1, 1);
    check(!is_file(RoomPlans::directory() . '/' . $stored) && count(RoomPlans::of($db, 1)) === 1, 'menghapus denah menghapus berkasnya');
    check(RoomPlans::deleteRoom($db, 1) === ['../../../sysconfig.local.inc.php'] && RoomPlans::of($db, 1) === [], 'denah ikut terhapus bersama ruangannya');
    RoomPlans::cleanup(['../../../sysconfig.local.inc.php', 'plan.png']);
    check(is_file($png), 'pembersihan hanya menyentuh berkas denah');
} finally {
    foreach (array_merge(glob($work . 'images/inventaris-barang/denah/*') ?: [], glob($work . '*.*') ?: []) as $file) @unlink($file);
    foreach (['images/inventaris-barang/denah', 'images/inventaris-barang', 'images', ''] as $dir) @rmdir($work . $dir);
}
echo "ok   done\n";
