<?php
/**
 * Public item page opened from a label's QR code (index.php?p=info_barang&i=<id>&t=<token>).
 * Rendered inside the OPAC template; shows what a visitor needs to identify the item and its upkeep,
 * without prices or staff names. Only signed links from printed labels open.
 */
defined('INDEX_AUTH') || die('Direct access not allowed!');

require_once __DIR__ . '/src/PublicLink.php';
require_once __DIR__ . '/src/PhotoStorage.php';

use SLiMS\Plugins\Inventory\PublicLink;

$inventoryDb = \SLiMS\DB::getInstance();
$inventoryDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$inventoryId = (int) ($_GET['i'] ?? 0);
$inventoryValid = PublicLink::verify($inventoryDb, $inventoryId, (string) ($_GET['t'] ?? ''));
$e = static fn($value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
header('X-Robots-Tag: noindex, nofollow');

// Photo requests leave the OPAC template: drop its buffered output and send the image alone.
if ($inventoryValid && isset($_GET['photo'])) {
    $statement = $inventoryDb->prepare('SELECT filename FROM inventory_item_photos WHERE id = ? AND item_id = ? AND filename IS NOT NULL');
    $statement->execute([(int) $_GET['photo'], $inventoryId]);
    $filename = $statement->fetchColumn();
    $bytes = $filename ? (new \SLiMS\Plugins\Inventory\PhotoStorage())->read((string) $filename) : null;
    while (ob_get_level() > 0) ob_end_clean();
    header('X-Content-Type-Options: nosniff');
    if ($bytes === null) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

$item = null;
if ($inventoryValid) {
    $statement = $inventoryDb->prepare(
        'SELECT i.*, l.room_name, ml.location_name FROM inventory_items i
         JOIN inventory_locations l ON l.id = i.location_id
         LEFT JOIN mst_location ml ON ml.location_id = l.slims_location_id
         WHERE i.id = ?'
    );
    $statement->execute([$inventoryId]);
    $item = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

$months = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$date = static function ($value) use ($months): string {
    if (!$value) return '—';
    $d = new DateTimeImmutable((string) $value);
    return $d->format('j') . ' ' . $months[(int) $d->format('n')] . ' ' . $d->format('Y');
};
?>
<style>
  .inv-pub{max-width:640px;margin:24px auto;padding:0 16px;color:#1f2937;font-size:15px;line-height:1.5}
  .inv-pub-card{border:1px solid #e5e7eb;border-radius:14px;background:#fff;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04)}
  .inv-pub-head{padding:20px 20px 16px}
  .inv-pub-eyebrow{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#6b7280}
  .inv-pub h1{font-size:24px;line-height:1.25;margin:6px 0 10px;font-weight:700;color:#111827}
  .inv-pub-tags{display:flex;flex-wrap:wrap;gap:6px}
  .inv-pub-tag{display:inline-block;padding:2px 10px;border-radius:999px;font-size:13px;font-weight:600;background:#f3f4f6;color:#374151}
  .inv-pub-tag.code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
  .inv-pub-tag.B{background:#dcfce7;color:#166534}.inv-pub-tag.KB{background:#fef3c7;color:#92400e}.inv-pub-tag.RB{background:#fee2e2;color:#991b1b}
  .inv-pub-tag.work{background:#e0e7ff;color:#3730a3}
  .inv-pub-photos{display:flex;gap:6px;overflow-x:auto;padding:0 20px 16px}
  .inv-pub-photos img{height:180px;border-radius:10px;border:1px solid #e5e7eb;object-fit:cover}
  .inv-pub-section{border-top:1px solid #f0f0f0;padding:16px 20px}
  .inv-pub-section h2{font-size:13px;letter-spacing:.05em;text-transform:uppercase;color:#6b7280;margin:0 0 10px;font-weight:600}
  .inv-pub dl{display:grid;grid-template-columns:1fr 1fr;gap:10px 16px;margin:0}
  .inv-pub dt{font-size:12px;color:#6b7280}.inv-pub dd{margin:0;font-weight:500}
  .inv-pub ol{list-style:none;margin:0;padding:0}
  .inv-pub li{padding:8px 0;border-bottom:1px dashed #eee}.inv-pub li:last-child{border-bottom:0}
  .inv-pub-muted{color:#6b7280;font-size:13px}
  .inv-pub-foot{text-align:center;margin-top:14px;font-size:13px}
  .inv-pub-foot a{color:#1e3a5f;font-weight:600}
</style>
<div class="inv-pub">
<?php if (!$item): /* Plain 200: the OPAC theme replaces 404 responses with its generic page, hiding this guidance. */ ?>
  <div class="inv-pub-card"><div class="inv-pub-head">
    <div class="inv-pub-eyebrow">Inventaris perpustakaan</div>
    <h1>Label tidak dikenali</h1>
    <p class="inv-pub-muted">Tautan dari label ini tidak valid atau barangnya sudah tidak tercatat dalam inventaris. Silakan hubungi petugas perpustakaan.</p>
  </div></div>
<?php else:
    $conditions = ['B' => 'Kondisi baik', 'KB' => 'Kurang baik', 'RB' => 'Rusak berat'];
    $photos = $inventoryDb->prepare('SELECT id FROM inventory_item_photos WHERE item_id = ? AND filename IS NOT NULL ORDER BY id LIMIT 5');
    $photos->execute([$inventoryId]);
    $photos = $photos->fetchAll(PDO::FETCH_COLUMN);

    // Upkeep history: results whose checklist item is this asset (snapshot stores "item_id":<int>,).
    $lastCheck = null; $openWork = 0; $work = [];
    try {
        $like = '%"item_id":' . $inventoryId . ',%';
        $q = $inventoryDb->prepare("SELECT i.performed_date, r.outcome FROM inventory_watch_results r JOIN inventory_watch_inspections i ON i.id = r.inspection_id WHERE r.snapshot LIKE ? AND i.status = 'final' AND r.outcome IN ('good','action') ORDER BY i.performed_date DESC, i.id DESC LIMIT 1");
        $q->execute([$like]);
        $lastCheck = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        $q = $inventoryDb->prepare("SELECT COUNT(*) FROM inventory_watch_findings f JOIN inventory_watch_results r ON r.id = f.result_id WHERE r.snapshot LIKE ? AND f.status <> 'closed'");
        $q->execute([$like]);
        $openWork = (int) $q->fetchColumn();
        $q = $inventoryDb->prepare("SELECT a.kind, a.performed_date, a.description FROM inventory_watch_actions a JOIN inventory_watch_findings f ON f.id = a.finding_id JOIN inventory_watch_results r ON r.id = f.result_id WHERE r.snapshot LIKE ? AND a.submitted_at IS NOT NULL AND a.kind <> 'none' ORDER BY a.performed_date DESC, a.id DESC LIMIT 5");
        $q->execute([$like]);
        $work = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ignored) {
        // Supervision tables are optional (migration 7); the item card still renders.
    }
    $kinds = ['repair' => 'Perbaikan', 'maintenance' => 'Pemeliharaan'];
    $facts = array_filter([
        'Ruangan' => $item['room_name'],
        'Lokasi' => $item['location_name'],
        'Merk / model' => $item['brand_model'],
        'Tahun perolehan' => $item['acquisition_year'],
        'Jumlah / register' => $item['quantity_register'],
        'Bahan' => $item['material'],
    ], static fn($v) => trim((string) $v) !== '');
    $staff = SWB . 'admin/plugin_container.php?' . http_build_query(['mod' => 'stock_take', 'id' => md5((string) realpath(__DIR__ . '/index.php')), 'qr' => $inventoryId]);
?>
  <div class="inv-pub-card">
    <div class="inv-pub-head">
      <div class="inv-pub-eyebrow"><?= $e($sysconf['library_name'] ?? 'Perpustakaan') ?> · Inventaris</div>
      <h1><?= $e($item['item_name']) ?></h1>
      <div class="inv-pub-tags">
        <span class="inv-pub-tag code"><?= $e(trim((string) $item['item_code']) !== '' ? $item['item_code'] : 'ID ' . $inventoryId) ?></span>
        <?php if (isset($conditions[$item['item_condition']])): ?><span class="inv-pub-tag <?= $e($item['item_condition']) ?>"><?= $e($conditions[$item['item_condition']]) ?></span><?php endif; ?>
        <?php if ($openWork): ?><span class="inv-pub-tag work">Sedang ditangani petugas</span><?php endif; ?>
      </div>
    </div>
    <?php if ($photos): ?>
    <div class="inv-pub-photos">
      <?php foreach ($photos as $photoId): ?>
        <img loading="lazy" alt="Foto <?= $e($item['item_name']) ?>" src="<?= $e(SWB . 'index.php?' . PublicLink::query($inventoryDb, $inventoryId, ['photo' => (int) $photoId])) ?>">
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="inv-pub-section">
      <h2>Identitas barang</h2>
      <dl><?php foreach ($facts as $label => $value): ?><div><dt><?= $e($label) ?></dt><dd><?= $e($value) ?></dd></div><?php endforeach; ?></dl>
    </div>
    <div class="inv-pub-section">
      <h2>Pemeliharaan</h2>
      <p style="margin:0 0 8px">
        <?= $lastCheck ? 'Terakhir diperiksa <b>' . $e($date($lastCheck['performed_date'])) . '</b>.' : 'Belum ada catatan pemeriksaan khusus untuk barang ini.' ?>
        <?= $openWork ? ' Ada ' . $openWork . ' tindak lanjut yang sedang dikerjakan.' : '' ?>
      </p>
      <?php if ($work): ?>
        <ol><?php foreach ($work as $row): ?>
          <li><b><?= $e($kinds[$row['kind']] ?? 'Tindakan') ?></b> · <?= $e($date($row['performed_date'])) ?><div class="inv-pub-muted"><?= nl2br($e($row['description'])) ?></div></li>
        <?php endforeach; ?></ol>
      <?php endif; ?>
    </div>
  </div>
  <p class="inv-pub-foot">Menemukan kerusakan? Sampaikan kepada petugas perpustakaan.<br><a href="<?= $e($staff) ?>">Petugas: kelola barang ini</a></p>
<?php endif; ?>
</div>
