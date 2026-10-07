<?php

declare(strict_types=1);

// Masukan: what is kept and sent, what waits when Klaras cannot be reached, and the replies read back.
require __DIR__ . '/../src/Feedback.php';

use SLiMS\Plugins\Inventory\Feedback;
use SLiMS\Plugins\Inventory\Telemetry;

function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL ' . $label); echo "ok   $label\n"; }
function rejects(callable $run, string $label, string $message): void
{
    try { $run(); } catch (RuntimeException $error) { check(str_contains($error->getMessage(), $message), $label); return; }
    check(false, $label);
}

$install = '6f1c2b8e-3d4a-4c5b-9e7f-1a2b3c4d5e6f';
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([
    'CREATE TABLE setting (setting_name TEXT PRIMARY KEY, setting_value TEXT)',
    'CREATE TABLE user (user_id INTEGER PRIMARY KEY, realname TEXT, email TEXT)',
    "CREATE TABLE inventory_feedback (id INTEGER PRIMARY KEY, panel_id TEXT, token TEXT, kind TEXT, message TEXT, page TEXT NOT NULL DEFAULT '', user_id INTEGER, contact INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'pending', issue_url TEXT, attempts INTEGER NOT NULL DEFAULT 0, sent_at TEXT, seen_at TEXT, created_at TEXT)",
    'CREATE TABLE inventory_feedback_replies (id INTEGER PRIMARY KEY, feedback_id INTEGER, panel_reply_id INTEGER, kind TEXT, message TEXT, replied_at TEXT, received_at TEXT)',
    "INSERT INTO user VALUES (7, 'Rina Wulandari', 'rina@example.sch.id'), (8, 'Petugas Baca', '')",
] as $sql) {
    $db->exec($sql);
}
// The installation already has its id, as after its first usage report.
$db->prepare('INSERT INTO setting VALUES (?, ?)')->execute([Telemetry::SETTING, serialize(['install_id' => $install, 'enabled' => true])]);
$GLOBALS['sysconf'] = ['library_name' => 'Perpustakaan Pusat'];

$sent = [];
$panel = 'up';
Feedback::$transport = static function (string $url, array $payload) use (&$sent, &$panel): ?array {
    $sent[] = [$url, $payload];
    if ($panel !== 'up') return null;
    if (str_ends_with($url, '/status')) {
        return ['data' => [[
            'id' => $payload['items'][0]['id'], 'status' => 'planned', 'issue_url' => 'https://github.com/klarasid/inven/issues/57',
            'replies' => [['id' => 3, 'kind' => 'issue_linked', 'message' => 'Kami tindak lanjuti di issue #57.', 'created_at' => '2026-10-07T09:00:00+07:00']],
        ]]];
    }
    return ['data' => ['id' => sprintf('00000000-0000-4000-8000-%012d', count($sent)), 'status' => 'new']];
};

// Sent at once, without the contact unless allowed.
$first = Feedback::submit($db, ['kind' => 'bug', 'message' => "  Tombol cetak KIR\r\ntidak merespons.  ", 'page' => 'inventory<script>', 'contact' => ''], 7, '2026-10-06 09:00:00');
[$url, $payload] = $sent[0];
check(str_ends_with($url, '/api/v1/feedback/plugins/inventaris-barang') && $payload['install_id'] === $install && strlen($payload['token']) === 48, 'masukan dikirim ke Klaras dengan id instalasi dan token buatan sendiri');
check($payload['message'] === "Tombol cetak KIR\ntidak merespons." && $payload['page'] === 'inventoryscript' && $payload['library']['name'] === 'Perpustakaan Pusat' && $payload['environment']['php_version'] === PHP_VERSION, 'pesan, halaman, perpustakaan, dan versi ikut dikirim dalam bentuk yang bersih');
check(!isset($payload['contact']) && $first['status']['key'] === 'new' && $first['author'] === 'Rina Wulandari', 'tanpa izin, kontak tidak dikirim; pengirimnya tetap tercatat di sini');
Feedback::submit($db, ['kind' => 'idea', 'message' => 'Mohon tambahkan ekspor Excel.', 'contact' => '1'], 7, '2026-10-06 09:05:00');
check($sent[1][1]['contact'] === ['name' => 'Rina Wulandari', 'email' => 'rina@example.sch.id'], 'dengan izin, nama dan email petugas ikut dikirim');
check(Feedback::contact($db, 8) === ['name' => 'Petugas Baca', 'email' => ''], 'email kosong tidak dikirim sebagai kontak');

