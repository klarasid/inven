<?php

declare(strict_types=1);

// Foto area: what an area keeps, what is refused, and what goes with the area or the room.
define('SB', sys_get_temp_dir() . '/inventory-area-photo-test-' . bin2hex(random_bytes(6)) . '/');
require __DIR__ . '/../src/AreaPhotos.php';

use SLiMS\Plugins\Inventory\AreaPhotos;

function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL ' . $label); echo "ok   $label\n"; }
function rejects(callable $run, string $label, string $message): void
{
    try { $run(); } catch (RuntimeException $error) { check(str_contains($error->getMessage(), $message), $label); return; }
    check(false, $label);
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([
    'CREATE TABLE inventory_room_areas (id INTEGER PRIMARY KEY, location_id INTEGER, type TEXT, name TEXT NOT NULL DEFAULT \'\')',
    'CREATE TABLE inventory_area_photos (id INTEGER PRIMARY KEY, area_id INTEGER, filename TEXT, created_by INTEGER, created_at TEXT)',
    "INSERT INTO inventory_room_areas (id, location_id, type) VALUES (1, 5, 'toilet'), (2, 5, 'baca'), (3, 6, 'parkir')",
] as $sql) {
    $db->exec($sql);
}
$picture = static function (int $shade): string {
    $image = imagecreatetruecolor(1600, 1200);
    imagefill($image, 0, 0, imagecolorallocate($image, $shade, 120, 90));
    ob_start(); imagepng($image); return (string) ob_get_clean();
};
$storage = AreaPhotos::storage();
$now = '2026-10-06 09:00:00';
$files = static fn (): array => array_values(array_filter(glob(rtrim(SB, '/') . '/images/inventaris-barang/area/*.jpg') ?: []));
try {
    $first = AreaPhotos::store($db, $storage, 5, 1, $picture(10), 7, $now);
    AreaPhotos::store($db, $storage, 5, 1, $picture(20), 7, $now);
    AreaPhotos::store($db, $storage, 5, 2, $picture(30), 7, $now);
    $lot = AreaPhotos::store($db, $storage, 6, 3, $picture(40), 7, $now);
    $kept = AreaPhotos::byArea($db, 5);
    check(count($kept[1]) === 2 && count($kept[2]) === 1 && !isset($kept[3]) && count(AreaPhotos::byArea($db)) === 3, 'foto tercatat pada areanya, dan dapat diminta per ruangan');
    $size = getimagesizefromstring((string) AreaPhotos::read($db, $storage, $first));
    check($size['mime'] === 'image/jpeg' && $size[0] === 1280, 'foto disimpan sebagai JPEG yang diperkecil, seperti foto barang');

    rejects(static fn () => AreaPhotos::store($db, $storage, 6, 1, $picture(50), 7, $now), 'area ruangan lain ditolak', 'Area tidak ditemukan di ruangan ini');
    rejects(static fn () => AreaPhotos::store($db, $storage, 5, 1, 'bukan gambar', 7, $now), 'berkas yang bukan gambar ditolak', 'gambar JPEG, PNG, atau WebP');
    AreaPhotos::store($db, $storage, 5, 1, $picture(60), 7, $now);
    rejects(static fn () => AreaPhotos::store($db, $storage, 5, 1, $picture(70), 7, $now), 'foto keempat ditolak', 'paling banyak 3 foto');
    rejects(static fn () => AreaPhotos::add($db, $storage, 5, 2, [], 7, $now), 'unggahan tanpa berkas ditolak', 'Pilih foto area');
    check(count($files()) === 5, 'unggahan yang ditolak tidak meninggalkan berkas');

    rejects(static fn () => AreaPhotos::delete($db, $storage, 6, $first), 'foto area ruangan lain tidak dapat dihapus', 'tidak ditemukan pada area di ruangan ini');
    AreaPhotos::delete($db, $storage, 5, $first);
    check(AreaPhotos::read($db, $storage, $first) === null && count($files()) === 4, 'menghapus foto menghapus berkasnya');

    $storage->cleanup(AreaPhotos::deleteArea($db, 1));
    check(!isset(AreaPhotos::byArea($db)[1]) && count($files()) === 2, 'foto ikut terhapus bersama areanya');
    $storage->cleanup(AreaPhotos::deleteRoom($db, 5));
    check(array_keys(AreaPhotos::byArea($db)) === [3] && count($files()) === 1 && AreaPhotos::read($db, $storage, $lot) !== null, 'foto ikut terhapus bersama ruangannya, milik ruangan lain tetap');
    echo "ok   done\n";
} finally {
    exec('rm -rf ' . escapeshellarg(rtrim(SB, '/')));
}
