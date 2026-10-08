<?php

// The lists and sheets as PDF, from their print buttons in SLiMS: daftar inventaris berfoto, daftar
// area dan fasilitas, daftar perangkat lunak, jadwal and checklist pemeriksaan. The same documents
// the Klaras InvenSync API shares (Documents), in the style picked from the print menu.
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

function inventory_document_log(string $message, string $action): void
{
    try {
        writeLog('staff', (string) ($_SESSION['uid'] ?? '0'), 'Klaras Inven', $message, 'stock_take', $action);
    } catch (Throwable $exception) {
        error_log('Inventory document audit log error: ' . $exception->getMessage());
    }
}

if (!utility::havePrivilege('stock_take', 'r')) {
    inventory_document_log('Upaya mencetak dokumen inventaris ditolak: hak baca tidak tersedia.', 'Denied');
    http_response_code(403);
    die('Anda tidak memiliki hak untuk mencetak dokumen inventaris.');
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

foreach (['PhotoStorage', 'Holidays', 'WatchRecurrence', 'Supervision', 'UpdateCheck', 'Telemetry', 'PrintDefaults', 'Documents', 'AreaPhotos'] as $class) {
    require_once __DIR__ . '/src/' . $class . '.php';
}

try {
    $db = \SLiMS\DB::getInstance();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $documents = new \SLiMS\Plugins\Inventory\Documents($db, SB . FLS . DS . 'cache');
    $watch = static fn (): \SLiMS\Plugins\Inventory\Supervision => new \SLiMS\Plugins\Inventory\Supervision($db, new \SLiMS\Plugins\Inventory\PhotoStorage(SB . 'images/inventaris-barang/pengawasan'));
    $libraryName = (string) ($GLOBALS['sysconf']['library_name'] ?? 'Perpustakaan');
    $library = trim((string) ($_GET['library'] ?? ''));
    $style = (string) ($_GET['style'] ?? '');
    switch ((string) ($_GET['action'] ?? '')) {
        case 'print_catalog':
            $categories = array_values(array_filter(array_map('trim', explode(',', (string) ($_GET['categories'] ?? '')))));
            $document = $documents->catalog(new \SLiMS\Plugins\Inventory\PhotoStorage(), (string) ($_GET['group'] ?? 'category'), $categories, $library, (string) ($_GET['photos'] ?? 'first'), $libraryName, $printedBy, $style);
            $printed = 'Daftar inventaris berfoto dicetak (' . $document['count'] . ' barang).';
            break;
        case 'print_areas':
            $document = $documents->areas(new \SLiMS\Plugins\Inventory\PhotoStorage(), (string) ($_GET['group'] ?? ''), $library, $libraryName, $printedBy, $style);
            $printed = 'Daftar area dan fasilitas dicetak (' . $document['count'] . ' area).';
            break;
        case 'print_software':
            $document = $documents->software($printedBy, $style);
            $printed = 'Daftar perangkat lunak dicetak (' . $document['count'] . ' aplikasi).';
            break;
        case 'print_schedules':
            $document = $documents->schedules($watch(), $printedBy, $style);
            $printed = 'Jadwal pemeriksaan dicetak (' . $document['count'] . ' jadwal).';
            break;
        case 'print_checklists':
            $document = $documents->checklists($watch(), max(0, (int) ($_GET['record'] ?? 0)), $printedBy, $style);
            $printed = 'Checklist pemeriksaan dicetak (' . $document['count'] . ' checklist).';
            break;
        default:
            throw new RuntimeException('Dokumen tidak dikenal.');
    }
    inventory_document_log($printed, 'Print');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $document['filename'] . '"');
    header('Content-Length: ' . strlen($document['bytes']));
    echo $document['bytes'];
} catch (RuntimeException $exception) {
    if ($exception instanceof PDOException || $exception->getPrevious() instanceof PDOException) {
        error_log('Inventory document error: ' . $exception->getMessage());
        \SLiMS\Plugins\Inventory\Telemetry::error('pdf', $exception);
        http_response_code(500);
        die('Dokumen tidak dapat dibuat. Coba lagi beberapa saat lagi.');
    }
    // What the document itself refuses: too many items or photos, an unknown filter or style.
    http_response_code(422);
    die(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'));
} catch (Throwable $exception) {
    error_log('Inventory document error: ' . $exception->getMessage());
    \SLiMS\Plugins\Inventory\Telemetry::error('pdf', $exception);
    http_response_code(500);
    die('Dokumen tidak dapat dibuat. Periksa konfigurasi mPDF dan direktori cache SLiMS.');
}

exit;
