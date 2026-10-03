<?php
/**
 * The three Stock Take pages around the library's facilities, each loaded through its own menu
 * file, which sets $inventorySarprasPage:
 *
 *   recap     Rekap Sarpras: eleven aspects computed from the inventory, the software register,
 *             the facility figures and supervision history. Read only; also serves the PDF
 *             (?pdf=<style>).
 *   software  Perangkat Lunak: the register of software the library runs.
 *   facility  Gedung & Jaringan: the figures that do not live in the inventory (building area,
 *             bandwidth) and the bandwidth evidence file (?evidence=1).
 *   sivitas   Sivitas per Lokasi: active members counted as sivitas, placed at locations by
 *             Institusi, member type or a default (Sivitas). Putting mistyped Institusi right
 *             changes SLiMS's member data, so it also needs Membership write access.
 *
 * Rekap Sarpras and Gedung & Jaringan work on one library location, named by ?library=<code>
 * (see Sarpras::locations). Where several locations hold rooms, the recap without one is the
 * institution's: the locations compared, and their recaps combined.
 *
 * Loaded by admin/plugin_container.php, which checks nothing itself, so the session, IP and
 * privilege checks here are the pages' only protection. Each page is a view of the React
 * workspace; it reads ?format=json and posts its changes back to its own menu file as JSON.
 */
defined('INDEX_AUTH') || die('Direct access not allowed!');

require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-stocktake');
require SB . 'admin/default/session.inc.php';
require SB . 'admin/default/session_check.inc.php';
require_once __DIR__ . '/UpdateCheck.php';
require_once __DIR__ . '/Telemetry.php';
require_once __DIR__ . '/PhotoStorage.php';
require_once __DIR__ . '/ItemPhotos.php';
require_once __DIR__ . '/WatchRecurrence.php';
require_once __DIR__ . '/Supervision.php';
require_once __DIR__ . '/PdfLayout.php';
require_once __DIR__ . '/Sarpras.php';
require_once __DIR__ . '/Sivitas.php';

use SLiMS\Plugins\Inventory\Sarpras;
use SLiMS\Plugins\Inventory\Sivitas;

if (!utility::havePrivilege('stock_take', 'r')) {
    die('<div class="errorBox">' . __('You are not authorized to view this section') . '</div>');
}

// What each page may change. The recap changes nothing.
$actions = [
    'recap' => [],
    'software' => ['software', 'software_delete'],
    'facility' => ['settings', 'evidence', 'evidence_delete'],
    'sivitas' => ['map', 'counting', 'merge', 'undo'],
];
$page = (string) ($inventorySarprasPage ?? 'recap');
if (!isset($actions[$page])) $page = 'recap';
$views = ['recap' => 'sarpras', 'software' => 'software', 'facility' => 'facility', 'sivitas' => 'sivitas'];

