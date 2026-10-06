<?php

declare(strict_types=1);

// Dokumen pendukung: which documents a location lists, by topic, what is refused, and what deleting removes.
define('SB', sys_get_temp_dir() . '/inventory-support-test-' . bin2hex(random_bytes(6)) . '/');
require __DIR__ . '/../src/SupportDocuments.php';

use SLiMS\Plugins\Inventory\SupportDocuments;

function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL ' . $label); echo "ok   $label\n"; }
function rejects(callable $run, string $label, string $message): void
{
    try { $run(); } catch (RuntimeException $error) { check(str_contains($error->getMessage(), $message), $label); return; }
    check(false, $label);
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([
    'CREATE TABLE inventory_locations (id INTEGER PRIMARY KEY, room_name TEXT, slims_location_id TEXT)',
    'CREATE TABLE inventory_support_documents (id INTEGER PRIMARY KEY, library_code TEXT NOT NULL DEFAULT \'\', kind TEXT, location_id INTEGER, title TEXT, filename TEXT, mime TEXT, created_by INTEGER, created_at TEXT)',
    "INSERT INTO inventory_locations VALUES (1, 'Ruang Baca', 'P01'), (2, 'Ruang Referensi', 'P02')",
] as $sql) {
    $db->exec($sql);
}
$dir = SupportDocuments::directory();
mkdir($dir, 0700, true);
$store = static function (string $library, string $kind, ?int $room, string $title, string $hex, string $type = 'pdf') use ($db, $dir): string {
    $filename = 'dokumen-' . str_repeat($hex, 16) . '.' . $type;
    file_put_contents($dir . '/' . $filename, 'file ' . $title);
    $db->prepare('INSERT INTO inventory_support_documents (library_code, kind, location_id, title, filename, mime, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$library, $kind, $room, $title, $filename, $type === 'pdf' ? 'application/pdf' : 'image/png', '2026-10-01 08:00:00']);
    return $filename;
};
try {
    $map = $store('P01', 'wifi', null, 'Peta Wi-Fi lantai 1', 'aa', 'png');
    $isp = $store('P01', 'isp', null, 'Tagihan ISP September', 'bb');
    $speed = $store('P01', 'speedtest', 1, 'Uji kecepatan / Ruang Baca', 'cc');
    $gone = $store('P01', 'speedtest', 9, 'Uji kecepatan ruang yang sudah dihapus', 'dd');
    $store('P02', 'isp', null, 'Kontrak ISP kampus 2', 'ee');
    $drill = $store('P01', 'pelatihan', null, 'Simulasi kebakaran 2026', 'ff');
    $store('P01', 'uji_fungsi', 1, 'Sertifikat isi ulang APAR', '11');
    $store('P01', 'pos', null, 'POS tanggap darurat', '22');

    $listed = SupportDocuments::of($db, 'P01');
    check(array_column($listed, 'title') === ['Uji kecepatan / Ruang Baca', 'Uji kecepatan ruang yang sudah dihapus', 'Tagihan ISP September', 'Peta Wi-Fi lantai 1', 'Sertifikat isi ulang APAR', 'POS tanggap darurat', 'Simulasi kebakaran 2026'], 'dokumen satu lokasi diurutkan menurut jenisnya; lokasi lain tidak ikut');
    check(array_column(SupportDocuments::of($db, 'P01', 'keamanan'), 'title') === ['Sertifikat isi ulang APAR', 'POS tanggap darurat', 'Simulasi kebakaran 2026'] && count(SupportDocuments::of($db, 'P01', 'jaringan')) === 4
        && array_unique(array_column(SupportDocuments::of($db, 'P01', 'keamanan'), 'topic')) === ['keamanan'], 'dokumen dapat diminta per topik: jaringan atau keamanan');
    check(array_keys(SupportDocuments::labels('keamanan')) === ['uji_fungsi', 'pos', 'pelatihan'] && count(SupportDocuments::labels()) === 6, 'tiap topik punya jenis dokumennya sendiri');
    check($listed[0]['room'] === ['id' => 1, 'name' => 'Ruang Baca'] && $listed[1]['room'] === null && $listed[2]['room'] === null, 'uji kecepatan menyebut ruangannya; ruangan yang sudah dihapus tidak disebut');

    $file = SupportDocuments::file($db, $listed[0]['id']);
    check($file !== null && $file['mime'] === 'application/pdf' && $file['name'] === 'uji-kecepatan-ruang-baca.pdf' && file_get_contents($file['path']) === 'file Uji kecepatan / Ruang Baca', 'berkas dikirim dengan nama unduhan yang aman');
    $db->exec("INSERT INTO inventory_support_documents (library_code, kind, title, filename, mime, created_at) VALUES ('P01', 'isp', 'Jebakan', '../../config/database.php', 'application/pdf', '2026-10-01')");
    check(SupportDocuments::file($db, (int) $db->lastInsertId()) === null && SupportDocuments::file($db, 999) === null, 'nama berkas di luar pola, atau dokumen yang tidak ada, tidak dilayani');

    rejects(static fn () => SupportDocuments::upload($db, 'P01', [], 'kontrak', '', 0, 1, '2026-10-01 08:00:00'), 'jenis dokumen lain ditolak', 'Pilih jenis dokumen pendukung');
    rejects(static fn () => SupportDocuments::upload($db, 'P01', [], 'isp', '', 0, 1, '2026-10-01 08:00:00'), 'unggahan tanpa berkas ditolak', 'Pilih berkas dokumen');

    rejects(static fn () => SupportDocuments::delete($db, 'P02', $listed[2]['id']), 'dokumen lokasi lain tidak dapat dihapus', 'tidak ditemukan di lokasi ini');
    SupportDocuments::delete($db, 'P01', $listed[2]['id']);
    check(!is_file($dir . '/' . $isp) && is_file($dir . '/' . $map) && is_file($dir . '/' . $drill) && count(SupportDocuments::of($db, 'P01')) === 7, 'menghapus dokumen menghapus berkasnya saja');

    // A network document uploaded before the folder took its wider name is still found, and removed.
    $former = SupportDocuments::formerDirectory();
    mkdir($former, 0700, true);
    $old = 'jaringan-' . str_repeat('33', 16) . '.pdf';
    file_put_contents($former . '/' . $old, 'file lama');
    $db->prepare('INSERT INTO inventory_support_documents (library_code, kind, title, filename, mime, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute(['P02', 'wifi', 'Peta lama', $old, 'application/pdf', '2026-09-01 08:00:00']);
    $oldId = (int) $db->lastInsertId();
    check(file_get_contents((string) (SupportDocuments::file($db, $oldId)['path'] ?? '')) === 'file lama', 'dokumen jaringan di folder lama masih dapat dibuka');
    SupportDocuments::delete($db, 'P02', $oldId);
    check(!is_file($former . '/' . $old), 'dan berkasnya ikut terhapus dari folder lama');
    rmdir($former);
    echo "ok   done\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $path) unlink($path);
    rmdir($dir); rmdir(dirname($dir)); rmdir(dirname($dir, 2)); rmdir(rtrim(SB, '/'));
}
