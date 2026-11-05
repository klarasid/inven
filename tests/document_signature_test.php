<?php

declare(strict_types=1);

// Electronic signature of printed documents: one record per content and signer, found again by the
// code in the QR, and a block with the QR at the end of the PDF. Runs on an in-memory SQLite database.
require __DIR__ . '/../src/DocumentSignature.php';

use SLiMS\Plugins\Inventory\DocumentSignature;

function check(bool $ok, string $label): void
{
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) exit(1);
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE setting (setting_name TEXT PRIMARY KEY, setting_value TEXT)');
$db->prepare('INSERT INTO setting VALUES (?, ?)')->execute(['inventory_label_secret', serialize(str_repeat('ab', 32))]);
$db->exec('CREATE TABLE inventory_signed_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, doc_type TEXT, title TEXT, scope TEXT, signer_name TEXT, signer_id INTEGER, content_hash TEXT, item_count INTEGER, signed_at TEXT)');

$first = DocumentSignature::sign($db, 'kir', 'Kartu Inventaris Ruangan - Ruang Baca', 'Perpustakaan Pusat', 'Rina', 7, '<p>isi</p>', 12);
check(DocumentSignature::valid($first['code']) && $first['content_hash'] === hash('sha256', '<p>isi</p>') && $first['item_count'] === 12, 'tanda tangan mencatat kode, sidik jari isi, dan jumlah');

$again = DocumentSignature::sign($db, 'kir', 'Kartu Inventaris Ruangan - Ruang Baca', 'Perpustakaan Pusat', 'Rina', 7, '<p>isi</p>', 12);
check($again['code'] === $first['code'] && $again['id'] === $first['id'] && (int) $db->query('SELECT COUNT(*) FROM inventory_signed_documents')->fetchColumn() === 1, 'isi dan penandatangan yang sama memakai kode yang sama');

$other = DocumentSignature::sign($db, 'kir', 'Kartu Inventaris Ruangan - Ruang Baca', 'Perpustakaan Pusat', 'Budi', 8, '<p>isi</p>', 12);
$changed = DocumentSignature::sign($db, 'kir', 'Kartu Inventaris Ruangan - Ruang Baca', 'Perpustakaan Pusat', 'Rina', 7, '<p>isi baru</p>', 13);
check($other['code'] !== $first['code'] && $changed['code'] !== $first['code'], 'penandatangan atau isi yang berbeda menghasilkan kode lain');

check(DocumentSignature::find($db, $first['code'])['signer_name'] === 'Rina' && DocumentSignature::find($db, str_repeat('0', 16)) === null && DocumentSignature::find($db, 'bukan-kode') === null && !DocumentSignature::valid('ABCDEF0123456789'),
    'kode ditemukan lagi; kode asal atau salah format ditolak');

$url = DocumentSignature::url($first['code']);
check(str_contains($url, 'p=verifikasi_dokumen') && str_contains($url, 'c=' . $first['code']), 'QR membuka halaman verifikasi dengan kodenya');

$block = DocumentSignature::block(['signer_name' => 'Rina <b>', 'signed_at' => '2026-11-05 09:30:00', 'code' => $first['code'], 'content_hash' => $first['content_hash']], $url);
check(str_contains($block, 'type="QR"') && str_contains($block, 'Ditandatangani secara elektronik') && str_contains($block, 'Rina &lt;b&gt;') && str_contains($block, '09:30'), 'blok tanda tangan memuat QR, penandatangan, dan waktu (teks di-escape)');

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
    $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'tempDir' => sys_get_temp_dir() . '/mpdf-sig-test']);
    $pdf->WriteHTML('<p>Dokumen</p>');
    $record = DocumentSignature::stamp($pdf, $db, 'catalog', 'Daftar Inventaris Berfoto', '', 'Rina', 7, '<p>Dokumen</p>', 3);
    $bytes = $pdf->Output('', 'S');
    check($record !== null && str_starts_with($bytes, '%PDF') && $record['doc_type'] === 'catalog', 'PDF diberi tanda tangan elektronik dengan QR');
}
echo 'ok   done' . PHP_EOL;
