<?php

declare(strict_types=1);
// Defences that do not need a database: private upload folders, the host printed on labels,
// and the links the update notice shows.
require __DIR__ . '/../src/PhotoStorage.php';
require __DIR__ . '/../src/PublicLink.php';
require __DIR__ . '/../src/UpdateCheck.php';

use SLiMS\Plugins\Inventory\PhotoStorage;
use SLiMS\Plugins\Inventory\PublicLink;
use SLiMS\Plugins\Inventory\UpdateCheck;

function check(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException('FAIL ' . $message); }
    echo 'ok   ' . $message . PHP_EOL;
}
function rejects(callable $operation, string $message): void
{
    try { $operation(); } catch (RuntimeException $e) { check(true, $message); return; }
    throw new RuntimeException('Tidak ditolak: ' . $message);
}

// Upload folders -------------------------------------------------------------------------------
$work = sys_get_temp_dir() . '/inventory-hardening-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
try {
    $folder = $work . '/inventaris-barang/sarpras';
    PhotoStorage::protect($folder);
    $rules = (string) file_get_contents($folder . '/.htaccess');
    check(is_dir($folder) && (fileperms($folder) & 0777) === 0700, 'folder unggahan dibuat hanya untuk pengguna web server');
    check(str_contains($rules, 'Require all denied') && str_contains($rules, 'Deny from all') && str_contains($rules, 'Options -Indexes'), 'folder unggahan menolak akses browser dengan aturannya sendiri');
    check(!file_exists($work . '/inventaris-barang/.htaccess'), 'perlindungan tidak bergantung pada aturan folder induk');
    PhotoStorage::protect($folder);
    check(file_get_contents($folder . '/.htaccess') === $rules, 'folder yang sudah terlindungi diterima apa adanya');

    file_put_contents($folder . '/.htaccess', "Require all granted\n");
    rejects(static fn () => PhotoStorage::protect($folder), 'aturan folder yang diubah ditolak');

    mkdir($work . '/target', 0700);
    symlink($work . '/target', $work . '/link');
    rejects(static fn () => PhotoStorage::protect($work . '/link'), 'folder berupa symbolic link ditolak');
} finally {
    foreach ([$work . '/inventaris-barang/sarpras/.htaccess', $work . '/link'] as $file) { if (is_file($file) || is_link($file)) unlink($file); }
    foreach ([$work . '/inventaris-barang/sarpras', $work . '/inventaris-barang', $work . '/target', $work] as $dir) { if (is_dir($dir)) rmdir($dir); }
}

// The host printed on labels --------------------------------------------------------------------
$host = static function (?string $header): string {
    if ($header === null) { unset($_SERVER['HTTP_HOST']); } else { $_SERVER['HTTP_HOST'] = $header; }
    return PublicLink::host();
};
foreach (['perpus.example.id', 'localhost:8080', '192.168.1.10', '[::1]:8080'] as $valid) {
    check($host($valid) === $valid, 'alamat ' . $valid . ' diterima untuk label');
}
check($host(null) === 'localhost', 'tanpa header Host, label memakai localhost');
foreach (['evil.example/path', 'perpus.example.id@evil.example', 'a b', 'perpus.example.id"><script>', "perpus.example.id\r\nX: y", '-perpus.example.id', 'perpus.example.id:99999999'] as $invalid) {
    rejects(static fn () => $host($invalid), 'alamat ' . json_encode($invalid) . ' ditolak untuk label');
}

// Links in the update notice --------------------------------------------------------------------
$github = new ReflectionMethod(UpdateCheck::class, 'github');
$github->setAccessible(true);
$release = 'https://github.com/klarasid/inven/releases/tag/v2.5.1';
check($github->invoke(null, $release) === $release, 'tautan rilis GitHub diteruskan');
foreach (['javascript:alert(1)', 'http://github.com/klarasid/inven', 'https://github.com.evil.example/x', 'https://evil.example/github.com/', 'https://github.com/x"onclick="y', null, ['https://github.com/x']] as $bad) {
    check($github->invoke(null, $bad) === null, 'tautan ' . json_encode($bad) . ' tidak diteruskan');
}
echo "ok   done\n";
