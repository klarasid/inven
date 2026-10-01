<?php
/**
 * Stock Take → Aplikasi InvenSync: allow the Klaras InvenSync mobile app, and see or end the
 * app sessions of this library's librarians.
 *
 * Loaded by admin/plugin_container.php, which checks nothing itself, so the session, IP and
 * privilege checks here are the page's only protection. Switching the app on and ending other
 * people's sessions needs System write access, not only Stock Take.
 */
defined('INDEX_AUTH') || die('Direct access not allowed!');

require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-stocktake');
require SB . 'admin/default/session.inc.php';
require SB . 'admin/default/session_check.inc.php';

if (!utility::havePrivilege('stock_take', 'r')) {
    die('<div class="errorBox">' . __('You are not authorized to view this section') . '</div>');
}

$canManage = utility::havePrivilege('system', 'w');
$connectReady = PHP_VERSION_ID >= 80100 && class_exists('SlimsConnect\\Http\\Kernel');
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$selfUrl = $_SERVER['PHP_SELF'] . '?' . http_build_query(['mod' => $_GET['mod'] ?? 'stock_take', 'id' => $_GET['id'] ?? '']);
if (empty($_SESSION['invensync_csrf'])) {
    $_SESSION['invensync_csrf'] = bin2hex(random_bytes(24));
}
$db = \SLiMS\DB::getInstance();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($connectReady) {
    require_once __DIR__ . '/src/Api/bootstrap.php';
}

