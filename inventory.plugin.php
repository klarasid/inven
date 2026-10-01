<?php
/**
 * Plugin Name: Klaras Inven
 * Plugin URI: https://github.com/klarasid/inven
 * Description: Inventaris barang, pengawasan, dan pemeliharaan sarana prasarana perpustakaan: ruangan dan barang, Kartu Inventaris Ruangan, jadwal pemeriksaan, laporan kerusakan, label QR, dan laporan PDF.
 * Version: 2.1.0
 * Author: KlarasID
 * Author URI: https://github.com/klarasid
 */

use SLiMS\Plugins;

defined('INDEX_AUTH') || die('Direct access not allowed!');

Plugins::group('Klaras Inven', function() {
    foreach ([
        ['Tugas','inspection.php','Pemeriksaan, tindak lanjut, dan verifikasi.'],
        ['Ruangan & Barang','index.php','Kelola ruangan, barang, dan kartu inventaris.'],
        ['Jadwal','checklist-and-schedule.php','Atur pemeriksaan rutin ruangan.'],
        ['Checklist','findings-and-follow-up.php','Kelola checklist dan riwayat versinya.'],
        ['Laporan','report.php','Tinjau capaian dan cetak laporan periode.'],
        ['Aplikasi InvenSync','app.php','Izinkan aplikasi Klaras InvenSync dan kelola perangkat yang masuk.'],
    ] as [$label,$file,$description]) Plugins::registerMenu('stock_take',$label,__DIR__.'/'.$file,$description);
});

// Public item page for label QR codes: index.php?p=info_barang (not listed in OPAC navigation).
Plugins::registerMenu('opac', 'Info Barang', __DIR__ . '/opac.php', 'Informasi barang inventaris dari label QR.');

// Klaras InvenSync, the mobile app: index.php?p=api/invensync/v1/…
// The API runs on SLiMS Connect (licence, tokens, HTTP), which needs PHP 8.1, so nothing of it
// is loaded until a request reaches the API router and SLiMS Connect is there.
if (!empty($GLOBALS['sysconf']['invensync_enabled']) && $GLOBALS['sysconf']['invensync_enabled'] === '1' && !defined('INVENSYNC_API_VERSION')) {
    // Read by SLiMS Connect's heartbeat: Klaras Panel lists this library in the app only then.
    define('INVENSYNC_API_VERSION', 1);
}
Plugins::getInstance()->registerHook('custom_api_route', function ($router) {
    if (PHP_VERSION_ID >= 80100 && class_exists('SlimsConnect\\Http\\Kernel')) {
        require_once __DIR__ . '/src/Api/bootstrap.php';
        \SLiMS\Plugins\Inventory\Api\Routes::register($router);
        return;
    }
    $router->map('GET|POST|PUT|PATCH|DELETE', '/invensync/v1[**:rest]?', function () {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['error' => [
            'code' => 'klaras_not_linked',
            'message' => 'Pasang dan tautkan SLiMS Connect di SLiMS ini agar aplikasi InvenSync bisa tersambung.',
            'request_id' => bin2hex(random_bytes(12)),
            'details' => null,
        ], 'meta' => ['api_version' => 'v1']]);
    });
});