// What is refused.
rejects(static fn () => Feedback::submit($db, ['kind' => 'keluhan', 'message' => 'Pesan yang cukup panjang.'], 7, '2026-10-06 09:00:00'), 'jenis lain ditolak', 'Pilih jenis masukan');
rejects(static fn () => Feedback::submit($db, ['kind' => 'bug', 'message' => 'Rusak'], 7, '2026-10-06 09:00:00'), 'pesan terlalu pendek ditolak', 'paling sedikit 10 karakter');
check((int) $db->query('SELECT COUNT(*) FROM inventory_feedback')->fetchColumn() === 2, 'masukan yang ditolak tidak tersimpan');

// Klaras cannot be reached: kept, and sent later.
$panel = 'down';
$waiting = Feedback::submit($db, ['kind' => 'question', 'message' => 'Bagaimana mencetak label untuk satu ruangan?'], 8, '2026-10-06 10:00:00');
check($waiting['status']['key'] === 'pending' && $waiting['status']['label'] === 'Menunggu terkirim', 'saat Klaras tak terjangkau, masukan tetap tersimpan dan menunggu');
check(Feedback::due($db), 'ada pekerjaan setelah halaman: masukan yang menunggu');
check(Feedback::flush($db) === 0, 'mengirim ulang saat Klaras masih tak terjangkau tidak menghilangkan apa pun');
$panel = 'up';
check(Feedback::flush($db) === 1 && (int) $db->query('SELECT COUNT(*) FROM inventory_feedback WHERE panel_id IS NULL')->fetchColumn() === 0, 'begitu Klaras terjangkau, yang menunggu terkirim');

// Replies read back, kept once, and counted until read.
$count = count($sent);
check(Feedback::refresh($db) && count($sent) === $count + 1, 'status dan balasan diminta dari Klaras');
$asked = end($sent)[1];
check(count($asked['items']) === 3 && $asked['items'][0]['token'] !== '' && $asked['install_id'] === $install, 'tiap masukan diminta dengan tokennya sendiri');
check(!Feedback::refresh($db) && !Feedback::refresh($db, true), 'tidak ditanyakan lagi sebelum waktunya, juga saat riwayat dibuka berulang kali dalam satu menit');
// Each staff member sees only what they sent. The reply went to the newest piece, sent by staff 8.
check(array_column(Feedback::list($db, 7), 'id') === [2, 1] && array_column(Feedback::list($db, 8), 'id') === [3] && Feedback::list($db, 9) === [], 'tiap petugas hanya melihat masukannya sendiri');
$newest = Feedback::list($db, 8)[0];
check($newest['status']['key'] === 'planned' && $newest['issue_url'] === 'https://github.com/klarasid/inven/issues/57' && $newest['replies'][0]['message'] === 'Kami tindak lanjuti di issue #57.' && $newest['replies'][0]['unread'], 'masukan tampil dengan status, tautan issue, dan balasan yang belum dibaca');
check(Feedback::unread($db, 8) === 1 && Feedback::unread($db, 7) === 0, 'balasan baru dihitung hanya untuk pengirimnya');
$db->prepare('UPDATE setting SET setting_value = ? WHERE setting_name = ?')->execute([serialize('0'), Feedback::CHECKED]);
Feedback::refresh($db, true);
check((int) $db->query('SELECT COUNT(*) FROM inventory_feedback_replies')->fetchColumn() === 1, 'balasan yang sama tidak disimpan dua kali');
Feedback::markSeen($db, 7, '2026-10-08 00:00:00');
check(Feedback::unread($db, 8) === 1, 'petugas lain membuka riwayatnya tidak menandai balasan ini dibaca');
Feedback::markSeen($db, 8, '2026-10-08 00:00:00');
check(Feedback::unread($db, 8) === 0 && !Feedback::list($db, 8)[0]['replies'][0]['unread'], 'setelah pengirimnya membuka riwayat, balasan tidak lagi dihitung baru');

