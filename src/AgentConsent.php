<?php
/**
 * Where a librarian allows an AI app to work in Klaras Inven as them (Api\AgentCodes).
 *
 * Klaras Panel sends the librarian here from their AI app. The page is its own document, not a
 * view of the workspace: it opens in the AI app's sign-in window, without SLiMS's admin frame.
 * It may not be framed, so another site cannot dress up the Izinkan button. Allowing it sends
 * the Panel a one-time code, only to the Panel's own callback address.
 *
 * Required from app.php once the admin session has started.
 */
defined('INDEX_AUTH') || die('Direct access not allowed!');

header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

$agentPage = static function (string $title, string $body): void {
    $e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $e($title) . '</title>'
        . '<style>body{margin:0;background:#f4f4f5;color:#18181b;font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}main{max-width:440px;margin:48px auto;padding:0 16px}'
        . '.card{background:#fff;border:1px solid #e4e4e7;border-radius:14px;padding:24px}h1{font-size:20px;margin:0 0 8px}p{margin:0 0 12px;color:#3f3f46}ul{margin:0 0 16px;padding-left:20px;color:#3f3f46}'
        . '.actions{display:flex;gap:8px;margin-top:20px}button,a.button{flex:1;display:inline-flex;justify-content:center;padding:10px 14px;border-radius:9px;border:1px solid #d4d4d8;background:#fff;color:#18181b;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}'
        . 'button.primary{background:#18181b;border-color:#18181b;color:#fff}.muted{font-size:13px;color:#71717a}</style></head><body><main><div class="card">'
        . '<h1>' . $e($title) . '</h1>' . $body . '</div></main></body></html>';
};
$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// Not signed in: say so instead of SLiMS's alert-and-redirect, which would lose this request.
if (empty($_SESSION['uid'])) {
    $agentPage('Masuk ke SLiMS dulu', '<p>Untuk mengizinkan aplikasi AI, masuk ke SLiMS dengan akun Anda, lalu muat ulang halaman ini.</p>'
        . '<div class="actions"><a class="button" href="' . $e(SWB . 'index.php?p=login') . '" target="_blank" rel="noopener">Masuk ke SLiMS</a></div>'
        . '<p class="muted" style="margin-top:16px">Kata sandi Anda hanya dimasukkan di SLiMS, tidak pernah di aplikasi AI atau Klaras.</p>');
    return;
}
require SB . 'admin/default/session_check.inc.php';

$fail = static function (string $message) use ($agentPage, $e): void {
    $agentPage('Aplikasi AI belum bisa dihubungkan', '<p>' . $e($message) . '</p><p class="muted">Tutup jendela ini dan mulai lagi dari aplikasi AI Anda.</p>');
};
if (!utility::havePrivilege('stock_take', 'r')) {
    $fail('Akun Anda tidak punya hak Stock Take, jadi tidak bisa mengizinkan aplikasi AI.');
    return;
}
if (PHP_VERSION_ID < 80100 || !class_exists('SlimsConnect\\Http\\Kernel')) {
    $fail('SLiMS Connect belum terpasang di SLiMS ini.');
    return;
}
require_once __DIR__ . '/Api/bootstrap.php';

use SLiMS\Plugins\Inventory\Api\AgentCodes;
use SLiMS\Plugins\Inventory\Api\Guard;

$db = \SLiMS\DB::getInstance();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$request = [
    'client' => AgentCodes::clientName((string) ($_GET['client'] ?? '')),
    'redirect_uri' => (string) ($_GET['redirect_uri'] ?? ''),
    'state' => (string) ($_GET['state'] ?? ''),
    'code_challenge' => (string) ($_GET['code_challenge'] ?? ''),
];
if (!AgentCodes::redirectAllowed($request['redirect_uri'], \SlimsConnect\Support\Settings::panelUrl())) {
    $fail('Permintaan ini tidak datang dari Klaras Panel yang tertaut ke SLiMS ini.');
    return;
}
if (!AgentCodes::validChallenge($request['code_challenge']) || ($_GET['code_challenge_method'] ?? '') !== 'S256' || preg_match('/\A[A-Za-z0-9._~-]{1,200}\z/', $request['state']) !== 1) {
    $fail('Permintaan tidak lengkap.');
    return;
}
try {
    if (!Guard::enabled($db) || !AgentCodes::enabled($db)) {
        $fail('Administrator SLiMS belum mengizinkan agent AI. Mintalah dinyalakan di Stock Take → Aplikasi InvenSync.');
        return;
    }
} catch (Throwable $error) {
    error_log('[invensync] agent consent: ' . $error->getMessage());
    $fail('Jalankan migrasi plugin Klaras Inven di System → Plugins terlebih dahulu.');
    return;
}
$back = static function (array $query) use ($request): void {
    header('Location: ' . $request['redirect_uri'] . '?' . http_build_query($query + ['state' => $request['state']]), true, 302);
};
if (empty($_SESSION['invensync_csrf'])) {
    $_SESSION['invensync_csrf'] = bin2hex(random_bytes(24));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['invensync_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $fail('Formulir kedaluwarsa. Muat ulang halaman ini.');
        return;
    }
    if (($_POST['decision'] ?? '') !== 'allow') {
        $back(['error' => 'access_denied']);
        return;
    }
    try {
        $code = AgentCodes::create($db, (int) $_SESSION['uid'], $request['client'], $request['code_challenge'], $request['redirect_uri'], time());
    } catch (Throwable $error) {
        error_log('[invensync] agent consent: ' . $error->getMessage());
        $fail('Izin belum bisa diberikan. Pastikan migrasi plugin Klaras Inven sudah dijalankan.');
        return;
    }
    writeLog('staff', (string) $_SESSION['uid'], 'Klaras InvenSync', 'Agent AI ' . $request['client'] . ' diizinkan atas nama ' . ($_SESSION['realname'] ?? '') . '.', 'stock_take', 'Login');
    $back(['code' => $code]);
    return;
}

$library = (string) ($GLOBALS['sysconf']['library_name'] ?? 'SLiMS');
$agentPage('Izinkan ' . $request['client'] . '?', '<p><b>' . $e($request['client']) . '</b> meminta izin bekerja di Klaras Inven ' . $e($library) . ' atas nama <b>' . $e($_SESSION['realname'] ?? '') . '</b>.</p>'
    . '<p>Aplikasi ini dapat:</p><ul><li>membaca ruangan, barang, tugas, jadwal, dan laporan;</li>'
    . (utility::havePrivilege('stock_take', 'w') ? '<li>membuat jadwal, checklist, dan laporan kerusakan, serta mencatat tindak lanjut.</li>' : '<li>tidak dapat mengubah data, karena akun Anda hanya boleh membaca.</li>')
    . '</ul><p class="muted">Semua perubahannya tercatat atas nama Anda. Administrator dapat mencabut izin ini kapan saja di Stock Take → Aplikasi InvenSync.</p>'
    . '<form method="post"><input type="hidden" name="csrf" value="' . $e($_SESSION['invensync_csrf']) . '"><div class="actions">'
    . '<button type="submit" name="decision" value="deny">Tolak</button><button type="submit" name="decision" value="allow" class="primary">Izinkan</button></div></form>');
