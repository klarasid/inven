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

function inventory_pdf_log(string $message, string $action): void
{
    try {
        writeLog('staff', (string) ($_SESSION['uid'] ?? '0'), 'Inventaris Barang', $message, 'stock_take', $action);
    } catch (Throwable $exception) {
        error_log('Inventory PDF audit log error: ' . $exception->getMessage());
    }
}

if (!utility::havePrivilege('stock_take', 'r')) {
    inventory_pdf_log('Upaya mencetak inventaris ditolak: hak baca tidak tersedia.', 'Denied');
    http_response_code(403);
    die('Anda tidak memiliki hak untuk mencetak inventaris.');
}

$locationId = filter_input(INPUT_GET, 'location_id', FILTER_VALIDATE_INT);
if (!$locationId || $locationId < 1) {
    inventory_pdf_log('Upaya mencetak inventaris ditolak: lokasi tidak valid.', 'Denied');
    http_response_code(400);
    die('Lokasi tidak valid.');
}

$now = time();
$pdfRequests = array_values(array_filter(
    (array) ($_SESSION['inventory_pdf_requests'] ?? []),
    static fn ($timestamp): bool => is_int($timestamp) && $timestamp > $now - 60
));
if (count($pdfRequests) >= 10) {
    inventory_pdf_log('Pencetakan inventaris dibatasi: lebih dari 10 permintaan per menit.', 'Rate limited');
    header('Retry-After: 60');
    http_response_code(429);
    die('Terlalu banyak permintaan PDF. Silakan coba lagi dalam satu menit.');
}
$pdfRequests[] = $now;
$_SESSION['inventory_pdf_requests'] = $pdfRequests;
session_write_close();

try {
    if (!class_exists(\Mpdf\Mpdf::class)) {
        throw new RuntimeException('Dependensi mPDF belum terpasang. Jalankan Composer dari direktori plugin.');
    }

    $db = \SLiMS\DB::getInstance();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $statement = $db->prepare(
        'SELECT l.*, ml.location_name AS slims_location_name
         FROM inventory_locations l
         LEFT JOIN mst_location ml ON ml.location_id = l.slims_location_id
         WHERE l.id = ?'
    );
    $statement->execute([$locationId]);
    $location = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$location) {
        inventory_pdf_log('Upaya mencetak lokasi inventaris #' . $locationId . ' gagal: lokasi tidak ditemukan.', 'Denied');
        http_response_code(404);
        die('Lokasi inventaris tidak ditemukan.');
    }

    $statement = $db->prepare('SELECT * FROM inventory_items WHERE location_id = ? ORDER BY item_name, item_code, id LIMIT 501');
    $statement->execute([$locationId]);
    $items = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (count($items) > 500) {
        inventory_pdf_log('Pencetakan lokasi inventaris #' . $locationId . ' ditolak: jumlah barang melebihi 500.', 'Limit exceeded');
        http_response_code(413);
        die('Lokasi memuat lebih dari 500 barang. Pecah data ke beberapa lokasi sebelum mencetak PDF.');
    }

    // Two KIR layouts: the classic form (default) and the modern one, chosen from the print menu.
    $modern = ($_GET['template'] ?? '') === 'modern';
    if ($modern) {
        require_once __DIR__ . '/src/PdfTemplateModern.php';
        $html = \SLiMS\Plugins\Inventory\PdfTemplateModern::render($location, $items);
    } else {
        require_once __DIR__ . '/src/PdfTemplate.php';
        $html = \SLiMS\Plugins\Inventory\PdfTemplate::render($location, $items);
    }

    $tempDir = SB . FLS . DS . 'cache';
    if (!is_dir($tempDir) || !is_writable($tempDir)) {
        http_response_code(500);
        die('Direktori cache PDF tidak dapat ditulis.');
    }

    if ($modern) {
        $pdf = \SLiMS\Plugins\Inventory\PdfLayout::mpdf(
            $tempDir,
            'Kartu Inventaris Ruangan - ' . $location['room_name'],
            \SLiMS\Plugins\Inventory\PdfTemplateModern::footer($location),
            ['format' => [330, 216], 'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 9, 'margin_bottom' => 13, 'margin_footer' => 6, 'exposeVersion' => false]
        );
    } else {
        $pdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => [330, 216],
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 4,
            'margin_bottom' => 7,
            'tempDir' => $tempDir,
            'exposeVersion' => false,
        ]);
        $pdf->SetTitle('Kartu Inventaris Ruangan - ' . $location['room_name']);
        $pdf->SetAuthor((string) ($sysconf['library_name'] ?? 'SLiMS'));
    }
    $pdf->WriteHTML($html);

    $safeRoom = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $location['room_name']);
    $safeRoom = trim((string) $safeRoom, '-') ?: 'lokasi';
    $filename = 'kartu-inventaris-' . strtolower($safeRoom) . '.pdf';
    $disposition = isset($_GET['download']) && $_GET['download'] === '1' ? 'attachment' : 'inline';
    $contents = $pdf->Output('', 'S');

    inventory_pdf_log('Kartu inventaris lokasi #' . $locationId . ' dicetak (' . count($items) . ' barang).', 'Print');
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($contents));
    echo $contents;
} catch (Throwable $exception) {
    error_log('Inventory PDF error: ' . $exception->getMessage());
    http_response_code(500);
    die('PDF tidak dapat dibuat. Periksa konfigurasi mPDF dan direktori cache SLiMS.');
}

exit;
