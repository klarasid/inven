<?php
/**
 * Plugin Name: Inventaris Barang Perpustakaan
 * Plugin URI: https://github.com/idoalit/slims-inventarisasi-barang-plugin
 * Description: Pencatatan inventaris barang per lokasi/ruangan dan pencetakan Kartu Inventaris Ruangan dalam format PDF.
 * Version: 2.0.0
 * Author: Waris Agung Widodo
 * Author URI: https://github.com/idoalit
 */

use SLiMS\Plugins;

defined('INDEX_AUTH') || die('Direct access not allowed!');

Plugins::group('Inventaris Barang', function() {
    foreach ([
        ['Tugas','inspection.php','Pemeriksaan, tindak lanjut, dan verifikasi.'],
        ['Ruangan & Barang','index.php','Kelola ruangan, barang, dan kartu inventaris.'],
        ['Jadwal','checklist-and-schedule.php','Atur pemeriksaan rutin ruangan.'],
        ['Checklist','findings-and-follow-up.php','Kelola checklist dan riwayat versinya.'],
        ['Laporan','report.php','Tinjau capaian dan cetak laporan periode.'],
    ] as [$label,$file,$description]) Plugins::registerMenu('stock_take',$label,__DIR__.'/'.$file,$description);
});

// Public item page for label QR codes: index.php?p=info_barang (not listed in OPAC navigation).
Plugins::registerMenu('opac', 'Info Barang', __DIR__ . '/opac.php', 'Informasi barang inventaris dari label QR.');
