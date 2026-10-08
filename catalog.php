<?php

// Daftar inventaris berfoto as PDF, from the print dialog on Ruangan & Barang: the document the
// Klaras InvenSync API also shares (Documents::catalog), in the style picked from the print menu.
defined('INDEX_AUTH') || die('Direct access not allowed!');
require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-stocktake');
require SB . 'admin/default/session.inc.php';
require SB . 'admin/default/session_check.inc.php';

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

function inventory_catalog_log(string $message, string $action): void
{
    try {
        writeLog('staff', (string) ($_SESSION['uid'] ?? '0'), 'Klaras Inven', $message, 'stock_take', $action);
    } catch (Throwable $exception) {
        error_log('Inventory catalog audit log error: ' . $exception->getMessage());
    }
}

if (!utility::havePrivilege('stock_take', 'r')) {
    inventory_catalog_log('Upaya mencetak daftar inventaris berfoto ditolak: hak baca tidak tersedia.', 'Denied');
    http_response_code(403);
    die('Anda tidak memiliki hak untuk mencetak daftar inventaris.');
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
$printedBy = (string) ($_SESSION['realname'] ?? '');
session_write_close();

foreach (['PhotoStorage', 'UpdateCheck', 'Telemetry', 'PrintDefaults', 'Documents'] as $class) {
    require_once __DIR__ . '/src/' . $class . '.php';
}

try {
    $db = \SLiMS\DB::getInstance();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $categories = array_values(array_filter(array_map('trim', explode(',', (string) ($_GET['categories'] ?? '')))));
    $documents = new \SLiMS\Plugins\Inventory\Documents($db, SB . FLS . DS . 'cache');
    $document = $documents->catalog(
        new \SLiMS\Plugins\Inventory\PhotoStorage(),
        (string) ($_GET['group'] ?? 'category'),
        $categories,
        trim((string) ($_GET['library'] ?? '')),
        (string) ($_GET['photos'] ?? 'first'),
        (string) ($GLOBALS['sysconf']['library_name'] ?? 'Perpustakaan'),
        $printedBy,
        (string) ($_GET['style'] ?? '')
    );
    inventory_catalog_log('Daftar inventaris berfoto dicetak (' . $document['count'] . ' barang).', 'Print');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $document['filename'] . '"');
    header('Content-Length: ' . strlen($document['bytes']));
    echo $document['bytes'];
} catch (RuntimeException $exception) {
    if ($exception instanceof PDOException || $exception->getPrevious() instanceof PDOException) {
        error_log('Inventory catalog error: ' . $exception->getMessage());
        \SLiMS\Plugins\Inventory\Telemetry::error('pdf', $exception);
        http_response_code(500);
        die('Daftar inventaris berfoto tidak dapat dibuat. Coba lagi beberapa saat lagi.');
    }
    // What the document itself refuses: too many items or photos, an unknown filter or style.
    http_response_code(422);
    die(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'));
} catch (Throwable $exception) {
    error_log('Inventory catalog error: ' . $exception->getMessage());
    \SLiMS\Plugins\Inventory\Telemetry::error('pdf', $exception);
    http_response_code(500);
    die('Daftar inventaris berfoto tidak dapat dibuat. Periksa konfigurasi mPDF dan direktori cache SLiMS.');
}

exit;
