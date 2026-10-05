<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$plugin = (string) file_get_contents($root . '/inventory.plugin.php');
$controller = (string) file_get_contents($root . '/src/SarprasController.php');
$failures = [];

// Sidebar sections and their menus, in the order they are registered.
$expected = [
    'Klaras Inven' => ['Rekap Sarpras' => 'sarpras.php', 'Tugas' => 'inspection.php', 'Jadwal' => 'checklist-and-schedule.php', 'Checklist' => 'findings-and-follow-up.php', 'Laporan' => 'report.php'],
    'Data Sarpras' => ['Ruangan & Barang' => 'index.php', 'Perangkat Lunak' => 'software.php', 'Gedung & Jaringan' => 'facility.php', 'Sivitas per Lokasi' => 'sivitas.php'],
    'Pengaturan Inven' => ['Pengaturan Cetak' => 'print-settings.php', 'Aplikasi InvenSync' => 'app.php'],
];
preg_match_all("/^    '([^']+)' => \\[$/m", $plugin, $groups);
preg_match_all("/^        \\['([^']+)','([^']+\\.php)',/m", $plugin, $menus, PREG_SET_ORDER);
$registered = [];
foreach ($menus as [, $label, $file]) $registered[$label] = $file;
$flat = array_merge(...array_values($expected));

// Each page of the controller: the actions it accepts, read from the same table the controller uses.
preg_match_all("/^    '(recap|software|facility|sivitas)' => \\[(.*)\\],$/m", $controller, $pages, PREG_SET_ORDER);
$actions = [];
foreach ($pages as [, $page, $list]) $actions[$page] = $list === '' ? [] : array_map(static fn($a) => trim($a, " '"), explode(',', $list));

$entry = static fn(string $file) => (string) file_get_contents($root . '/' . $file);
$checks = [
    'tiga bagian sidebar terdaftar berurutan' => $groups[1] === array_keys($expected),
    'menu terdaftar sesuai bagian dan urutannya' => $registered === $flat,
    'setiap menu menunjuk berkas yang ada' => !array_filter($registered, static fn($file) => !is_file($root . '/' . $file)),
    'halaman sarpras dimuat lewat controller' => str_contains($entry('sarpras.php'), "\$inventorySarprasPage = 'recap'")
        && str_contains($entry('software.php'), "\$inventorySarprasPage = 'software'")
        && str_contains($entry('facility.php'), "\$inventorySarprasPage = 'facility'")
        && str_contains($entry('sivitas.php'), "\$inventorySarprasPage = 'sivitas'"),
    'Rekap Sarpras tidak menerima perubahan data' => ($actions['recap'] ?? null) === []
        && str_contains($controller, 'if (!in_array($action, $actions[$page], true))'),
    'input sarpras hanya di halamannya sendiri' => ($actions['software'] ?? null) === ['software', 'software_delete']
        && ($actions['facility'] ?? null) === ['settings', 'evidence', 'evidence_delete', 'network_upload', 'network_delete']
        && ($actions['sivitas'] ?? null) === ['map', 'counting', 'merge', 'undo'],
    'memperbaiki institusi anggota butuh hak tulis Keanggotaan' => str_contains($controller, "havePrivilege('membership', 'w')")
        && str_contains($controller, 'if (!$canFixMembers)'),
    'bukti dan PDF hanya dilayani halamannya' => str_contains($controller, "\$page === 'facility' && (\$_GET['evidence'] ?? '') === '1'")
        && str_contains($controller, "\$page === 'facility' && isset(\$_GET['network'])")
        && str_contains($controller, "\$page === 'recap' && isset(\$_GET['pdf'])"),
    'controller memeriksa IP, hak akses, dan CSRF' => str_contains($controller, "do_checkIP('smc-stocktake')")
        && str_contains($controller, "havePrivilege('stock_take', 'r')")
        && str_contains($controller, "havePrivilege('stock_take', 'w')")
        && str_contains($controller, "hash_equals(\$_SESSION['inventory_sarpras_csrf'], \$token)"),
    'Pengaturan Cetak memakai controller pengawasan' => str_contains($entry('print-settings.php'), "\$inventoryWorkspaceView='print-settings'")
        && str_contains($entry('print-settings.php'), "/src/WatchController.php"),
];

foreach ($checks as $label => $passed) {
    echo ($passed ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$passed) {
        $failures[] = $label;
    }
}

exit($failures ? 1 : 0);
