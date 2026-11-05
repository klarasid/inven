<?php
declare(strict_types=1);
require __DIR__ . '/../src/InventoryCatalog.php';
use SLiMS\Plugins\Inventory\InventoryCatalog;
$f = 0; $ok = function (bool $c, string $m) use (&$f) { echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $f++; };
$item = fn ($n, $c, $p) => ['item_name' => $n, 'item_code' => $c, 'location_code' => 'R01', 'photos' => $p];
$catalog = ['groups' => [
    ['label' => 'Sarana akses', 'items' => [$item('Kiosk OPAC/1', 'K-1', ['a', 'b']), $item('Kiosk OPAC/1', 'K-1', ['a'])]],
    ['label' => 'Perabot', 'items' => [$item('Meja', '', ['a', 'missing'])]],
]];
$read = fn ($n) => $n === 'missing' ? null : 'JPEG' . $n;
$r = InventoryCatalog::archive($catalog, $read, sys_get_temp_dir());
$tmp = tempnam(sys_get_temp_dir(), 'z'); file_put_contents($tmp, $r['bytes']);
$zip = new ZipArchive(); $zip->open($tmp);
$names = []; for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
$ok($r['files'] === 4, '4 files, unreadable skipped');
$ok(in_array('Sarana akses/R01-K-1-Kiosk OPAC-1-1.jpg', $names, true), 'sanitized name: ' . implode('|', $names));
$ok(count($names) === count(array_unique($names)), 'duplicate names are numbered');
$ok(in_array('Perabot/R01-Meja-1.jpg', $names, true), 'no code');
try { InventoryCatalog::archive(['groups' => []], $read, sys_get_temp_dir()); $ok(false, 'empty refused'); } catch (RuntimeException $e) { $ok(true, 'empty refused'); }
require __DIR__ . '/../src/AreaCatalog.php';
use SLiMS\Plugins\Inventory\AreaCatalog;
$areas = ['groups' => [['label' => 'Layanan dasar', 'areas' => [
    ['location_code' => 'R01', 'label' => 'Area baca', 'name' => 'Sudut anak', 'photos' => ['a', 'b']],
    ['location_code' => null, 'label' => 'Tempat parkir', 'name' => '', 'photos' => ['a']]], 'items' => []],
    ['label' => 'Fasilitas umum', 'areas' => [], 'items' => [$item('Papan nama', 'P-1', ['i'])]]]];
$r = AreaCatalog::archive($areas, fn ($n) => 'A' . $n, fn ($n) => 'I' . $n, sys_get_temp_dir());
file_put_contents($tmp, $r['bytes']); $zip = new ZipArchive(); $zip->open($tmp);
$names = []; for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
$ok(in_array('Layanan dasar/R01-Area baca-Sudut anak-2.jpg', $names, true), 'area name: ' . implode('|', $names));
$ok(in_array('Layanan dasar/Tempat parkir-1.jpg', $names, true), 'area without code or name');
$ok(in_array('Fasilitas umum/R01-P-1-Papan nama-1.jpg', $names, true) && $zip->getFromName('Fasilitas umum/R01-P-1-Papan nama-1.jpg') === 'Ii', 'facility item uses item reader');
unlink($tmp); echo $f ? "$f FAIL\n" : "all ok\n"; exit($f ? 1 : 0);
