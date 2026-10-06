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
check(Sarpras::categories(['kenyamanan', 'perabot']) === 'perabot,kenyamanan' && strlen((string) Sarpras::categories(array_keys(Sarpras::CATEGORIES))) <= 100, 'sarana kenyamanan adalah kategori, dan semua kategori sekaligus muat di kolomnya');
check(Sarpras::categories('') === null && Sarpras::categories(null) === null && Sarpras::categories([]) === null, 'tanpa kategori disimpan kosong');
rejects(static fn () => Sarpras::categories('komputer,pesawat'), 'kategori yang tidak dikenal ditolak');
rejects(static fn () => Sarpras::categories(['komputer', ['multimedia']]), 'bentuk kategori yang tidak wajar ditolak');
check(Sarpras::categoryCodes('komputer,multimedia') === ['komputer', 'multimedia'] && Sarpras::categoryCodes(null) === [] && Sarpras::categoryCodes('komputer,multim') === ['komputer'], 'nilai tersimpan dibaca sebagai daftar kode yang dikenal');
check(strlen((string) Sarpras::categories(array_keys(Sarpras::CATEGORIES))) <= 100, 'semua kategori sekaligus muat di kolomnya');

// The ten multimedia devices the accreditation instrument names each have a type to pick.
check(!array_diff(['Komputer multimedia', 'Proyektor', 'Pengeras suara', 'Headphone', 'Panel interaktif', 'Printer 3D', 'Perangkat VR', 'Perekam suara', 'Kamera', 'Pemutar audio'], Sarpras::lists()['types']['multimedia']),
    'saran jenis multimedia memuat sepuluh perangkat multimedia yang umum');

// The security and safety devices the instrument names each have a type to pick, too.
check(!array_diff(['CCTV', 'Security gate', 'Mesin peminjaman mandiri', 'Gerbang / perangkat RFID', 'Loker penitipan', 'APAR', 'Alarm kebakaran', 'Rambu dan jalur evakuasi', 'Pintu darurat', 'Lampu darurat'], Sarpras::lists()['types']['keamanan']),
    'saran jenis keamanan memuat sarana keamanan koleksi dan keselamatan yang umum');