// Threads: the librarian answers in their own thread.
$piece = Feedback::list($db, 8)[0];
check(!$piece['can_reply'], 'sebelum migrasi 22, masukan belum bisa dibalas');
rejects(static function () use ($db, $piece): void { Feedback::answer($db, ['feedback_id' => $piece['id'], 'message' => 'Di Chrome.'], 8, '2026-10-07 10:00:00'); }, 'membalas sebelum migrasi 22 diminta menjalankan migrasinya', 'versi 22');
$db->exec('ALTER TABLE inventory_feedback ADD COLUMN awaiting_reply INTEGER NOT NULL DEFAULT 0');
$db->exec('ALTER TABLE inventory_feedback ADD COLUMN can_reply INTEGER NOT NULL DEFAULT 1');

$answers = [];
$closed = false;
$asking = [['id' => 4, 'kind' => 'manual', 'message' => 'Di halaman mana tombolnya tidak merespons?', 'created_at' => '2026-10-07T09:30:00+07:00']];
Feedback::$transport = static function (string $url, array $payload) use (&$answers, &$panel, &$closed, &$asking): ?array {
    if ($panel !== 'up') return null;
    if (str_ends_with($url, '/status')) {
        return ['data' => [['id' => $payload['items'][0]['id'], 'status' => 'reviewing', 'awaiting_reply' => true, 'can_reply' => true, 'issue_url' => null, 'replies' => $asking]]];
    }
    if (str_ends_with($url, '/replies')) {
        if ($closed) return ['error' => ['code' => 'feedback_closed', 'message' => 'Percakapan ini sudah ditutup.']];
        $answers[] = $payload;
        return ['data' => ['id' => 100 + count($answers), 'kind' => 'sender', 'status' => 'reviewing', 'created_at' => '2026-10-07T10:00:00+07:00']];
    }
    return null;
};
$db->prepare('UPDATE setting SET setting_value = ? WHERE setting_name = ?')->execute([serialize('0'), Feedback::CHECKED]);
Feedback::refresh($db);
$piece = Feedback::list($db, 8)[0];
check($piece['status']['key'] === 'awaiting' && $piece['status']['label'] === 'Perlu jawaban Anda' && $piece['can_reply'], 'saat Klaras meminta info tambahan, masukan menunggu jawaban pengirimnya');

rejects(static function () use ($db, $piece): void { Feedback::answer($db, ['feedback_id' => $piece['id'], 'message' => 'Di Chrome.'], 7, '2026-10-07 10:00:00'); }, 'petugas lain tidak bisa membalas masukan yang bukan miliknya', 'tidak ditemukan');
rejects(static function () use ($db, $piece): void { Feedback::answer($db, ['feedback_id' => $piece['id'], 'message' => ' '], 8, '2026-10-07 10:00:00'); }, 'balasan kosong ditolak', 'Tulis balasan');

$answered = Feedback::answer($db, ['feedback_id' => $piece['id'], 'message' => "Di halaman Ruang 🙂,\r\ntombol Cetak KIR."], 8, '2026-10-07 10:00:00');
$mine = end($answered['replies']);
check(count($answers) === 1, 'balasan langsung dikirim ke Klaras');
check($answers[0]['message'] === "Di halaman Ruang 🙂,\ntombol Cetak KIR." && strlen($answers[0]['token']) === 48 && $answers[0]['install_id'] === $install, 'balasan dikirim dengan token masukannya');
check($mine['from_sender'] && !$mine['pending'] && !$mine['unread'] && $answered['status']['key'] === 'reviewing', 'balasan sendiri tampil sebagai "Anda", tidak dihitung baru, dan masukan kembali ditinjau');
$db->exec('UPDATE inventory_feedback SET seen_at = NULL WHERE user_id = 8');
check(Feedback::unread($db, 8) === 2, 'hanya balasan Klaras yang dihitung belum dibaca, bukan balasan pengirimnya');

