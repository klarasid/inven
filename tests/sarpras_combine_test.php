<?php

declare(strict_types=1);

require __DIR__ . '/../src/Sarpras.php';

use SLiMS\Plugins\Inventory\Sarpras;

$failures = [];
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$passed) $failures[] = $label;
};

// A location's recap with one ordinary aspect at the given level, and the shared software aspect.
$recap = static fn(?string $level, string $value = '-') => ['aspects' => [
    ['no' => 6, 'section' => 'TI', 'title' => 'Ketersediaan jaringan internet', 'name' => 'Jaringan internet', 'value' => $value, 'level' => $level, 'basis' => '', 'checks' => [], 'rows' => [], 'columns' => [], 'fix' => '', 'sources' => ['facility']],
    ['no' => 8, 'shared' => true, 'section' => 'TI', 'title' => 'Legalitas perangkat lunak', 'name' => 'Lisensi perangkat lunak', 'value' => '80% berlisensi resmi', 'level' => 'a', 'basis' => 'x', 'checks' => [], 'rows' => [['SLiMS', 'Open source']], 'columns' => ['Aplikasi', 'Lisensi'], 'fix' => '', 'sources' => ['software']],
]];
$combine = static function (array $levels) use ($recap): array {
    $recaps = [];
    $names = [];
    foreach ($levels as $code => $level) {
        $recaps[$code] = $recap($level, 'capaian ' . $code);
        $names[$code] = 'Kampus ' . $code;
    }
    return Sarpras::combine($recaps, $names);
};
$level = static fn(array $levels) => $combine($levels)['aspects'][0]['level'];

// The average, rounded to the nearest level with a half going up.
$check('rata-rata 3,5 menjadi Sangat baik', $level(['01' => 'a', '02' => 'b']) === 'a');
$check('rata-rata 2,5 menjadi Baik', $level(['01' => 'b', '02' => 'c']) === 'b');
$check('rata-rata 1,5 menjadi Cukup', $level(['01' => 'c', '02' => 'd']) === 'c');
$check('rata-rata di bawah 1,5 menjadi Kurang', $level(['01' => 'c', '02' => 'd', '03' => 'd']) === 'd');
$check('rata-rata 3,33 menjadi Baik', $level(['01' => 'a', '02' => 'b', '03' => 'b']) === 'b');
$check('semua lokasi punya data: capaian menyebut jumlahnya saja', $combine(['01' => 'a', '02' => 'b'])['aspects'][0]['value'] === 'Rata-rata 2 lokasi');

// Locations without data stay out of the average but are named beside it.
$mixed = $combine(['01' => 'a', '02' => null, '03' => 'd'])['aspects'][0];
$check('lokasi tanpa data tidak ikut dirata-rata', $mixed['level'] === 'b' && $mixed['value'] === 'Rata-rata 2 dari 3 lokasi');
$check('sebaran menyebut tiap kondisi dan lokasi tanpa data', $mixed['basis'] === 'Dari 3 lokasi: Sangat baik 1 · Kurang 1 · 1 belum ada data.');
$check('baris diurutkan dari kondisi terendah, tanpa data di akhir', array_column($mixed['rows'], 0) === ['Kampus 03', 'Kampus 01', 'Kampus 02']
    && $mixed['rows'][0] === ['Kampus 03', 'Kurang', 'capaian 03'] && $mixed['rows'][2][1] === 'Belum ada data');
$check('saran menunjuk lokasi dengan kondisi terendah', $mixed['targets'] === [['code' => '03', 'name' => 'Kampus 03']] && $mixed['sources'] === []);

$none = $combine(['01' => null, '02' => null]);
$check('tanpa data di semua lokasi berarti tanpa kondisi', $none['aspects'][0]['level'] === null && $none['aspects'][0]['value'] === 'Belum ada data'
    && $none['aspects'][0]['targets'] === []);
$check('ringkasan dihitung dari aspek gabungan', $none['summary'] === ['a' => 1, 'b' => 0, 'c' => 0, 'd' => 0, 'empty' => 1]);

// Codes that PHP turns into integer keys keep their names.
$numeric = $combine(['10' => 'd', '06' => 'a'])['aspects'][0];
$check('kode lokasi berupa angka tetap dikenali', $numeric['rows'][0][0] === 'Kampus 10' && $numeric['targets'][0]['code'] === '10');

$shared = $combine(['01' => 'd', '02' => 'd'])['aspects'][1];
$check('aspek bersama diambil apa adanya', $shared['value'] === '80% berlisensi resmi' && $shared['rows'] === [['SLiMS', 'Open source']] && $shared['sources'] === ['software']);

// Facility figures per location, from either stored format.
$old = ['sivitas' => 1200, 'bandwidth_mbps' => 100.0];
$check('format lama menjadi milik lokasi utama', Sarpras::profiles($old, '00') === ['00' => $old]);
$check('format lama tanpa lokasi tetap satu unit', Sarpras::profiles($old, '') === ['' => $old]);
$check('setting kosong tidak membuat profil', Sarpras::profiles([], '00') === []);
$new = ['locations' => ['00' => ['sivitas' => 5], '06' => ['sivitas' => 9]]];
$check('format baru dibaca apa adanya', Sarpras::profiles($new, '00') === $new['locations']);
$check('profil satu unit pindah ke lokasi utama saat ruangan diberi lokasi', Sarpras::profiles(['locations' => ['' => $old]], '06') === ['06' => $old]);
$check('profil lokasi utama tidak ditimpa profil lama', Sarpras::profiles(['locations' => ['' => $old, '06' => ['sivitas' => 9]]], '06') === ['06' => ['sivitas' => 9]]);

exit($failures ? 1 : 0);