// What the recap counts -------------------------------------------------------------------------
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([
    'CREATE TABLE setting (setting_name TEXT PRIMARY KEY, setting_value TEXT)',
    'CREATE TABLE mst_location (location_id TEXT PRIMARY KEY, location_name TEXT)',
    // SLiMS's members: sivitas is counted from them (Sivitas).
    'CREATE TABLE member (member_id TEXT PRIMARY KEY, member_type_id INTEGER, inst_name TEXT, is_pending INTEGER, expire_date TEXT)',
    'CREATE TABLE inventory_locations (id INTEGER PRIMARY KEY, room_name TEXT, location_code TEXT, area_m2 REAL, slims_location_id TEXT)',
    'CREATE TABLE inventory_room_areas (id INTEGER PRIMARY KEY, location_id INTEGER, type TEXT, name TEXT NOT NULL DEFAULT \'\', created_at TEXT, updated_at TEXT)',
    'CREATE TABLE inventory_items (id INTEGER PRIMARY KEY, location_id INTEGER, item_name TEXT, category TEXT, item_type TEXT NOT NULL DEFAULT \'\', item_condition TEXT)',
    'CREATE TABLE inventory_software (id INTEGER PRIMARY KEY, name TEXT, version TEXT, licence TEXT, valid_until TEXT)',
    'CREATE TABLE inventory_support_documents (id INTEGER PRIMARY KEY, library_code TEXT NOT NULL DEFAULT \'\', kind TEXT, location_id INTEGER, title TEXT, filename TEXT, mime TEXT, created_by INTEGER, created_at TEXT)',
    'CREATE TABLE inventory_watch_schedules (id INTEGER PRIMARY KEY, location_id INTEGER, snapshot TEXT, frequency TEXT, start_date TEXT, end_date TEXT, active INTEGER)',
    'CREATE TABLE inventory_watch_inspections (id INTEGER PRIMARY KEY, schedule_id INTEGER, library_code TEXT, room_key INTEGER, kind TEXT, status TEXT, due_date TEXT)',
    'CREATE TABLE inventory_watch_results (id INTEGER PRIMARY KEY, inspection_id INTEGER, outcome TEXT)',
    'CREATE TABLE inventory_watch_findings (id INTEGER PRIMARY KEY, inspection_id INTEGER, status TEXT, deadline TEXT)',
    'CREATE TABLE inventory_watch_actions (id INTEGER PRIMARY KEY, finding_id INTEGER, submitted_at TEXT)',
    "INSERT INTO inventory_locations (id, room_name) VALUES (1, 'Ruang Baca'), (2, 'Ruang Koleksi')",
    "INSERT INTO inventory_room_areas (location_id, type) VALUES (1, 'baca'), (2, 'koleksi')",
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
check(array_column($aspect(5)['checks'], 'ok', 'label') == ['Komputer di area baca' => true, 'Komputer di area koleksi' => false], 'komputer yang rusak berat tidak melayani fungsi ruangannya');
check($aspect(7)['value'] === '2 jenis' && $aspect(7)['rows'] === [['PC', '1'], ['Proyektor', '1']], 'barang yang sama juga dihitung sebagai perangkat multimedia');
check($aspect(4)['basis'] !== '' && str_starts_with($aspect(4)['basis'], '1 barang'), 'kategori lain tidak ikut terhitung');
check($aspect(4)['columns'] === ['Area', 'Perabot dan peralatan'] && $aspect(4)['rows'] === [['Area baca', '—'], ['Area koleksi', 'Meja (1)']], 'perabot dan peralatan dirinci per area ruangannya');
check($recap['counts']['uncategorized'] === 1 && $recap['counts']['items'] === 5, 'hanya barang tanpa kategori yang dihitung belum berkategori');
// Sivitas comes from SLiMS's active members, not from a figure typed in.
$sivitasCheck = static fn (array $recap): bool => array_column(array_values(array_filter($recap['aspects'], static fn ($a) => $a['no'] === 1))[0]['checks'], 'ok', 'label')['Ada sivitas (anggota aktif) yang dilayani'];
check(!$sivitasCheck($recap), 'tanpa anggota aktif, belum ada sivitas');
$db->exec("INSERT INTO member VALUES ('A1', 1, 'Gizi', 0, '2099-01-01'), ('A2', 1, 'Gizi', 1, '2099-01-01')");
check($sivitasCheck(Sarpras::recap($db, new Supervision($db, new PhotoStorage(sys_get_temp_dir() . '/inventory-test-unused')))), 'anggota aktif dihitung sebagai sivitas di rekap');
// Supporting areas named by the accreditation instrument, and the open ones among them.
$db->exec("INSERT INTO inventory_locations (id, room_name) VALUES (3, 'Halaman')");
$db->exec("INSERT INTO inventory_room_areas (location_id, type) VALUES (3, 'gazebo'), (3, 'taman_baca'), (3, 'taman_literasi'), (2, 'disabilitas')");
$open = array_column(Sarpras::recap($db, new Supervision($db, new PhotoStorage(sys_get_temp_dir() . '/inventory-test-unused')))['aspects'], null, 'no');
$aspect = static fn (int $no): array => $open[$no];
check(str_ends_with($aspect(2)['value'], '4 area pendukung') && in_array(['Halaman', 'Gazebo, Taman baca, Taman literasi'], $aspect(2)['rows'], true), 'ruang disabilitas, gazebo, taman baca, dan taman literasi dihitung sebagai area pendukung');
check(array_column($aspect(5)['checks'], 'label') === ['Komputer di area baca', 'Komputer di area koleksi', 'Komputer di ruang layanan disabilitas'] && str_contains($aspect(5)['basis'], '1 dari 3 fungsi layanan'), 'area terbuka tidak dituntut memiliki komputer');
// The recap's "Bukti pengukuran diunggah": a speed test among the location's supporting documents.
$evidence = static fn (): bool => array_column(array_column(Sarpras::recap($db, new Supervision($db, new PhotoStorage(sys_get_temp_dir() . '/inventory-test-unused')))['aspects'], null, 'no')[6]['checks'], 'ok', 'label')['Bukti pengukuran diunggah'];
$db->exec("INSERT INTO inventory_support_documents (library_code, kind, title) VALUES ('', 'isp', 'Tagihan ISP'), ('P09', 'speedtest', 'Uji lokasi lain')");
check(!$evidence(), 'dokumen ISP, atau uji kecepatan lokasi lain, belum menjadi bukti pengukuran');
$db->exec("INSERT INTO inventory_support_documents (library_code, kind, title) VALUES ('', 'speedtest', 'Uji kecepatan')");
check($evidence(), 'hasil uji kecepatan lokasi ini memenuhi bukti pengukuran');
echo "ok   done\n";