$db->prepare('UPDATE setting SET setting_value = ? WHERE setting_name = ?')->execute([serialize('0'), Feedback::CHECKED]);
$asking[] = ['id' => 101, 'kind' => 'sender', 'message' => "Di halaman Ruang 🙂,\ntombol Cetak KIR.", 'created_at' => '2026-10-07T10:00:00+07:00'];
Feedback::refresh($db);
check(count(Feedback::list($db, 8)[0]['replies']) === 3, 'balasan sendiri yang dikembalikan Klaras tidak tersimpan dua kali');

$panel = 'down';
$waiting = Feedback::answer($db, ['feedback_id' => $piece['id'], 'message' => 'Juga terjadi di Firefox.'], 8, '2026-10-07 11:00:00');
check(end($waiting['replies'])['pending'] && Feedback::due($db), 'saat Klaras tak terjangkau, balasan menunggu dan akan dikirim ulang');
$panel = 'up';
check(Feedback::flush($db) === 1 && !end(Feedback::list($db, 8)[0]['replies'])['pending'] && count($answers) === 2, 'begitu Klaras terjangkau, balasan yang menunggu terkirim');

$closed = true;
rejects(static function () use ($db, $piece): void { Feedback::answer($db, ['feedback_id' => $piece['id'], 'message' => 'Masih terjadi.'], 8, '2026-10-07 12:00:00'); }, 'percakapan yang sudah ditutup Klaras menolak balasan', 'sudah ditutup');
$piece = Feedback::list($db, 8)[0];
check(!$piece['can_reply'] && count($piece['replies']) === 4, 'balasan yang ditolak tidak tersimpan, dan masukan tidak bisa dibalas lagi');


// Problems in three parts, and screenshots.
$uploads = [];
$refuse = false;
Feedback::$transport = static function (string $url, array $payload) use (&$uploads, &$panel, &$refuse, &$sent): ?array {
    $sent[] = [$url, $payload];
    if ($panel !== 'up') return null;
    if (str_ends_with($url, '/attachments')) {
        if ($refuse) return ['error' => ['code' => 'too_many_attachments', 'message' => 'Paling banyak 3 tangkapan layar.']];
        $uploads[] = $payload + ['bytes' => file_get_contents($payload['file']['path'])];
        return ['data' => ['id' => 500 + count($uploads)]];
    }
    if (str_ends_with($url, '/status') || str_ends_with($url, '/replies')) return null;
    return ['data' => ['id' => sprintf('00000000-0000-4000-8000-%012d', count($sent)), 'status' => 'new']];
};
$png = static function (int $width = 8): string { $image = imagecreatetruecolor($width, 8); ob_start(); imagepng($image); return (string) ob_get_clean(); };
Feedback::$directory = sys_get_temp_dir() . '/inventory-feedback-test-' . bin2hex(random_bytes(4));

rejects(static function () use ($db): void { Feedback::submit($db, ['kind' => 'bug', 'did' => 'Buka Ruang', 'happened' => 'Error'], 7, '2026-10-07 13:00:00'); }, 'masalah tanpa cerita apa yang terjadi ditolak', 'Ceritakan apa yang terjadi');
$problem = Feedback::submit($db, ['kind' => 'bug', 'did' => "Saya membuka Ruang\r\nlalu menekan Cetak KIR.", 'happened' => 'Halaman kosong.', 'expected' => '', 'page' => 'rooms'], 7, '2026-10-07 13:00:00');
check($problem['message'] === "Yang saya lakukan:\nSaya membuka Ruang\nlalu menekan Cetak KIR.\n\nYang terjadi:\nHalaman kosong." && end($sent)[1]['message'] === $problem['message'], 'masalah ditulis dalam tiga bagian dan dikirim sebagai satu pesan seperti di Klaras Panel');

