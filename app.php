<?php
/**
 * Stock Take → Aplikasi InvenSync: allow the Klaras InvenSync mobile app, and see or end the
 * app sessions of this library's librarians.
 *
 * Loaded by admin/plugin_container.php, which checks nothing itself, so the session, IP and
 * privilege checks here are the page's only protection. The page itself is the React workspace
 * (view "invensync"); it reads ?format=json and posts its actions back here as JSON. Switching the app on and ending other
 * people's sessions needs System write access, not only Stock Take. So does allowing AI apps
 * (Api\AgentCodes), whose consent page is also served from here (?agent=authorize).
 */
defined('INDEX_AUTH') || die('Direct access not allowed!');

require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-stocktake');
require SB . 'admin/default/session.inc.php';
// An AI app asking, through Klaras Panel, to work as the signed-in librarian: a page of its own.
if (($_GET['agent'] ?? '') === 'authorize') {
    require __DIR__ . '/src/AgentConsent.php';
    exit;
}
require SB . 'admin/default/session_check.inc.php';

if (!utility::havePrivilege('stock_take', 'r')) {
    die('<div class="errorBox">' . __('You are not authorized to view this section') . '</div>');
}

$canManage = utility::havePrivilege('system', 'w');
$connectReady = PHP_VERSION_ID >= 80100 && class_exists('SlimsConnect\\Http\\Kernel');
if (empty($_SESSION['invensync_csrf'])) {
    $_SESSION['invensync_csrf'] = bin2hex(random_bytes(24));
}
$db = \SLiMS\DB::getInstance();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($connectReady) {
    require_once __DIR__ . '/src/Api/bootstrap.php';
}
$json = static function (array $body, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) filter_input(INPUT_POST, 'csrf', FILTER_UNSAFE_RAW);
    if (!$canManage || !$connectReady || !hash_equals($_SESSION['invensync_csrf'], $token)) {
        $json(['ok' => false, 'message' => 'Anda perlu hak tulis System untuk mengubah pengaturan ini.'], 403);
        exit;
    }
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'agents_enable' || $action === 'agents_disable') {
            $enable = $action === 'agents_enable';
            \SLiMS\Plugins\Inventory\Api\AgentCodes::setEnabled($db, $enable, date('Y-m-d H:i:s'));
            \SLiMS\Plugins\Inventory\Api\Heartbeat::now();
            writeLog('staff', (string) $_SESSION['uid'], 'Klaras InvenSync', $enable ? 'Agent AI diizinkan.' : 'Agent AI dimatikan; semua sesinya dicabut.', 'stock_take', 'Update');
            $json(['ok' => true, 'message' => $enable ? 'Agent AI diizinkan.' : 'Agent AI dimatikan. Semua aplikasi AI yang tersambung harus diizinkan lagi.']);
        } elseif ($action === 'enable' || $action === 'disable') {
            $enable = $action === 'enable';
            \SLiMS\Plugins\Inventory\Api\Guard::setEnabled($db, $enable);
            if ($enable && !defined('INVENSYNC_API_VERSION')) {
                define('INVENSYNC_API_VERSION', 2);
            }
            \SLiMS\Plugins\Inventory\Api\Heartbeat::now();
            writeLog('staff', (string) $_SESSION['uid'], 'Klaras InvenSync', $enable ? 'Aplikasi InvenSync diizinkan.' : 'Aplikasi InvenSync dimatikan.', 'stock_take', 'Update');
            $json(['ok' => true, 'message' => $enable ? 'Aplikasi InvenSync diizinkan.' : 'Aplikasi InvenSync dimatikan. Petugas tidak bisa memakainya lagi.']);
        } elseif ($action === 'revoke' && ($session = filter_input(INPUT_POST, 'session', FILTER_VALIDATE_INT))) {
            (new \SLiMS\Plugins\Inventory\Api\StaffTokens($db))->revoke((int) $session);
            writeLog('staff', (string) $_SESSION['uid'], 'Klaras InvenSync', 'Sesi aplikasi #' . (int) $session . ' dicabut.', 'stock_take', 'Delete');
            $json(['ok' => true, 'message' => 'Sesi dicabut. Perangkat itu harus masuk lagi.']);
        } else {
            $json(['ok' => false, 'message' => 'Aksi tidak dikenal.'], 400);
        }
    } catch (Throwable $error) {
        error_log('[invensync] settings: ' . $error->getMessage());
        $json(['ok' => false, 'message' => 'Pengaturan tidak tersimpan. Pastikan migrasi plugin sudah dijalankan di System → Plugins.'], 500);
    }
    exit;
}

if (($_GET['format'] ?? '') === 'json') {
    $enabled = false;
    $agents = false;
    $linked = false;
    $licensed = false;
    $sessions = [];
    $problem = '';
    if ($connectReady) {
        try {
            $enabled = \SLiMS\Plugins\Inventory\Api\Guard::enabled($db);
            $agents = \SLiMS\Plugins\Inventory\Api\AgentCodes::enabled($db);
            $linked = \SLiMS\Plugins\Inventory\Api\Licence::linked();
            $licensed = $linked && \SLiMS\Plugins\Inventory\Api\Licence::allows();
            $sessions = array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'name' => (string) ($row['realname'] ?: $row['username']),
                    'device' => (string) $row['device_name'],
                    'ip' => (string) $row['ip'],
                    'created_at' => (string) $row['created_at'],
                    'last_used_at' => (string) $row['last_used_at'],
                    'agent' => ($row['kind'] ?? 'app') === \SLiMS\Plugins\Inventory\Api\AgentCodes::KIND,
                ];
            }, (new \SLiMS\Plugins\Inventory\Api\StaffTokens($db))->active());
        } catch (Throwable $error) {
            error_log('[invensync] status: ' . $error->getMessage());
            $problem = 'Status belum bisa dibaca. Jalankan migrasi plugin Klaras Inven di System → Plugins.';
        }
    }
    $json(['ok' => true, 'data' => [
        'connect' => $connectReady, 'linked' => $linked, 'licensed' => $licensed, 'enabled' => $enabled, 'agents' => $agents,
        'sessions' => $sessions, 'problem' => $problem,
        'manage' => $canManage, 'csrf' => $canManage ? $_SESSION['invensync_csrf'] : '',
    ]]);
    exit;
}

require_once __DIR__ . '/src/Workspace.php';
\SLiMS\Plugins\Inventory\Workspace::shell('invensync', utility::havePrivilege('stock_take', 'w'), [
    'page' => \SLiMS\Plugins\Inventory\Workspace::endpoint('app.php'),
]);
