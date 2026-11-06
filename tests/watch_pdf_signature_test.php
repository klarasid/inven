<?php

declare(strict_types=1);

// Inspection documents and the supervision report carry no block for handwritten signatures: a
// finalised inspection is signed electronically by its examiner (the QR block), a draft is not.
require __DIR__ . '/../src/WatchPdf.php';
require __DIR__ . '/../src/Supervision.php';
require __DIR__ . '/../src/DocumentSignature.php';

use SLiMS\Plugins\Inventory\DocumentSignature;
use SLiMS\Plugins\Inventory\WatchPdf;

function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL ' . $label); echo "ok   $label\n"; }

$document = static fn (string $status, string $examiner, ?int $examinerId, string $template = 'Checklist ruang', ?int $templateId = 3): array => [
    'inspection' => ['id' => 14, 'status' => $status, 'examiner_name' => $examiner, 'examiner_id' => $examinerId, 'performed_date' => '2026-10-06', 'due_date' => '2026-10-05', 'kind' => 'routine', 'finalized_at' => '2026-10-06 09:50:00', 'parent_id' => null, 'reason' => '', 'notes' => ''],
    'snapshot' => ['room_name' => 'Ruang Koleksi', 'library_name' => 'Perpustakaan Pusat', 'template_name' => $template, 'template_id' => $templateId],
    'results' => [['id' => 1, 'outcome' => 'good', 'notes' => '', 'snapshot' => json_encode(['group' => 'Sarana', 'object' => 'Meja', 'item_id' => null])]],
    'findings' => [], 'actions' => [], 'photos' => [],
    'events' => [['event' => 'finalize', 'actor_name' => $examiner, 'notes' => '', 'created_at' => '2026-10-06 09:50:00']],
];

$final = WatchPdf::signer($document('final', 'Rina', 7));
check($final !== null && $final['type'] === 'inspection' && $final['name'] === 'Rina' && $final['id'] === 7 && $final['count'] === 1
    && str_contains($final['title'], 'Dokumen Pemeriksaan Ruangan #14') && $final['scope'] === 'Perpustakaan Pusat', 'pemeriksaan final ditandatangani pemeriksanya');
check(WatchPdf::signer($document('draft', 'Rina', 7)) === null && WatchPdf::signer($document('final', '', null)) === null, 'draf, atau tanpa pemeriksa, tidak ditandatangani');
$report = WatchPdf::signer($document('final', 'Budi', null, 'Laporan kerusakan', null));
check($report !== null && str_contains($report['title'], 'Laporan Kerusakan dan Tindak Lanjut') && $report['id'] === null, 'laporan kerusakan ditandatangani pelapornya');
check(isset(DocumentSignature::TYPES['inspection'], DocumentSignature::TYPES['report'], DocumentSignature::TYPES['schedules']), 'halaman verifikasi mengenal jenis dokumen pemeriksaan, laporan, dan jadwal');

foreach (['latex', 'iso'] as $style) {
    $html = WatchPdf::detail($document('final', 'Rina', 7), static fn (): ?string => null, $style);
    check(str_contains($html, 'Ruang Koleksi') && !str_contains($html, 'Mengetahui') && !str_contains($html, 'Pemeriksa,') && !str_contains($html, 'Pengesahan'), "dokumen pemeriksaan ($style) tanpa blok tanda tangan basah");
}
echo "ok   done\n";
