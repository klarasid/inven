<?php
/**
 * Stock Take → Rekap Sarpras: eleven aspects of the library's facilities computed from the
 * inventory, plus the figures that do not live in it (sivitas, building area, bandwidth with its
 * evidence) and the register of software the library runs.
 *
 * Loaded by admin/plugin_container.php, which checks nothing itself, so the session, IP and
 * privilege checks here are the page's only protection. The page is the React workspace (view
 * "sarpras"); it reads ?format=json, posts its changes back here as JSON, and serves the
 * evidence file (?evidence=1) and the PDF (?pdf=<style>).
 */
defined('INDEX_AUTH') || die('Direct access not allowed!');

require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-stocktake');
require SB . 'admin/default/session.inc.php';
require SB . 'admin/default/session_check.inc.php';
require_once __DIR__ . '/src/UpdateCheck.php';
require_once __DIR__ . '/src/Telemetry.php';
require_once __DIR__ . '/src/PhotoStorage.php';
require_once __DIR__ . '/src/ItemPhotos.php';
require_once __DIR__ . '/src/WatchRecurrence.php';
require_once __DIR__ . '/src/Supervision.php';
require_once __DIR__ . '/src/PdfLayout.php';
require_once __DIR__ . '/src/Sarpras.php';

use SLiMS\Plugins\Inventory\Sarpras;

if (!utility::havePrivilege('stock_take', 'r')) {
    die('<div class="errorBox">' . __('You are not authorized to view this section') . '</div>');
}

