<?php

defined('INDEX_AUTH') || die('Direct access not allowed!');
$pluginAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($pluginAutoload)) {
    require_once $pluginAutoload;
}
require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-stocktake');
require SB . 'admin/default/session.inc.php';
require SB . 'admin/default/session_check.inc.php';

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

function inventory_label_log(string $message, string $action): void
{
    try {
        writeLog('staff', (string) ($_SESSION['uid'] ?? '0'), 'Klaras Inven', $message, 'stock_take', $action);
    } catch (Throwable $exception) {
        error_log('Inventory label audit log error: ' . $exception->getMessage());
    }
}

if (!utility::havePrivilege('stock_take', 'r')) {
    inventory_label_log('Upaya mencetak label ditolak: hak baca tidak tersedia.', 'Denied');
    http_response_code(403);
    die('Anda tidak memiliki hak untuk mencetak label inventaris.');
}

$locationId = filter_input(INPUT_GET, 'location_id', FILTER_VALIDATE_INT);
if (!$locationId || $locationId < 1) {
    http_response_code(400);
    die('Lokasi tidak valid.');
}
// Optional subset of items, e.g. ids=12,15,19; empty prints every item in the room.
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))), fn($id) => $id > 0)));
if (count($ids) > 500) {
    http_response_code(413);
    die('Maksimal 500 label per cetak.');
}

require_once __DIR__ . '/src/PublicLink.php';
try {
    $host = \SLiMS\Plugins\Inventory\PublicLink::host();
} catch (RuntimeException $exception) {
    http_response_code(400);
    die($exception->getMessage());
}

$now = time();
$requests = array_values(array_filter(
    (array) ($_SESSION['inventory_pdf_requests'] ?? []),
    static fn ($timestamp): bool => is_int($timestamp) && $timestamp > $now - 60
));
if (count($requests) >= 10) {
    header('Retry-After: 60');
    http_response_code(429);
    die('Terlalu banyak permintaan PDF. Silakan coba lagi dalam satu menit.');
}
$requests[] = $now;
$_SESSION['inventory_pdf_requests'] = $requests;
session_write_close();

try {
    if (!class_exists(\Mpdf\Mpdf::class) || !class_exists(\Mpdf\QrCode\QrCode::class)) {
        throw new RuntimeException('Dependensi mPDF/QR belum terpasang. Jalankan Composer dari direktori plugin.');
    }
    require_once __DIR__ . '/src/LabelSheet.php';
    $preset = isset(\SLiMS\Plugins\Inventory\LabelSheet::PRESETS[$_GET['preset'] ?? '']) ? (string) $_GET['preset'] : 'a4-3x8';
    $start = max(1, (int) ($_GET['start'] ?? 1));

    $db = \SLiMS\DB::getInstance();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $room = $db->prepare('SELECT l.room_name, ml.location_name FROM inventory_locations l LEFT JOIN mst_location ml ON ml.location_id = l.slims_location_id WHERE l.id = ?');
    $room->execute([$locationId]);
    $room = $room->fetch(PDO::FETCH_ASSOC);
    if (!$room) {
        http_response_code(404);
        die('Lokasi inventaris tidak ditemukan.');
    }
    $sql = 'SELECT id, item_name, item_code FROM inventory_items WHERE location_id = ?';
    $args = [$locationId];
    if ($ids) {
        $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $args = array_merge($args, $ids);
    }
    $items = $db->prepare($sql . ' ORDER BY item_name, item_code, id LIMIT 501');
    $items->execute($args);
    $items = $items->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        http_response_code(404);
        die('Tidak ada barang untuk dicetak labelnya.');
    }
    if (count($items) > 500) {
        http_response_code(413);
        die('Ruangan memuat lebih dari 500 barang. Pilih barang yang akan dicetak.');
    }

    // QR target: the signed public OPAC page (default), or the staff page that requires login,
    // where index.php resolves ?qr=<item id> to the item's room and details.
    $public = ($_GET['target'] ?? 'public') !== 'staff';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $origin = ($https ? 'https' : 'http') . '://' . $host;
    $pluginId = md5((string) realpath(__DIR__ . '/index.php'));
    $labels = array_map(static fn(array $item): array => [
        'item' => $item,
        'room' => (string) $room['room_name'],
        'library' => (string) ($room['location_name'] ?? ''),
        'url' => $public
            ? $origin . SWB . 'index.php?' . \SLiMS\Plugins\Inventory\PublicLink::query($db, (int) $item['id'])
            : $origin . AWB . 'plugin_container.php?' . http_build_query(['mod' => 'stock_take', 'id' => $pluginId, 'qr' => (int) $item['id']]),
    ], $items);

    $pdf = \SLiMS\Plugins\Inventory\LabelSheet::mpdf(SB . FLS . DS . 'cache', $preset, 'Label inventaris - ' . $room['room_name']);
    $pdf->WriteHTML(\SLiMS\Plugins\Inventory\LabelSheet::render($labels, $preset, $start));
    $contents = $pdf->Output('', 'S');

    inventory_label_log('Label inventaris lokasi #' . $locationId . ' dicetak (' . count($items) . ' label, ' . $preset . ', QR ' . ($public ? 'publik' : 'petugas') . ').', 'Print');
    require_once __DIR__ . '/src/UpdateCheck.php';
    require_once __DIR__ . '/src/Telemetry.php';
    \SLiMS\Plugins\Inventory\Telemetry::count('labels_pdf');
    $safeRoom = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $room['room_name']), '-') ?: 'lokasi';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="label-' . strtolower($safeRoom) . '.pdf"');
    header('Content-Length: ' . strlen($contents));
    echo $contents;
} catch (Throwable $exception) {
    error_log('Inventory label error: ' . $exception->getMessage());
    require_once __DIR__ . '/src/UpdateCheck.php';
    require_once __DIR__ . '/src/Telemetry.php';
    \SLiMS\Plugins\Inventory\Telemetry::error('pdf', $exception);
    http_response_code(500);
    die('Label tidak dapat dibuat. Periksa konfigurasi mPDF dan direktori cache SLiMS.');
}

exit;
