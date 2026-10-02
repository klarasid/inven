<?php

declare(strict_types=1);

$index = (string) file_get_contents(__DIR__ . '/../index.php');
$print = (string) file_get_contents(__DIR__ . '/../print.php');
$root = __DIR__ . '/..';
$read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);
$failures = [];

$checks = [
    'otorisasi menggunakan stock_take' => str_contains($index, "havePrivilege('stock_take', 'r')")
        && str_contains($index, "havePrivilege('stock_take', 'w')")
        && str_contains($print, "havePrivilege('stock_take', 'r')"),
    'cakupan IP menggunakan stocktake' => str_contains($index, "do_checkIP('smc-stocktake')")
        && str_contains($print, "do_checkIP('smc-stocktake')"),
    'mutasi dilindungi CSRF' => str_contains($index, 'hash_equals($csrf'),
    'aktivitas inventaris diaudit' => str_contains($index, "writeLog('staff'")
        && str_contains($print, "'Print'"),
    'PDF tidak disimpan cache' => str_contains($print, 'private, no-store'),
    'PDF tidak mengekspos versi generator' => str_contains($print, "'exposeVersion' => false"),
    'PDF diberi rate limit' => str_contains($print, 'count($pdfRequests) >= 10'),
    'PDF dibatasi 500 barang' => str_contains($print, 'LIMIT 501')
        && str_contains($print, 'count($items) > 500'),
    'pesan exception internal tidak dikirim ke UI' => str_contains(
        $index,
        '$message = \'Terjadi kesalahan internal. Silakan coba lagi atau hubungi administrator.\''
    ),
    'runtime mPDF dikelola Composer plugin' => str_contains($print, "__DIR__ . '/vendor/autoload.php'")
        && str_contains($print, 'class_exists(\\Mpdf\\Mpdf::class)'),
    'PHP plugin tidak dapat diminta langsung lewat web' => (bool) preg_match('/<FilesMatch "[^"]*php[^"]*">\s*Order allow,deny\s*<\/FilesMatch>/', $read('.htaccess')),
    'skrip pustaka yang dapat dijalankan tidak ikut dirilis' => str_contains($read('tools/release.sh'), 'vendor/mpdf/mpdf/data/out.php')
        && str_contains($read('tools/release.sh'), 'vendor/paragonie/random_compat/other'),
    'penampil PDF hanya memuat halaman plugin' => str_contains($read('assets/viewer/viewer.js'), "url.origin !== location.origin")
        && str_contains($read('assets/viewer/viewer.js'), "url.pathname.endsWith('/plugin_container.php')"),
    'folder kop dan bukti dilindungi sendiri' => str_contains($read('src/Letterheads.php'), 'PhotoStorage::protect(')
        && str_contains($read('src/Sarpras.php'), 'PhotoStorage::protect('),
    'label memakai alamat situs yang tervalidasi' => str_contains($read('labels.php'), 'PublicLink::host()')
        && str_contains($read('src/Api/Context.php'), 'PublicLink::host()')
        && !str_contains($read('labels.php'), "\$_SERVER['HTTP_HOST']"),
    'jawaban JSON tidak ditebak jenisnya oleh browser' => array_reduce(
        ['index.php', 'app.php', 'privacy.php', 'inventory.plugin.php', 'src/SarprasController.php', 'src/WatchController.php'],
        static fn (bool $ok, string $file): bool => $ok && substr_count($read($file), "Content-Type: application/json") <= substr_count($read($file), 'X-Content-Type-Options: nosniff'),
        true
    ),
];

foreach ($checks as $label => $passed) {
    echo ($passed ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$passed) {
        $failures[] = $label;
    }
}

exit($failures ? 1 : 0);
