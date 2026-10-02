<?php

declare(strict_types=1);
// An item may have several categories and counts under each of them in Rekap Sarpras.
// Runs on an in-memory SQLite database; needs no MySQL.

define('SB', sys_get_temp_dir() . '/');
require __DIR__ . '/../src/PhotoStorage.php';
require __DIR__ . '/../src/WatchRecurrence.php';
require __DIR__ . '/../src/Supervision.php';
require __DIR__ . '/../src/PdfLayout.php';
require __DIR__ . '/../src/Sarpras.php';

use SLiMS\Plugins\Inventory\PhotoStorage;
use SLiMS\Plugins\Inventory\Sarpras;
use SLiMS\Plugins\Inventory\Supervision;

function check(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'ok   ' . $label . PHP_EOL;
}
function rejects(callable $operation, string $label): void
{
    try { $operation(); } catch (RuntimeException $e) { check(true, $label); return; }
    throw new RuntimeException('Tidak ditolak: ' . $label);
}

// What is stored -------------------------------------------------------------------------------
check(Sarpras::categories('komputer') === 'komputer', 'satu kategori disimpan seperti sebelumnya');
check(Sarpras::categories('multimedia,komputer') === 'komputer,multimedia', 'beberapa kategori disimpan dalam urutan tetap');
check(Sarpras::categories(['multimedia', 'komputer', 'komputer']) === 'komputer,multimedia', 'daftar kategori diterima dan tidak berulang');
check(Sarpras::categories('') === null && Sarpras::categories(null) === null && Sarpras::categories([]) === null, 'tanpa kategori disimpan kosong');
rejects(static fn () => Sarpras::categories('komputer,pesawat'), 'kategori yang tidak dikenal ditolak');
rejects(static fn () => Sarpras::categories(['komputer', ['multimedia']]), 'bentuk kategori yang tidak wajar ditolak');
check(Sarpras::categoryCodes('komputer,multimedia') === ['komputer', 'multimedia'] && Sarpras::categoryCodes(null) === [] && Sarpras::categoryCodes('komputer,multim') === ['komputer'], 'nilai tersimpan dibaca sebagai daftar kode yang dikenal');
check(strlen((string) Sarpras::categories(array_keys(Sarpras::CATEGORIES))) <= 100, 'semua kategori sekaligus muat di kolomnya');

// What the recap counts -------------------------------------------------------------------------
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([
    'CREATE TABLE setting (setting_name TEXT PRIMARY KEY, setting_value TEXT)',
    'CREATE TABLE mst_location (location_id TEXT PRIMARY KEY, location_name TEXT)',
    'CREATE TABLE inventory_locations (id INTEGER PRIMARY KEY, room_name TEXT, location_code TEXT, area_m2 REAL, room_functions TEXT NOT NULL DEFAULT \'\', slims_location_id TEXT)',
    'CREATE TABLE inventory_items (id INTEGER PRIMARY KEY, location_id INTEGER, item_name TEXT, category TEXT, item_type TEXT NOT NULL DEFAULT \'\', item_condition TEXT)',
    'CREATE TABLE inventory_software (id INTEGER PRIMARY KEY, name TEXT, version TEXT, licence TEXT, valid_until TEXT)',
    'CREATE TABLE inventory_watch_schedules (id INTEGER PRIMARY KEY, location_id INTEGER, snapshot TEXT, frequency TEXT, start_date TEXT, end_date TEXT, active INTEGER)',
    'CREATE TABLE inventory_watch_inspections (id INTEGER PRIMARY KEY, schedule_id INTEGER, library_code TEXT, room_key INTEGER, kind TEXT, status TEXT, due_date TEXT)',
    'CREATE TABLE inventory_watch_results (id INTEGER PRIMARY KEY, inspection_id INTEGER, outcome TEXT)',
    'CREATE TABLE inventory_watch_findings (id INTEGER PRIMARY KEY, inspection_id INTEGER, status TEXT, deadline TEXT)',
    'CREATE TABLE inventory_watch_actions (id INTEGER PRIMARY KEY, finding_id INTEGER, submitted_at TEXT)',
    "INSERT INTO inventory_locations (id, room_name, room_functions) VALUES (1, 'Ruang Baca', 'baca'), (2, 'Ruang Koleksi', 'koleksi')",
    // A computer that is also multimedia equipment, a projector, a broken smart TV, and a desk.
    "INSERT INTO inventory_items (id, location_id, item_name, category, item_type, item_condition) VALUES
        (1, 1, 'PC layanan', 'komputer,multimedia', 'PC', 'B'),
        (2, 1, 'Proyektor', 'multimedia', 'Proyektor', 'B'),
        (3, 2, 'Smart TV', 'komputer,multimedia', 'Televisi', 'RB'),
        (4, 2, 'Meja', 'perabot', '', 'B'),
        (5, 2, 'Kardus', NULL, '', 'B')",
] as $sql) {
    $db->exec($sql);
}
$recap = Sarpras::recap($db, new Supervision($db, new PhotoStorage(sys_get_temp_dir() . '/inventory-test-unused')));
$aspect = static function (int $no) use ($recap): array {
    foreach ($recap['aspects'] as $aspect) if ($aspect['no'] === $no) return $aspect;
    throw new RuntimeException('Aspek ' . $no . ' tidak ada.');
};

check(str_starts_with($aspect(5)['basis'], '2 komputer;') && str_contains($aspect(5)['basis'], '1 dari 2 fungsi layanan'), 'barang berkategori komputer dan multimedia dihitung sebagai komputer');
check(array_column($aspect(5)['checks'], 'ok', 'label') === ['Area baca' => true, 'Area koleksi' => false], 'komputer yang rusak berat tidak melayani fungsi ruangannya');
check($aspect(7)['value'] === '2 jenis' && $aspect(7)['rows'] === [['PC', '1'], ['Proyektor', '1']], 'barang yang sama juga dihitung sebagai perangkat multimedia');
check($aspect(4)['basis'] !== '' && str_starts_with($aspect(4)['basis'], '1 barang'), 'kategori lain tidak ikut terhitung');
check($recap['counts']['uncategorized'] === 1 && $recap['counts']['items'] === 5, 'hanya barang tanpa kategori yang dihitung belum berkategori');
echo "ok   done\n";