/* Form submissions arrive in the hidden submitExec iframe. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) filter_input(INPUT_POST, 'csrf', FILTER_UNSAFE_RAW);
    if (!$canManage || !$connectReady || !hash_equals($_SESSION['invensync_csrf'], $token)) {
        utility::jsToastr('Aplikasi InvenSync', 'Anda perlu hak tulis System untuk mengubah pengaturan ini.', 'error');
        exit;
    }
    try {
        if (isset($_POST['enable']) || isset($_POST['disable'])) {
            $enable = isset($_POST['enable']);
            \SLiMS\Plugins\Inventory\Api\Guard::setEnabled($db, $enable);
            writeLog('staff', (string) $_SESSION['uid'], 'Klaras InvenSync', $enable ? 'Aplikasi InvenSync diizinkan.' : 'Aplikasi InvenSync dimatikan.', 'stock_take', 'Update');
            utility::jsToastr('Aplikasi InvenSync', $enable ? 'Aplikasi InvenSync diizinkan.' : 'Aplikasi InvenSync dimatikan. Petugas tidak bisa memakainya lagi.', 'success');
        } elseif (($session = filter_input(INPUT_POST, 'revoke', FILTER_VALIDATE_INT)) !== null && $session !== false) {
            (new \SLiMS\Plugins\Inventory\Api\StaffTokens($db))->revoke((int) $session);
            writeLog('staff', (string) $_SESSION['uid'], 'Klaras InvenSync', 'Sesi aplikasi #' . (int) $session . ' dicabut.', 'stock_take', 'Delete');
            utility::jsToastr('Aplikasi InvenSync', 'Sesi dicabut. Perangkat itu harus masuk lagi.', 'success');
        }
    } catch (Throwable $error) {
        error_log('[invensync] settings: ' . $error->getMessage());
        utility::jsToastr('Aplikasi InvenSync', 'Pengaturan tidak tersimpan. Pastikan migrasi plugin sudah dijalankan di System → Plugins.', 'error');
    }
    echo '<script>parent.jQuery("#mainContent").simbioAJAX(' . json_encode($selfUrl) . ');</script>';
    exit;
}

$enabled = $connectReady && \SLiMS\Plugins\Inventory\Api\Guard::enabled($db);
$linked = false;
$licensed = false;
$sessions = [];
$problem = '';
if ($connectReady) {
    try {
        $linked = \SLiMS\Plugins\Inventory\Api\Licence::linked();
        $licensed = $linked && \SLiMS\Plugins\Inventory\Api\Licence::allows();
        $sessions = (new \SLiMS\Plugins\Inventory\Api\StaffTokens($db))->active();
    } catch (Throwable $error) {
        error_log('[invensync] status: ' . $error->getMessage());
        $problem = 'Status belum bisa dibaca. Jalankan migrasi plugin Inventaris Barang di System → Plugins.';
    }
}
$status = static function (bool $ok, string $yes, string $no) use ($escape): string {
    return '<span class="' . ($ok ? 'text-success' : 'text-danger') . '">' . $escape($ok ? $yes : $no) . '</span>';
};
?>
<div class="menuBox">
    <div class="menuBoxInner stockTakeIcon">
        <div class="per_title"><h2>Aplikasi InvenSync</h2></div>
        <div class="infoBox">
            Klaras InvenSync adalah aplikasi HP untuk mencatat barang, memeriksa ruangan, dan menjalankan stock opname langsung ke SLiMS ini.
            Petugas masuk dengan akun SLiMS masing-masing dan hanya bisa melakukan yang diizinkan hak Stock Take mereka.
            Plugin ini tetap bisa dipakai penuh dari SLiMS tanpa aplikasi.
        </div>
    </div>
</div>

<?php if ($problem !== ''): ?><div class="errorBox"><?= $escape($problem) ?></div><?php endif; ?>

<table class="s-table table">
    <tr><th width="30%">SLiMS Connect</th><td><?= $status($connectReady, 'Terpasang', 'Belum terpasang. Pasang SLiMS Connect (PHP 8.1 atau lebih baru) untuk memakai aplikasi.') ?></td></tr>
    <tr><th>Tautan ke Klaras Panel</th><td><?= $connectReady ? $status($linked, 'Tertaut', 'Belum tertaut. Daftarkan perpustakaan di Klaras Panel, lalu isi API key di System → SLiMS Connect.') : '—' ?></td></tr>
    <tr><th>Paket Klaras Panel</th><td><?= $linked ? $status($licensed, 'Mencakup Klaras InvenSync', 'Belum mencakup Klaras InvenSync') : '—' ?></td></tr>
    <tr><th>Izin aplikasi</th><td><?= $status($enabled, 'Diizinkan', 'Belum diizinkan') ?></td></tr>
</table>

<?php if ($connectReady && $canManage): ?>
<iframe name="submitExec" class="noBlock" style="display: none; width: 100%; height: 0;"></iframe>
<form method="post" action="<?= $escape($selfUrl) ?>" target="submitExec" class="notAJAX" style="margin-bottom:1rem">
    <input type="hidden" name="csrf" value="<?= $escape($_SESSION['invensync_csrf']) ?>">
    <?php if ($enabled): ?>
        <input type="submit" name="disable" class="btn btn-danger" value="Matikan aplikasi" onclick="return confirm('Matikan aplikasi InvenSync? Semua petugas langsung tidak bisa memakainya, dan perubahan yang belum terkirim tertahan di HP mereka.')">
    <?php else: ?>
        <input type="submit" name="enable" class="btn btn-primary" value="Izinkan aplikasi">
    <?php endif; ?>
</form>
<?php elseif ($connectReady): ?>
<p class="text-muted">Hanya pengguna dengan hak tulis System yang bisa mengubah izin dan mencabut sesi.</p>
<?php endif; ?>

<?php if ($connectReady): ?>
<h3 style="margin-top:1rem">Perangkat yang masuk</h3>
<?php if (!$sessions): ?>
    <p class="text-muted">Belum ada petugas yang masuk ke aplikasi.</p>
<?php else: ?>
<table class="s-table table">
    <thead><tr><th>Petugas</th><th>Perangkat</th><th>Masuk</th><th>Terakhir aktif</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($sessions as $session): ?>
        <tr>
            <td><?= $escape($session['realname'] ?: $session['username']) ?></td>
            <td><?= $escape($session['device_name']) ?><br><small class="text-muted"><?= $escape($session['ip']) ?></small></td>
            <td><?= $escape($session['created_at']) ?></td>
            <td><?= $escape($session['last_used_at']) ?></td>
            <?php if ($canManage): ?>
            <td>
                <form method="post" action="<?= $escape($selfUrl) ?>" target="submitExec" class="notAJAX" onsubmit="return confirm('Cabut sesi ini? Perangkat itu harus masuk lagi, dan perubahan yang belum terkirim tertahan di HP.')">
                    <input type="hidden" name="csrf" value="<?= $escape($_SESSION['invensync_csrf']) ?>">
                    <input type="hidden" name="revoke" value="<?= (int) $session['id'] ?>">
                    <input type="submit" class="btn btn-sm btn-default" value="Cabut sesi">
                </form>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php endif; ?>
