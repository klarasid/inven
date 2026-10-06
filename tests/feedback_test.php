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
echo "ok   done\n";
