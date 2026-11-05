<?php

declare(strict_types=1);

// Signature specimens of a room's KIR: an upload is read as a picture and kept as a PNG, one per signer,
// replaced or removed with the room form, and printed in the KIR's signature space. In-memory SQLite.
define('SB', sys_get_temp_dir() . '/specimens-' . bin2hex(random_bytes(4)) . '/');
mkdir(SB . 'images/inventaris-barang', 0775, true);
require __DIR__ . '/../src/RoomSpecimens.php';
require __DIR__ . '/../src/PdfLayout.php';
require __DIR__ . '/../src/PdfTemplate.php';
require __DIR__ . '/../src/PdfTemplateModern.php';

use SLiMS\Plugins\Inventory\PdfTemplate;
use SLiMS\Plugins\Inventory\PdfTemplateModern;
use SLiMS\Plugins\Inventory\RoomSpecimens;

function check(bool $ok, string $label): void { if (!$ok) { echo "FAIL $label\n"; exit(1); } echo "ok   $label\n"; }
function rejects(callable $run, string $label, string $message): void
{
    try { $run(); } catch (RuntimeException $error) { check(str_contains($error->getMessage(), $message), $label); return; }
    check(false, $label);
}

$dir = sys_get_temp_dir() . '/specimen-src-' . bin2hex(random_bytes(4));
mkdir($dir);
$make = static function (string $name, int $w, int $h, string $type) use ($dir): string {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 20, 40, 160));
    $path = "$dir/$name";
    $type === 'jpeg' ? imagejpeg($im, $path) : imagepng($im, $path);
    return $path;
};
$png = $make('ttd.png', 1200, 400, 'png');
$jpg = $make('ttd.jpg', 300, 100, 'jpeg');

// normalize: any accepted picture becomes a PNG of at most 600 px.
$big = RoomSpecimens::normalize($png, 'Yang mengetahui');
$info = getimagesizefromstring($big);
check($info !== false && $info['mime'] === 'image/png' && max($info[0], $info[1]) === 600 && $info[0] === 600 && $info[1] === 200, 'gambar besar diperkecil menjadi PNG maksimal 600 px');
$small = getimagesizefromstring(RoomSpecimens::normalize($jpg));
check($small !== false && $small[0] === 300 && $small['mime'] === 'image/png', 'JPEG kecil diubah ke PNG tanpa diperbesar');

file_put_contents("$dir/bukan.png", '<?php echo 1;');
rejects(static fn() => RoomSpecimens::normalize("$dir/bukan.png", 'Pengurus barang'), 'berkas yang bukan gambar ditolak', 'harus berupa gambar PNG, JPEG, atau WebP');
file_put_contents("$dir/besar.png", str_repeat('A', RoomSpecimens::MAX_BYTES + 1));
rejects(static fn() => RoomSpecimens::normalize("$dir/besar.png"), 'berkas di atas 2 MB ditolak', 'maksimal 2 MB');

// requested: removal needs the flag; nothing asked changes nothing; a failed upload is refused.
check(RoomSpecimens::requested([], []) === [] && RoomSpecimens::requested([], ['remove_specimen_manager' => '1']) === ['manager' => null], 'tanpa permintaan tidak ada perubahan; penghapusan memakai penanda');
rejects(static fn() => RoomSpecimens::requested(['specimen_knowing' => ['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '']], []), 'unggahan gagal ditolak dengan menyebut penandatangan', 'yang mengetahui');
rejects(static fn() => RoomSpecimens::requested(['specimen_manager' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => $png]], []), 'berkas yang bukan hasil unggahan HTTP ditolak', 'pengurus barang');

// save, replace and remove, with the files that go with them.
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE inventory_room_specimens (location_id INTEGER, role TEXT, filename TEXT, updated_at TEXT, PRIMARY KEY (location_id, role))');
// PhotoStorage::protect needs the folder; keep it simple for the test.
@mkdir(RoomSpecimens::directory(), 0775, true);
RoomSpecimens::save($db, 5, ['knowing' => $big, 'manager' => $big], '2026-11-05 10:00:00');
check(RoomSpecimens::roles($db, 5) === ['knowing', 'manager'] && count(glob(RoomSpecimens::directory() . '/spesimen-*.png')) === 2, 'spesimen kedua penandatangan tersimpan');
$first = RoomSpecimens::path($db, 5, 'knowing');
RoomSpecimens::save($db, 5, ['knowing' => RoomSpecimens::normalize($jpg), 'manager' => null], '2026-11-05 11:00:00');
check(RoomSpecimens::roles($db, 5) === ['knowing'] && $first !== null && !is_file($first) && count(glob(RoomSpecimens::directory() . '/spesimen-*.png')) === 1, 'mengganti dan menghapus membuang berkas lamanya');
check(RoomSpecimens::path($db, 6, 'knowing') === null && RoomSpecimens::path($db, 5, 'bendahara') === null, 'ruangan lain atau penandatangan tak dikenal tidak punya spesimen');

// printing: the specimen sits in the signature space of both layouts, and only when asked.
$uris = RoomSpecimens::dataUris($db, 5);
check(array_keys($uris) === ['knowing'] && str_starts_with($uris['knowing'], 'data:image/png;base64,'), 'spesimen untuk KIR berupa data URI per penandatangan');
$room = ['room_name' => 'Ruang Baca', 'knowing_name' => 'Dr. A', 'manager_name' => 'B'];
foreach (['klasik' => PdfTemplate::render($room, [], null, $uris), 'modern' => PdfTemplateModern::render($room, [], null, $uris)] as $layout => $html) {
    check(substr_count($html, 'data:image/png;base64,') === 1, "KIR $layout memuat spesimen penandatangan yang punya");
}
check(!str_contains(PdfTemplate::render($room, []), 'data:image') && !str_contains(PdfTemplateModern::render($room, []), 'data:image'), 'tanpa pilihan spesimen KIR tercetak seperti biasa');

// the room goes: its files are named for removal once the deletion commits.
$files = RoomSpecimens::deleteRoom($db, 5);
RoomSpecimens::cleanup($files);
check(RoomSpecimens::roles($db, 5) === [] && count(glob(RoomSpecimens::directory() . '/spesimen-*.png')) === 0, 'spesimen ikut terhapus bersama ruangannya');
echo "ok   done\n";
