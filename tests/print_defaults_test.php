<?php

declare(strict_types=1);
// The print style a PDF gets when nobody names one (PrintDefaults): what Pengaturan Cetak saves,
// what the app and AI apps may ask for instead, and what happens once a chosen letterhead is gone.
// Runs on SQLite; needs no MySQL and no mPDF.

define('SB', sys_get_temp_dir() . '/iv_print_' . bin2hex(random_bytes(6)) . '/');
require __DIR__ . '/../src/PrintDefaults.php';

use SLiMS\Plugins\Inventory\Letterheads;
use SLiMS\Plugins\Inventory\PrintDefaults;

function check(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'ok   ' . $label . PHP_EOL;
}
function rejects(callable $operation, string $needle, string $label): void
{
    try { $operation(); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), $needle), $label); return; }
    throw new RuntimeException('Tidak ditolak: ' . $label);
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE setting (setting_id INTEGER PRIMARY KEY, setting_name TEXT UNIQUE, setting_value TEXT)');
$kop = static function (array $templates) use ($db): void {
    $db->prepare('DELETE FROM setting WHERE setting_name = ?')->execute([Letterheads::SETTING]);
    $db->prepare('INSERT INTO setting (setting_name, setting_value) VALUES (?, ?)')->execute([Letterheads::SETTING, serialize($templates)]);
    foreach ($templates as $template) file_put_contents(Letterheads::path($template), '%PDF-1.4');
};
$rektorat = ['id' => 'aaaaaaaaaaaaaaaa', 'name' => 'Rektorat', 'body' => 'iso'];
$fakultas = ['id' => 'bbbbbbbbbbbbbbbb', 'name' => 'Fakultas', 'body' => 'latex'];
mkdir(Letterheads::directory(), 0700, true);

try {
    // Nothing saved yet.
    check(PrintDefaults::load($db) === ['style' => 'latex', 'kir' => 'classic'], 'tanpa pengaturan, laporan bergaya LaTeX dan KIR klasik');
    check(PrintDefaults::style($db) === 'latex' && !PrintDefaults::modern($db), 'dan itulah yang dicetak bila tidak ada gaya yang diminta');

    // What Pengaturan Cetak saves.
    check(PrintDefaults::save($db, ['style' => 'iso', 'kir' => 'modern']) === ['style' => 'iso', 'kir' => 'modern'] && PrintDefaults::load($db) === ['style' => 'iso', 'kir' => 'modern'], 'gaya bawaan tersimpan');
    PrintDefaults::save($db, ['style' => 'iso', 'kir' => 'modern']);
    check((int) $db->query("SELECT COUNT(*) FROM setting WHERE setting_name = 'inventory_pdf_defaults'")->fetchColumn() === 1, 'menyimpan lagi tidak menggandakan pengaturan');
    check(PrintDefaults::style($db) === 'iso' && PrintDefaults::modern($db), 'PDF tanpa gaya yang diminta mengikuti bawaan');
    rejects(static fn () => PrintDefaults::save($db, ['style' => 'word', 'kir' => 'modern']), 'Gaya laporan tidak dikenal', 'gaya di luar daftar ditolak');
    rejects(static fn () => PrintDefaults::save($db, ['style' => 'kop:' . $rektorat['id'], 'kir' => 'modern']), 'Gaya laporan tidak dikenal', 'kop yang tidak ada ditolak sebagai bawaan');
    rejects(static fn () => PrintDefaults::save($db, ['style' => 'iso', 'kir' => 'lanskap']), 'Tata letak KIR tidak dikenal', 'tata letak KIR di luar daftar ditolak');
    check(PrintDefaults::load($db) === ['style' => 'iso', 'kir' => 'modern'], 'yang ditolak tidak mengubah bawaan');

    // What the app or an AI app asks for wins over the default.
    check(PrintDefaults::style($db, 'latex') === 'latex' && !PrintDefaults::modern($db, 'classic'), 'gaya yang diminta mengalahkan bawaan');
    check(PrintDefaults::modern($db, 'lanskap'), 'tata letak KIR yang tidak dikenal mengikuti bawaan');
    rejects(static fn () => PrintDefaults::style($db, 'word'), 'Gaya cetak tidak dikenal', 'gaya yang diminta tapi tidak dikenal ditolak, bukan diganti diam-diam');
    rejects(static fn () => PrintDefaults::style($db, 'kop'), 'Belum ada template kop', 'kop diminta sebelum ada template: ditolak dengan petunjuk');

    // Letterheads.
    $kop([$rektorat['id'] => $rektorat, $fakultas['id'] => $fakultas]);
    $configured = static function (): string {
        $template = new ReflectionProperty(SLiMS\Plugins\Inventory\PdfLetterhead::class, 'template');
        $template->setAccessible(true);
        return (string) ($template->getValue()['name'] ?? '');
    };
    check(PrintDefaults::style($db, 'kop:' . $rektorat['id']) === 'kop' && $configured() === 'Rektorat', 'kop yang diminta dengan id dipakai');
    check(PrintDefaults::style($db, 'kop') === 'kop' && $configured() === 'Fakultas', 'kop tanpa id, bawaan bukan kop: kop pertama menurut nama');
    PrintDefaults::save($db, ['style' => 'kop:' . $rektorat['id'], 'kir' => 'classic']);
    check(PrintDefaults::style($db) === 'kop' && $configured() === 'Rektorat', 'kop sebagai bawaan dipakai bila tidak ada gaya yang diminta');
    check(PrintDefaults::style($db, 'kop') === 'kop' && $configured() === 'Rektorat', 'kop tanpa id memakai kop bawaan');
    rejects(static fn () => PrintDefaults::style($db, 'kop:cccccccccccccccc'), 'tidak ditemukan', 'kop yang diminta tapi tidak ada ditolak');
    rejects(static fn () => PrintDefaults::style($db, 'kop:../../etc/passwd'), 'Gaya cetak tidak dikenal', 'id kop yang bukan id ditolak');

    // The default's letterhead is deleted afterwards.
    $kop([$fakultas['id'] => $fakultas]);
    check(PrintDefaults::style($db) === 'latex', 'kop bawaan yang sudah dihapus: kembali ke LaTeX, PDF tetap tercetak');
    check(PrintDefaults::style($db, 'kop') === 'kop' && $configured() === 'Fakultas', 'dan kop tanpa id memakai kop yang masih ada');
} finally {
    foreach (glob(Letterheads::directory() . '/*.pdf') ?: [] as $file) unlink($file);
    rmdir(Letterheads::directory()); rmdir(dirname(Letterheads::directory())); rmdir(dirname(Letterheads::directory(), 2)); rmdir(rtrim(SB, '/'));
}
echo "ok   done\n";
