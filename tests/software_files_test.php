<?php

declare(strict_types=1);

// Bukti lisensi: which files an application lists, what is refused, and what deleting removes.
define('SB', sys_get_temp_dir() . '/inventory-licence-test-' . bin2hex(random_bytes(6)) . '/');
require __DIR__ . '/../src/SoftwareFiles.php';

use SLiMS\Plugins\Inventory\SoftwareFiles;

function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL ' . $label); echo "ok   $label\n"; }
function rejects(callable $run, string $label, string $message): void
{
    try { $run(); } catch (RuntimeException $error) { check(str_contains($error->getMessage(), $message), $label); return; }
    check(false, $label);
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([
    'CREATE TABLE inventory_software (id INTEGER PRIMARY KEY, name TEXT)',
    'CREATE TABLE inventory_software_files (id INTEGER PRIMARY KEY, software_id INTEGER, title TEXT, filename TEXT, mime TEXT, created_by INTEGER, created_at TEXT)',
    "INSERT INTO inventory_software VALUES (1, 'Windows 11 Pro'), (2, 'SLiMS')",
] as $sql) {
    $db->exec($sql);
}
$dir = SoftwareFiles::directory();
mkdir($dir, 0700, true);
$store = static function (int $software, string $title, string $hex, string $type = 'png') use ($db, $dir): string {
    $filename = 'lisensi-' . str_repeat($hex, 16) . '.' . $type;
    file_put_contents($dir . '/' . $filename, 'file ' . $title);
    $db->prepare('INSERT INTO inventory_software_files (software_id, title, filename, mime, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$software, $title, $filename, $type === 'pdf' ? 'application/pdf' : 'image/png', '2026-10-01 08:00:00']);
    return $filename;
};
try {
    $sticker = $store(1, 'Stiker COA', 'aa');
    $invoice = $store(1, 'Faktur / pembelian 2025', 'bb', 'pdf');
    $about = $store(2, 'Halaman lisensi', 'cc');

    $files = SoftwareFiles::bySoftware($db);
    check(array_column($files[1], 'title') === ['Stiker COA', 'Faktur / pembelian 2025'] && array_column($files[2], 'title') === ['Halaman lisensi'], 'berkas bukti dikelompokkan per aplikasi, menurut urutan unggahnya');

    $file = SoftwareFiles::file($db, $files[1][1]['id']);
    check($file !== null && $file['mime'] === 'application/pdf' && $file['name'] === 'faktur-pembelian-2025.pdf' && file_get_contents($file['path']) === 'file Faktur / pembelian 2025', 'berkas dikirim dengan nama unduhan yang aman');
    $db->exec("INSERT INTO inventory_software_files (software_id, title, filename, mime, created_at) VALUES (2, 'Jebakan', '../../config/database.php', 'application/pdf', '2026-10-01')");
    check(SoftwareFiles::file($db, (int) $db->lastInsertId()) === null && SoftwareFiles::file($db, 999) === null, 'nama berkas di luar pola, atau berkas yang tidak ada, tidak dilayani');

    rejects(static fn () => SoftwareFiles::upload($db, 1, [], '', 1, '2026-10-01 08:00:00'), 'unggahan tanpa berkas ditolak', 'Pilih berkas bukti lisensi');
    rejects(static fn () => SoftwareFiles::delete($db, 2, $files[1][0]['id']), 'berkas aplikasi lain tidak dapat dihapus', 'tidak ditemukan pada aplikasi ini');

    SoftwareFiles::delete($db, 1, $files[1][0]['id']);
    check(!is_file($dir . '/' . $sticker) && is_file($dir . '/' . $invoice) && count(SoftwareFiles::bySoftware($db)[1]) === 1, 'menghapus satu berkas menghapus berkas itu saja');
    SoftwareFiles::deleteSoftware($db, 1);
    check(!is_file($dir . '/' . $invoice) && is_file($dir . '/' . $about) && !isset(SoftwareFiles::bySoftware($db)[1]), 'berkas bukti ikut terhapus bersama aplikasinya, milik aplikasi lain tetap');
    echo "ok   done\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $path) unlink($path);
    rmdir($dir); rmdir(dirname($dir)); rmdir(dirname($dir, 2)); rmdir(rtrim(SB, '/'));
}
