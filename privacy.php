<?php
/**
 * Stock Take → Data pemakaian: exactly what this plugin reports to Klaras each day, and the switch
 * to stop it. Opening the page counts as having seen the notice.
 *
 * Loaded by admin/plugin_container.php, which checks nothing itself, so the session, IP and
 * privilege checks here are the page's only protection. Switching reporting off or on needs
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
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$selfUrl = $_SERVER['PHP_SELF'] . '?' . http_build_query(['mod' => $_GET['mod'] ?? 'stock_take', 'id' => $_GET['id'] ?? '']);
if (empty($_SESSION['inventory_telemetry_csrf'])) {
    $_SESSION['inventory_telemetry_csrf'] = bin2hex(random_bytes(24));
}
$db = \SLiMS\DB::getInstance();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* Form submissions arrive in the hidden submitExec iframe. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) filter_input(INPUT_POST, 'csrf', FILTER_UNSAFE_RAW);
    if (!$canManage || !hash_equals($_SESSION['inventory_telemetry_csrf'], $token)) {
        utility::jsToastr('Data pemakaian', 'Anda perlu hak tulis System untuk mengubah pengaturan ini.', 'error');
        exit;
    }
    $enable = isset($_POST['enable']);
    Telemetry::setEnabled($db, $enable);
    writeLog('staff', (string) $_SESSION['uid'], 'Klaras Inven', $enable ? 'Pengiriman data pemakaian diaktifkan.' : 'Pengiriman data pemakaian dimatikan.', 'stock_take', 'Update');
    utility::jsToastr('Data pemakaian', $enable ? 'Pengiriman data pemakaian aktif kembali.' : 'Pengiriman dimatikan. Klaras menghapus nama dan alamat perpustakaan ini dari datanya.', 'success');
    echo '<script>parent.jQuery("#mainContent").simbioAJAX(' . json_encode($selfUrl) . ');</script>';
    exit;
}

if ($canManage) {
    Telemetry::acknowledge($db);
}
$state = Telemetry::state($db);
$report = Telemetry::report($db);
Telemetry::sendLater();
$results = ['ok' => 'Terkirim', 'failed' => 'Gagal terkirim, dicoba lagi dalam satu jam', 'off' => 'Dimatikan'];
?>
<div class="menuBox">
    <div class="menuBoxInner stockTakeIcon">
        <div class="per_title"><h2>Data pemakaian</h2></div>
        <div class="infoBox">
            Sekali sehari, Klaras Inven mengirim ringkasan pemakaian ke Klaras agar plugin gratis ini bisa terus dirawat:
            versi mana yang dipakai, fitur apa yang berguna, dan galat apa yang perlu diperbaiki.
            Yang dikirim hanya nama perpustakaan, alamat SLiMS, versi perangkat lunak, jumlah (ruangan, barang, pemeriksaan, temuan, stock opname),
            pemakaian fitur, dan galat teknis yang sudah dibersihkan dari isinya.
            <strong>Isi inventaris, nama dan kode barang, data anggota, dan data petugas tidak pernah dikirim.</strong>
        </div>
    </div>
</div>

<table class="s-table table">
    <tr><th width="30%">Pengiriman</th><td><?= $state['enabled'] ? '<span class="text-success">Aktif</span>' : '<span class="text-danger">Dimatikan</span>' ?></td></tr>
    <tr><th>Terakhir dikirim</th><td><?= $state['last_sent'] ? $escape(date('Y-m-d H:i', $state['last_sent'])) . ' — ' . $escape($results[$state['last_result']] ?? $state['last_result']) : 'Belum pernah' ?></td></tr>
    <tr><th>Tujuan</th><td><code><?= $escape(Telemetry::endpoint()) ?></code></td></tr>
    <tr><th>ID instalasi</th><td><code><?= $escape($state['install_id']) ?></code><div class="formElementInfo">Acak, dibuat di SLiMS ini. Tidak terkait dengan orang mana pun.</div></td></tr>
</table>

<?php if ($canManage): ?>
<iframe name="submitExec" class="noBlock" style="display: none; width: 100%; height: 0;"></iframe>
<form method="post" action="<?= $escape($selfUrl) ?>" target="submitExec" class="notAJAX" style="margin-bottom:1rem">
    <input type="hidden" name="csrf" value="<?= $escape($_SESSION['inventory_telemetry_csrf']) ?>">
    <?php if ($state['enabled']): ?>
        <input type="submit" name="disable" class="btn btn-default" value="Matikan pengiriman" onclick="return confirm('Matikan pengiriman data pemakaian? Klaras akan diberi tahu sekali, lalu menghapus nama dan alamat perpustakaan ini dari datanya.')">
    <?php else: ?>
        <input type="submit" name="enable" class="btn btn-primary" value="Aktifkan kembali">
    <?php endif; ?>
</form>
<?php else: ?>
<p class="text-muted">Hanya pengguna dengan hak tulis System yang bisa mematikan atau mengaktifkan pengiriman.</p>
<?php endif; ?>

<h3 style="margin-top:1rem">Data yang dikirim</h3>
<p class="text-muted">Persis seperti di bawah ini, dalam format JSON.</p>
<pre style="max-height:480px;overflow:auto;background:#f6f8fa;padding:1rem;border-radius:6px"><?= $escape(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