rejects(static function () use ($db, $png): void { Feedback::submit($db, ['kind' => 'question', 'message' => 'Kenapa tombol ini abu-abu?'], 7, '2026-10-07 13:00:00', [['bytes' => $png(), 'name' => 'layar.png']]); }, 'sebelum migrasi 23, tangkapan layar diminta menjalankan migrasinya', 'versi 23');
$db->exec("CREATE TABLE inventory_feedback_attachments (id INTEGER PRIMARY KEY, feedback_id INTEGER, filename TEXT, name TEXT, mime TEXT, size INTEGER, state TEXT NOT NULL DEFAULT 'pending', panel_attachment_id INTEGER, created_at TEXT)");
$count = (int) $db->query('SELECT COUNT(*) FROM inventory_feedback')->fetchColumn();
rejects(static function () use ($db): void { Feedback::submit($db, ['kind' => 'question', 'message' => 'Kenapa tombol ini abu-abu?'], 7, '2026-10-07 13:00:00', [['bytes' => '%PDF-1.4 bukan gambar', 'name' => 'catatan.pdf']]); }, 'berkas yang bukan gambar ditolak', 'PNG, JPG, atau WebP');
rejects(static function () use ($db, $png): void { Feedback::submit($db, ['kind' => 'question', 'message' => 'Kenapa tombol ini abu-abu?'], 7, '2026-10-07 13:00:00', array_fill(0, 4, ['bytes' => $png(), 'name' => 'layar.png'])); }, 'lebih dari tiga tangkapan layar ditolak', 'paling banyak 3');
$noise = static function (): string { $image = imagecreatetruecolor(600, 400); for ($y = 0; $y < 400; $y++) for ($x = 0; $x < 600; $x++) imagesetpixel($image, $x, $y, random_int(0, 0xFFFFFF)); ob_start(); imagepng($image); return (string) ob_get_clean(); };
rejects(static function () use ($db, $noise): void { Feedback::submit($db, ['kind' => 'question', 'message' => 'Kenapa tombol ini abu-abu?'], 7, '2026-10-07 13:00:00', [['bytes' => $noise(), 'name' => 'besar.png']]); }, 'tangkapan layar di atas 500 KB ditolak; browser mengecilkannya lebih dulu', '500 KB');
check((int) $db->query('SELECT COUNT(*) FROM inventory_feedback')->fetchColumn() === $count, 'masukan dengan lampiran yang ditolak tidak tersimpan');

$withScreenshot = Feedback::submit($db, ['kind' => 'question', 'message' => 'Kenapa tombol ini abu-abu?'], 7, '2026-10-07 13:00:00', [['bytes' => $png(), 'name' => '../layar.png']]);
check(count($uploads) === 1 && $uploads[0]['id'] === end($sent)[1]['id'] && strlen($uploads[0]['token']) === 48 && $uploads[0]['file']['mime'] === 'image/png' && $uploads[0]['bytes'] === $png(), 'tangkapan layar dikirim sesudah masukannya, dengan token masukan itu');
check($withScreenshot['screenshots'] === [['name' => 'layar.png', 'state' => 'sent']] && glob(Feedback::$directory . '/*.png') === [], 'setelah Klaras menerimanya, salinan di SLiMS dihapus; namanya tetap tampil di riwayat');
check(is_file(Feedback::$directory . '/.htaccess'), 'folder tangkapan layar tidak bisa dibuka dari browser');

$panel = 'down';
$waiting = Feedback::submit($db, ['kind' => 'idea', 'message' => 'Tambahkan pencarian di halaman Ruang.'], 7, '2026-10-07 14:00:00', [['bytes' => $png(9), 'name' => 'ruang.png']]);
check($waiting['screenshots'][0]['state'] === 'pending' && count(glob(Feedback::$directory . '/*.png')) === 1 && Feedback::due($db), 'saat Klaras tak terjangkau, tangkapan layar menunggu bersama masukannya');
$panel = 'up';
Feedback::flush($db);
check(count($uploads) === 2 && Feedback::list($db, 7)[0]['screenshots'][0]['state'] === 'sent' && glob(Feedback::$directory . '/*.png') === [], 'begitu Klaras terjangkau, masukan lalu tangkapan layarnya terkirim');

$refuse = true;
$refused = Feedback::submit($db, ['kind' => 'idea', 'message' => 'Satu lagi dengan gambar.'], 7, '2026-10-07 15:00:00', [['bytes' => $png(10), 'name' => 'lagi.png']]);
check($refused['screenshots'][0]['state'] === 'refused' && glob(Feedback::$directory . '/*.png') === [] && !Feedback::due($db), 'tangkapan layar yang ditolak Klaras tidak dikirim ulang dan salinannya dihapus');
@unlink(Feedback::$directory . '/.htaccess');
@rmdir(Feedback::$directory);

echo "ok   done\n";