$canWrite = utility::havePrivilege('stock_take', 'w');
if (empty($_SESSION['inventory_sarpras_csrf'])) {
    $_SESSION['inventory_sarpras_csrf'] = bin2hex(random_bytes(24));
}
$db = \SLiMS\DB::getInstance();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$json = static function (array $body, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
$log = static function (string $message, string $action): void {
    writeLog('staff', (string) ($_SESSION['uid'] ?? 0), 'Klaras Inven', $message, 'stock_take', $action);
};
$schemaMessage = 'Struktur Rekap Sarpras belum tersedia. Jalankan migrasi plugin hingga versi 9 melalui System → Plugins.';
$isSchema = static fn(Throwable $e): bool => $e instanceof PDOException && in_array((int) ($e->errorInfo[1] ?? 0), [1054, 1146], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) filter_input(INPUT_POST, 'csrf', FILTER_UNSAFE_RAW);
    if (!$canWrite || !hash_equals($_SESSION['inventory_sarpras_csrf'], $token)) {
        $json(['ok' => false, 'message' => 'Anda perlu hak tulis Stock Take untuk mengubah data ini.'], 403);
        exit;
    }
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'settings') {
            Sarpras::saveSettings($db, $_POST);
            $log('Data pendukung Rekap Sarpras diperbarui.', 'Update');
            $json(['ok' => true, 'message' => 'Data pendukung tersimpan.']);
        } elseif ($action === 'evidence') {
            Sarpras::uploadEvidence($db, $_FILES['evidence'] ?? []);
            $log('Bukti pengukuran bandwidth diunggah.', 'Update');
            $json(['ok' => true, 'message' => 'Bukti pengukuran tersimpan.']);
        } elseif ($action === 'evidence_delete') {
            Sarpras::deleteEvidence($db);
            $log('Bukti pengukuran bandwidth dihapus.', 'Delete');
            $json(['ok' => true, 'message' => 'Bukti pengukuran dihapus.']);
        } elseif ($action === 'software') {
            $id = Sarpras::saveSoftware($db, $_POST, (int) ($_POST['record_id'] ?? 0), isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null);
            $log('Perangkat lunak #' . $id . ' disimpan.', 'Update');
            $json(['ok' => true, 'message' => 'Aplikasi tersimpan.', 'record' => $id]);
        } elseif ($action === 'software_delete') {
            $id = (int) ($_POST['record_id'] ?? 0);
            Sarpras::deleteSoftware($db, $id);
            $log('Perangkat lunak #' . $id . ' dihapus.', 'Delete');
            $json(['ok' => true, 'message' => 'Aplikasi dihapus.']);
        } else {
            $json(['ok' => false, 'message' => 'Aksi tidak dikenal.'], 400);
        }
    } catch (RuntimeException $error) {
        $json(['ok' => false, 'message' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        error_log('[sarpras] ' . $error->getMessage());
        $json(['ok' => false, 'message' => $isSchema($error) ? $schemaMessage : 'Data tidak tersimpan. Periksa log PHP.'], 500);
    }
    exit;
}

if (($_GET['evidence'] ?? '') === '1') {
    $file = Sarpras::evidenceFile($db);
    if (!$file) {
        http_response_code(404);
        echo 'Bukti tidak ditemukan.';
        exit;
    }
    header('Content-Type: ' . $file['mime']);
    header('Content-Length: ' . filesize($file['path']));
    header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $file['name']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($file['path']);
    exit;
}

$watch = static fn() => new \SLiMS\Plugins\Inventory\Supervision($db, new \SLiMS\Plugins\Inventory\PhotoStorage(SB . 'images/inventaris-barang/pengawasan'));

if (isset($_GET['pdf'])) {
    try {
        session_write_close();
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (is_file($autoload)) require_once $autoload;
        if (!class_exists(\Mpdf\Mpdf::class)) throw new RuntimeException('Dependensi mPDF belum tersedia. Jalankan composer install di direktori plugin.');
        require_once __DIR__ . '/src/SarprasPdf.php';
        $style = (string) $_GET['pdf'];
        if (str_starts_with($style, 'kop:')) {
            require_once __DIR__ . '/src/Letterheads.php';
            \SLiMS\Plugins\Inventory\PdfLetterhead::configure(\SLiMS\Plugins\Inventory\Letterheads::find($db, substr($style, 4)));
            $style = 'kop';
        } elseif ($style === 'kop' || !isset(\SLiMS\Plugins\Inventory\WatchPdf::STYLES[$style])) {
            $style = 'latex';
        }
        $html = \SLiMS\Plugins\Inventory\SarprasPdf::render(Sarpras::recap($db, $watch()), [
            'printed_by' => (string) ($_SESSION['realname'] ?? ''),
            'documents' => \SLiMS\Plugins\Inventory\PdfDocuments::load($db),
        ], $style);
        $pdf = \SLiMS\Plugins\Inventory\WatchPdf::mpdf(SB . FLS . DS . 'cache', 'Rekap Sarana dan Prasarana', $style);
        $pdf->WriteHTML($html);
        $log('Rekap Sarpras dicetak.', 'Print');
        \SLiMS\Plugins\Inventory\Telemetry::count('sarpras_pdf');
        $pdf->Output('rekap-sarpras-' . date('Ymd') . '.pdf', 'I');
    } catch (Throwable $error) {
        if (!$error instanceof RuntimeException || $error instanceof PDOException) error_log('[sarpras] pdf: ' . $error->getMessage());
        header('Content-Type: text/html; charset=utf-8');
        echo '<div class="alert alert-danger">' . htmlspecialchars($isSchema($error) ? $schemaMessage : ($error instanceof RuntimeException && !$error instanceof PDOException ? $error->getMessage() : 'PDF tidak dapat dibuat. Periksa log PHP.'), ENT_QUOTES, 'UTF-8') . '</div>';
    }
    exit;
}

if (($_GET['format'] ?? '') === 'json') {
    try {
        $recap = Sarpras::recap($db, $watch());
        $settings = $recap['settings'];
        unset($recap['settings']);
        $evidence = is_array($settings['evidence']) ? ['name' => $settings['evidence']['name'], 'mime' => $settings['evidence']['mime'], 'uploaded_at' => $settings['evidence']['uploaded_at']] : null;
        $settings['evidence'] = $evidence;
        $json(['ok' => true, 'data' => [
            'recap' => $recap,
            'settings' => $settings,
            'software' => Sarpras::software($db),
            'licences' => Sarpras::LICENCES,
            'coverage' => Sarpras::COVERAGE,
            'levels' => Sarpras::LEVELS,
            'write' => $canWrite,
            'csrf' => $canWrite ? $_SESSION['inventory_sarpras_csrf'] : '',
        ]]);
    } catch (Throwable $error) {
        if (!$isSchema($error)) error_log('[sarpras] read: ' . $error->getMessage());
        $json(['ok' => false, 'message' => $isSchema($error) ? $schemaMessage : 'Rekap belum bisa dibaca. Periksa log PHP.'], 500);
    }
    exit;
}

require_once __DIR__ . '/src/Workspace.php';
\SLiMS\Plugins\Inventory\Workspace::shell('sarpras', $canWrite, [
    'page' => \SLiMS\Plugins\Inventory\Workspace::endpoint('sarpras.php'),
]);