$canWrite = utility::havePrivilege('stock_take', 'w');
$canFixMembers = $canWrite && utility::havePrivilege('membership', 'w');
if (empty($_SESSION['inventory_sarpras_csrf'])) {
    $_SESSION['inventory_sarpras_csrf'] = bin2hex(random_bytes(24));
}
$db = \SLiMS\DB::getInstance();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$json = static function (array $body, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
$log = static function (string $message, string $action): void {
    writeLog('staff', (string) ($_SESSION['uid'] ?? 0), 'Klaras Inven', $message, 'stock_take', $action);
};
// The location this request is about. '' is the library as one unit, or the institution.
$places = null;
$library = '';
try {
    $places = Sarpras::locations($db);
    $codes = array_column($places['locations'], 'code');
    $library = trim((string) ($_POST['library'] ?? $_GET['library'] ?? ''));
    if ($library !== '' && !in_array($library, $codes, true)) {
        $json(['ok' => false, 'message' => 'Lokasi perpustakaan tidak dikenal.'], 422);
        exit;
    }
    // A lone location needs no choosing; with no rooms yet the recap has nothing to show for one.
    if ($library === '' && count($codes) === 1 && $places['rooms'] > 0) $library = $codes[0];
    // Facility figures always belong to one location where there are any.
    if ($library === '' && $codes && $page === 'facility') $library = $codes[0];
} catch (PDOException $error) {
    // Tables not migrated yet: the page's own queries report that below.
}
$several = $places !== null && $places['rooms'] > 0 && count($places['locations']) > 1;
$place = static function () use ($places, $library): ?array {
    foreach ($places['locations'] ?? [] as $location) if ($location['code'] === $library) return $location;
    return null;
};

$schemaMessage = 'Struktur data sarpras belum tersedia. Jalankan migrasi plugin hingga versi 14 melalui System → Plugins.';
$isSchema = static fn(Throwable $e): bool => $e instanceof PDOException && in_array((int) ($e->errorInfo[1] ?? 0), [1054, 1146], true);
// An unexpected error goes into the daily usage report, stripped of its data. A schema not migrated
// yet is not one: the report already carries the migration level.
$report = static function (Throwable $e, ?string $category = null) use ($isSchema): void {
    if (!$isSchema($e)) \SLiMS\Plugins\Inventory\Telemetry::error($category ?? ($e instanceof PDOException ? 'db' : 'workspace'), $e);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) filter_input(INPUT_POST, 'csrf', FILTER_UNSAFE_RAW);
    if (!$canWrite || !hash_equals($_SESSION['inventory_sarpras_csrf'], $token)) {
        $json(['ok' => false, 'message' => 'Anda perlu hak tulis Stock Take untuk mengubah data ini.'], 403);
        exit;
    }
    try {
        $action = (string) ($_POST['action'] ?? '');
        if (!in_array($action, $actions[$page], true)) {
            $json(['ok' => false, 'message' => 'Aksi tidak dikenal.'], 400);
        } elseif ($action === 'settings') {
            Sarpras::saveSettings($db, $_POST, $library);
            $log('Data gedung dan jaringan' . ($library !== '' ? ' lokasi ' . $library : '') . ' diperbarui.', 'Update');
            $json(['ok' => true, 'message' => 'Data gedung dan jaringan tersimpan.']);
        } elseif ($action === 'evidence') {
            Sarpras::uploadEvidence($db, $_FILES['evidence'] ?? [], $library);
            $log('Bukti pengukuran bandwidth' . ($library !== '' ? ' lokasi ' . $library : '') . ' diunggah.', 'Update');
            $json(['ok' => true, 'message' => 'Bukti pengukuran tersimpan.']);
        } elseif ($action === 'evidence_delete') {
            Sarpras::deleteEvidence($db, $library);
            $log('Bukti pengukuran bandwidth' . ($library !== '' ? ' lokasi ' . $library : '') . ' dihapus.', 'Delete');
            $json(['ok' => true, 'message' => 'Bukti pengukuran dihapus.']);
        } elseif ($page === 'sivitas' && $action === 'map') {
            $basis = (string) ($_POST['basis'] ?? '');
            $values = is_array($_POST['values'] ?? null) ? array_map('strval', $_POST['values']) : [(string) ($_POST['value'] ?? '')];
            $location = trim((string) ($_POST['location'] ?? ''));
            if (!$values || count($values) > 1000) throw new RuntimeException('Pilih 1–1000 nilai untuk dipetakan.');
            $db->beginTransaction();
            foreach ($values as $value) Sivitas::map($db, $basis, $value, $location, date('Y-m-d H:i:s'));
            $db->commit();
            $what = $basis === 'type' ? 'tipe anggota' : 'institusi';
            $log(count($values) . ' ' . $what . ($location === '' ? ' dilepas dari lokasinya.' : ' dipetakan ke lokasi ' . $location . '.'), 'Update');
            $json(['ok' => true, 'message' => $location === '' ? 'Pemetaan dihapus.' : count($values) . ' ' . $what . ' dipetakan.']);
        } elseif ($page === 'sivitas' && $action === 'counting') {
            Sivitas::saveSettings($db, ['default' => $_POST['default'] ?? '', 'excluded' => $_POST['excluded'] ?? []]);
            $log('Pengaturan hitungan sivitas diperbarui.', 'Update');
            $json(['ok' => true, 'message' => 'Pengaturan sivitas tersimpan.']);
        } elseif ($page === 'sivitas' && ($action === 'merge' || $action === 'undo')) {
            if (!$canFixMembers) {
                $json(['ok' => false, 'message' => 'Memperbaiki institusi mengubah data anggota SLiMS. Anda perlu hak tulis Keanggotaan.'], 403);
            } elseif ($action === 'merge') {
                $from = is_array($_POST['from'] ?? null) ? array_map('strval', $_POST['from']) : [];
                $fix = Sivitas::merge($db, $from, (string) ($_POST['to'] ?? ''), (int) ($_SESSION['uid'] ?? 0), date('Y-m-d H:i:s'));
                $log('Institusi ' . $fix['count'] . ' anggota diseragamkan menjadi "' . trim((string) $_POST['to']) . '".', 'Update');
                \SLiMS\Plugins\Inventory\Telemetry::count('institution_merge');
                $json(['ok' => true, 'message' => 'Institusi ' . $fix['count'] . ' anggota diperbaiki.']);
            } else {
                $restored = Sivitas::undo($db, (int) ($_POST['record_id'] ?? 0), date('Y-m-d H:i:s'));
                $log('Perbaikan institusi #' . (int) $_POST['record_id'] . ' diurungkan untuk ' . $restored . ' anggota.', 'Update');
                $json(['ok' => true, 'message' => 'Institusi ' . $restored . ' anggota dikembalikan.']);
            }
        } elseif ($action === 'software') {
            $id = Sarpras::saveSoftware($db, $_POST, (int) ($_POST['record_id'] ?? 0), isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null);
            $log('Perangkat lunak #' . $id . ' disimpan.', 'Update');
            $json(['ok' => true, 'message' => 'Aplikasi tersimpan.', 'record' => $id]);
        } else {
            $id = (int) ($_POST['record_id'] ?? 0);
            Sarpras::deleteSoftware($db, $id);
            $log('Perangkat lunak #' . $id . ' dihapus.', 'Delete');
            $json(['ok' => true, 'message' => 'Aplikasi dihapus.']);
        }
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        // A PDOException is a RuntimeException too: only the others carry a message meant for the form.
        if ($error instanceof RuntimeException && !$error instanceof PDOException) {
            $json(['ok' => false, 'message' => $error->getMessage()], 422);
        } else {
            error_log('[sarpras] ' . $error->getMessage());
            $report($error);
            $json(['ok' => false, 'message' => $isSchema($error) ? $schemaMessage : 'Data tidak tersimpan. Periksa log PHP.'], 500);
        }
    }
    exit;
}

