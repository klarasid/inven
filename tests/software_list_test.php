<?php

declare(strict_types=1);

// Daftar perangkat lunak: how the register is grouped, what counts as licensed, and what is printed.
require __DIR__ . '/../src/SoftwareList.php';

use SLiMS\Plugins\Inventory\SoftwareList;

function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL ' . $label); echo "ok   $label\n"; }

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE inventory_software_files (id INTEGER PRIMARY KEY, software_id INTEGER, title TEXT, filename TEXT, mime TEXT, created_by INTEGER, created_at TEXT)');
$db->exec('CREATE TABLE inventory_software (id INTEGER PRIMARY KEY, name TEXT, version TEXT NOT NULL DEFAULT \'\', purpose TEXT NOT NULL DEFAULT \'\', licence TEXT, licence_ref TEXT NOT NULL DEFAULT \'\', valid_until TEXT, installs INTEGER NOT NULL DEFAULT 1, notes TEXT)');
check(SoftwareList::build($db, '2026-10-06') === ['applications' => 0, 'licensed' => 0, 'percent' => null, 'groups' => []], 'register kosong tidak punya persentase');
$db->exec("INSERT INTO inventory_software_files (software_id, title, filename, mime, created_at) VALUES (1, 'Stiker COA', 'lisensi-a.png', 'image/png', '2026-10-01 08:00:00'), (1, 'Faktur', 'lisensi-b.pdf', 'application/pdf', '2026-10-01 08:00:00'), (4, 'Halaman lisensi', 'lisensi-c.png', 'image/png', '2026-10-01 08:00:00')");
$db->exec("INSERT INTO inventory_software (id, name, version, purpose, licence, licence_ref, valid_until, installs) VALUES
    (1, 'Windows 11 Pro', '23H2', 'Sistem operasi', 'komersial', 'OEM-<123>', NULL, 12),
    (2, 'Ubuntu', '24.04', 'sistem  operasi', 'open_source', '', NULL, 3),
    (3, 'Microsoft 365', '', 'Aplikasi perkantoran', 'langganan', 'Kontrak 45/2025', '2026-10-05', 12),
    (4, 'SLiMS', '9.6', 'Otomasi perpustakaan', 'open_source', '', NULL, 1),
    (5, 'Photoshop', 'CS6', '', 'tidak', '', NULL, 2),
    (6, 'Zotero', '', 'Manajemen referensi', 'freeware', '', '2026-10-06', 5)");
$list = SoftwareList::build($db, '2026-10-06');
$names = array_map(static fn ($group) => [$group['label'] => array_column($group['items'], 'name')], $list['groups']);
check($names === [
    ['Aplikasi perkantoran' => ['Microsoft 365']],
    ['Manajemen referensi' => ['Zotero']],
    ['Otomasi perpustakaan' => ['SLiMS']],
    ['Sistem operasi' => ['Ubuntu', 'Windows 11 Pro']],
    ['Kegunaan belum diisi' => ['Photoshop']],
], 'aplikasi dikelompokkan per kegunaan tanpa membedakan huruf besar dan spasi; yang belum diisi paling akhir');
check($list['applications'] === 6 && $list['licensed'] === 4 && $list['percent'] === 66.7, 'open source dan gratis dihitung resmi; lisensi yang berlaku sampai hari ini masih resmi');
$byName = array_column(array_merge(...array_column($list['groups'], 'items')), null, 'name');
check($byName['Microsoft 365']['expired'] === true && $byName['Microsoft 365']['licensed'] === false && $byName['Photoshop']['expired'] === false && $byName['Photoshop']['licensed'] === false,
    'lisensi kedaluwarsa dibedakan dari yang tidak berlisensi');

$html = SoftwareList::html($list, ['printed_by' => 'Rina']);
check(str_contains($html, 'Sistem operasi (2 aplikasi)') && str_contains($html, '4 dari 6 (66,7%)') && str_contains($html, 'OEM-&lt;123&gt;'), 'tiap kelompok dicetak dengan jumlahnya, dan bukti lisensi di-escape');
check(array_column($byName['Windows 11 Pro']['files'], 'title') === ['Stiker COA', 'Faktur'] && $byName['Ubuntu']['files'] === [] && str_contains($html, '2 berkas bukti terlampir') && substr_count($html, 'berkas bukti terlampir') === 2,
    'berkas bukti lisensi ikut pada aplikasinya, dan jumlahnya dicetak');
check(substr_count($html, '>Resmi<') === 4 && substr_count($html, '>Kedaluwarsa<') === 1 && substr_count($html, '>Tidak resmi<') === 1, 'status tiap aplikasi dicetak');
echo "ok   done\n";
