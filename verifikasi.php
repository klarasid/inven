<?php
/**
 * Public page opened from the QR code of a signed document (index.php?p=verifikasi_dokumen&c=<code>).
 * Tells whether the code is on record and shows who signed which document and when; it never shows
 * the document's content. Rendered inside the OPAC template.
 */
defined('INDEX_AUTH') || die('Direct access not allowed!');

require_once __DIR__ . '/src/PublicLink.php';
require_once __DIR__ . '/src/PdfLayout.php';
require_once __DIR__ . '/src/DocumentSignature.php';

use SLiMS\Plugins\Inventory\DocumentSignature;
use SLiMS\Plugins\Inventory\PdfLayout;

$verifyDb = \SLiMS\DB::getInstance();
$verifyDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
header('X-Robots-Tag: noindex, nofollow');
$e = static fn($value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$record = null;
try {
    $record = DocumentSignature::find($verifyDb, (string) ($_GET['c'] ?? ''));
} catch (Throwable $error) {
    error_log('Inventory document verification: ' . $error->getMessage());
}
?>
<style>
  .inv-ver{max-width:600px;margin:24px auto;padding:0 16px;color:#1f2937;font-size:15px;line-height:1.5}
  .inv-ver-card{border:1px solid #e5e7eb;border-radius:14px;background:#fff;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04)}
  .inv-ver-head{padding:20px}
  .inv-ver-eyebrow{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#6b7280}
  .inv-ver h1{font-size:22px;line-height:1.25;margin:6px 0 10px;font-weight:700;color:#111827}
  .inv-ver-badge{display:inline-block;padding:3px 12px;border-radius:999px;font-size:13px;font-weight:600}
  .inv-ver-badge.ok{background:#dcfce7;color:#166534}.inv-ver-badge.no{background:#fee2e2;color:#991b1b}
  .inv-ver dl{display:grid;grid-template-columns:1fr 1fr;gap:10px 16px;margin:0;padding:16px 20px;border-top:1px solid #f0f0f0}
  .inv-ver dt{font-size:12px;color:#6b7280}.inv-ver dd{margin:0;font-weight:500;word-break:break-word}
  .inv-ver-wide{grid-column:1/-1}.inv-ver-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}
  .inv-ver-muted{color:#6b7280;font-size:13px;margin:0}
</style>
<div class="inv-ver">
<?php if (!$record): /* Plain 200: the OPAC theme replaces 404 responses with its generic page. */ ?>
  <div class="inv-ver-card"><div class="inv-ver-head">
    <div class="inv-ver-eyebrow">Verifikasi dokumen</div>
    <h1>Dokumen tidak dikenali</h1>
    <span class="inv-ver-badge no">Tidak terdaftar</span>
    <p class="inv-ver-muted" style="margin-top:10px">Kode ini tidak tercatat di perpustakaan ini. Dokumen mungkin bukan asli, atau dicetak dari sistem lain. Silakan hubungi petugas perpustakaan.</p>
  </div></div>
<?php else: ?>
  <div class="inv-ver-card">
    <div class="inv-ver-head">
      <div class="inv-ver-eyebrow"><?= $e($sysconf['library_name'] ?? 'Perpustakaan') ?> · Verifikasi dokumen</div>
      <h1><?= $e($record['title']) ?></h1>
      <span class="inv-ver-badge ok">Tanda tangan elektronik tercatat</span>
    </div>
    <dl>
      <div><dt>Jenis dokumen</dt><dd><?= $e(DocumentSignature::TYPES[$record['doc_type']] ?? $record['doc_type']) ?></dd></div>
      <div><dt>Ditandatangani oleh</dt><dd><?= $e($record['signer_name']) ?></dd></div>
      <div><dt>Tanggal</dt><dd><?= $e(PdfLayout::date(substr((string) $record['signed_at'], 0, 10))) ?>, <?= $e(substr((string) $record['signed_at'], 11, 5)) ?></dd></div>
      <?php if (trim((string) $record['scope']) !== ''): ?><div><dt>Cakupan</dt><dd><?= $e($record['scope']) ?></dd></div><?php endif; ?>
      <div><dt>Jumlah isi</dt><dd><?= (int) $record['item_count'] ?></dd></div>
      <div><dt>Kode</dt><dd class="inv-ver-mono"><?= $e(strtoupper(implode('-', str_split((string) $record['code'], 4)))) ?></dd></div>
      <div class="inv-ver-wide"><dt>Sidik jari isi (SHA-256)</dt><dd class="inv-ver-mono"><?= $e($record['content_hash']) ?></dd></div>
    </dl>
    <dl><div class="inv-ver-wide"><p class="inv-ver-muted">Halaman ini menunjukkan bahwa dokumen dengan kode tersebut ditandatangani secara elektronik oleh petugas perpustakaan pada waktu di atas. Isi dokumen tidak ditampilkan di sini. Cocokkan nama dokumen, penandatangan, dan tanggal dengan dokumen yang Anda pegang.</p></div></dl>
  </div>
<?php endif; ?>
</div>
