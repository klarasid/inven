<?php
/**
 * Stock Take → Data pemakaian: exactly what this plugin reports to Klaras each day, and the switch
 * to stop it. Opening the page counts as having seen the notice.
 *
 * Loaded by admin/plugin_container.php, which checks nothing itself, so the session, IP and
 * privilege checks here are the page's only protection. The page itself is the React workspace
 * (view "privacy"); it reads ?format=json and posts its actions back here as JSON. Switching reporting off or on needs
 * System write access.
 */
defined('INDEX_AUTH') || die('Direct access not allowed!');

require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-stocktake');
require SB . 'admin/default/session.inc.php';
require SB . 'admin/default/session_check.inc.php';
require_once __DIR__ . '/src/UpdateCheck.php';
require_once __DIR__ . '/src/Telemetry.php';

use SLiMS\Plugins\Inventory\Telemetry;

if (!utility::havePrivilege('stock_take', 'r')) {
    die('<div class="errorBox">' . __('You are not authorized to view this section') . '</div>');
}

$canManage = utility::havePrivilege('system', 'w');
if (empty($_SESSION['inventory_telemetry_csrf'])) {
    $_SESSION['inventory_telemetry_csrf'] = bin2hex(random_bytes(24));
}
$db = \SLiMS\DB::getInstance();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$json = static function (array $body, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) filter_input(INPUT_POST, 'csrf', FILTER_UNSAFE_RAW);
    if (!$canManage || !hash_equals($_SESSION['inventory_telemetry_csrf'], $token)) {
        $json(['ok' => false, 'message' => 'Anda perlu hak tulis System untuk mengubah pengaturan ini.'], 403);
        exit;
    }
    // The notice banner's "Mengerti" button: seen once, gone for every administrator.
    if (isset($_POST['acknowledge'])) {
        Telemetry::acknowledge($db);
        http_response_code(204);
        exit;
    }
    $enable = ($_POST['action'] ?? '') === 'enable';
    Telemetry::setEnabled($db, $enable);
    writeLog('staff', (string) $_SESSION['uid'], 'Klaras Inven', $enable ? 'Pengiriman data pemakaian diaktifkan.' : 'Pengiriman data pemakaian dimatikan.', 'stock_take', 'Update');
    $json(['ok' => true, 'message' => $enable ? 'Pengiriman data pemakaian aktif kembali.' : 'Pengiriman dimatikan. Klaras menghapus nama dan alamat perpustakaan ini dari datanya.']);
    exit;
}

if (($_GET['format'] ?? '') === 'json') {
    $state = Telemetry::state($db);
    $json(['ok' => true, 'data' => [
        'enabled' => $state['enabled'],
        'last_sent' => $state['last_sent'] ? date('Y-m-d H:i', $state['last_sent']) : null,
        'last_result' => $state['last_result'],
        'endpoint' => Telemetry::endpoint(),
        'install_id' => $state['install_id'],
        'report' => Telemetry::report($db),
        'manage' => $canManage,
        'csrf' => $canManage ? $_SESSION['inventory_telemetry_csrf'] : '',
    ]]);
    exit;
}

// Opening this page counts as having seen the notice.
if ($canManage) {
    Telemetry::acknowledge($db);
}
require_once __DIR__ . '/src/Workspace.php';
\SLiMS\Plugins\Inventory\Workspace::shell('privacy', utility::havePrivilege('stock_take', 'w'), [
    'page' => \SLiMS\Plugins\Inventory\Workspace::endpoint('privacy.php'),
]);
