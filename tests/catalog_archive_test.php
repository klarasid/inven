<?php
declare(strict_types=1);
require __DIR__ . '/../src/InventoryCatalog.php';
use SLiMS\Plugins\Inventory\InventoryCatalog;
$f = 0; $ok = function (bool $c, string $m) use (&$f) { echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $f++; };
$item = fn ($n, $c, $p) => ['item_name' => $n, 'item_code' => $c, 'photos' => $p];
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
$ok(in_array('Sarana akses/Kiosk OPAC-1 K-1-1.jpg', $names, true), 'sanitized name: ' . implode('|', $names));
$ok(count($names) === count(array_unique($names)), 'duplicate names are numbered');
$ok(in_array('Perabot/Meja-1.jpg', $names, true), 'no code');
try { InventoryCatalog::archive(['groups' => []], $read, sys_get_temp_dir()); $ok(false, 'empty refused'); } catch (RuntimeException $e) { $ok(true, 'empty refused'); }
unlink($tmp); echo $f ? "$f FAIL\n" : "all ok\n"; exit($f ? 1 : 0);