if ($page === 'facility' && ($_GET['evidence'] ?? '') === '1') {
    $file = Sarpras::evidenceFile($db, $library);
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

if ($page === 'recap' && isset($_GET['pdf'])) {
    try {
        session_write_close();
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) require_once $autoload;
        if (!class_exists(\Mpdf\Mpdf::class)) throw new RuntimeException('Dependensi mPDF belum tersedia. Jalankan composer install di direktori plugin.');
        require_once __DIR__ . '/SarprasPdf.php';
        $style = (string) $_GET['pdf'];
        if (str_starts_with($style, 'kop:')) {
            require_once __DIR__ . '/Letterheads.php';
            \SLiMS\Plugins\Inventory\PdfLetterhead::configure(\SLiMS\Plugins\Inventory\Letterheads::find($db, substr($style, 4)));
            $style = 'kop';
        } elseif ($style === 'kop' || !isset(\SLiMS\Plugins\Inventory\WatchPdf::STYLES[$style])) {
            $style = 'latex';
        }
        $context = ['printed_by' => (string) ($_SESSION['realname'] ?? ''), 'documents' => \SLiMS\Plugins\Inventory\PdfDocuments::load($db)];
        if ($several && $library === '') {
            $overview = Sarpras::overview($db, $watch(), $places['locations']);
            $recap = $overview['recap'];
            $context['locations'] = $overview['locations'];
        } else {
            $recap = Sarpras::recap($db, $watch(), $library);
            $context['location'] = (string) ($place()['name'] ?? '');
        }
        $html = \SLiMS\Plugins\Inventory\SarprasPdf::render($recap, $context, $style);
        $pdf = \SLiMS\Plugins\Inventory\WatchPdf::mpdf(SB . FLS . DS . 'cache', 'Rekap Sarana dan Prasarana', $style);
        $pdf->WriteHTML($html);
        $log('Rekap Sarpras' . ($library !== '' ? ' lokasi ' . $library : '') . ' dicetak.', 'Print');
        \SLiMS\Plugins\Inventory\Telemetry::count('sarpras_pdf');
        $pdf->Output('rekap-sarpras-' . ($library !== '' ? $library . '-' : '') . date('Ymd') . '.pdf', 'I');
    } catch (Throwable $error) {
        if (!$error instanceof RuntimeException || $error instanceof PDOException) {
            error_log('[sarpras] pdf: ' . $error->getMessage());
            $report($error, 'pdf');
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<div class="alert alert-danger">' . htmlspecialchars($isSchema($error) ? $schemaMessage : ($error instanceof RuntimeException && !$error instanceof PDOException ? $error->getMessage() : 'PDF tidak dapat dibuat. Periksa log PHP.'), ENT_QUOTES, 'UTF-8') . '</div>';
    }
    exit;
}

if (($_GET['format'] ?? '') === 'json') {
    try {
        $access = ['write' => $canWrite, 'csrf' => $canWrite ? $_SESSION['inventory_sarpras_csrf'] : ''];
        if ($page === 'software') {
            $json(['ok' => true, 'data' => ['software' => Sarpras::software($db), 'licences' => Sarpras::LICENCES] + $access]);
        } elseif ($page === 'sivitas') {
            $settings = Sivitas::settings($db);
            $maps = Sivitas::maps($db);
            $counts = Sivitas::counts($db);
            $locations = Sivitas::locations($db);
            $json(['ok' => true, 'data' => [
                'counts' => ['total' => $counts['total'], 'unmapped' => $counts['unmapped'], 'single' => $counts['single'],
                    'locations' => array_map(static fn(array $l) => $l + ['count' => $counts['locations'][$l['code']] ?? 0], $locations)],
                'settings' => $settings,
                'types' => array_map(static fn(array $t) => $t + ['location' => $maps['type'][(string) $t['id']] ?? '', 'excluded' => in_array($t['id'], $settings['excluded'], true)], Sivitas::types($db)),
                'institutions' => $counts['single'] ? [] : Sivitas::institutions($db),
                'similar' => Sivitas::similar($db),
                'fixes' => Sivitas::fixes($db),
                'locations' => $locations,
                'fix' => $canFixMembers,
            ] + $access]);
        } elseif ($page === 'facility') {
            if ($places === null) $places = Sarpras::locations($db);
            $settings = Sarpras::settings($db, $library);
            $settings['evidence'] = is_array($settings['evidence']) ? ['name' => $settings['evidence']['name'], 'mime' => $settings['evidence']['mime'], 'uploaded_at' => $settings['evidence']['uploaded_at']] : null;
            // What these figures amount to in the recap, so the page can show the effect of saving them.
            $result = [];
            foreach (Sarpras::recap($db, $watch(), $library)['aspects'] as $aspect) {
                if (in_array('facility', $aspect['sources'], true)) $result[] = array_intersect_key($aspect, array_flip(['no', 'name', 'value', 'level', 'checks']));
            }
            $counts = Sivitas::counts($db);
            $sivitas = ['count' => Sivitas::forLocation($db, $library), 'single' => $counts['single'], 'unmapped' => $counts['unmapped']];
            $json(['ok' => true, 'data' => ['settings' => $settings, 'sivitas' => $sivitas, 'coverage' => Sarpras::COVERAGE, 'result' => $result, 'levels' => Sarpras::LEVELS, 'locations' => $places['locations'], 'location' => $place()] + $access]);
        } else {
            if ($places === null) $places = Sarpras::locations($db);
            $shared = ['levels' => Sarpras::LEVELS, 'locations' => $places['rooms'] > 0 ? $places['locations'] : [], 'unassigned' => $places['unassigned']];
            if ($places['rooms'] === 0) {
                $json(['ok' => true, 'data' => ['mode' => 'empty'] + $shared]);
            } elseif ($several && $library === '') {
                $overview = Sarpras::overview($db, $watch(), $places['locations']);
                $json(['ok' => true, 'data' => ['mode' => 'overview', 'recap' => $overview['recap'], 'locations' => $overview['locations']] + $shared]);
            } else {
                $recap = Sarpras::recap($db, $watch(), $library);
                unset($recap['settings']);
                $json(['ok' => true, 'data' => ['mode' => 'location', 'recap' => $recap, 'location' => $place()] + $shared]);
            }
        }
    } catch (Throwable $error) {
        if (!$isSchema($error)) error_log('[sarpras] read: ' . $error->getMessage());
        $report($error);
        $json(['ok' => false, 'message' => $isSchema($error) ? $schemaMessage : 'Data belum bisa dibaca. Periksa log PHP.'], 500);
    }
    exit;
}

require_once __DIR__ . '/Workspace.php';
\SLiMS\Plugins\Inventory\Workspace::shell($views[$page], $canWrite);
