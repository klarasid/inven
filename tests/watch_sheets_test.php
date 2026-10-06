<?php

declare(strict_types=1);

// The schedule sheet and the checklist sheet: what each prints.
require __DIR__ . '/../src/WatchSheets.php';

use SLiMS\Plugins\Inventory\WatchSheets;

function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL ' . $label); echo "ok   $label\n"; }

$schedule = static fn (int $room, string $name, string $library, string $frequency, ?string $end, string $assignee): array => [
    'location_id' => $room, 'frequency' => $frequency, 'start_date' => '2026-01-05', 'end_date' => $end, 'assignee_name' => $assignee,
    'snapshot' => json_encode(['room_name' => $name, 'library_name' => $library, 'template_name' => 'Checklist <ruang>']),
];
$html = WatchSheets::schedules([
    $schedule(1, 'Ruang Baca', 'Perpustakaan Pusat', 'monthly', null, 'Rina'),
    $schedule(1, 'Ruang Baca', 'Perpustakaan Pusat', 'yearly', '2026-12-31', 'Dimas'),
    $schedule(2, 'Ruang Referensi', '', 'weekly', null, 'Rina'),
], ['printed_by' => 'Rina', 'rooms' => 5]);
check(str_contains($html, 'JADWAL PEMERIKSAAN SARANA DAN PRASARANA') && substr_count($html, 'Ruang Baca') === 2 && str_contains($html, 'Ruang Referensi'), 'tiap jadwal yang berjalan tercantum dengan ruangannya');
check(str_contains($html, '2 dari 5 ruangan') && str_contains($html, '>3<'), 'ruangan terjadwal dihitung sekali walau jadwalnya lebih dari satu');
check(str_contains($html, 'Bulanan') && str_contains($html, 'Tanpa batas') && str_contains($html, '31 Desember 2026') && str_contains($html, 'Checklist &lt;ruang&gt;'), 'frekuensi, masa berlaku, dan checklist dicetak; teks di-escape');
check(str_contains(WatchSheets::schedules([], ['rooms' => 5]), 'Belum ada jadwal pemeriksaan rutin yang berjalan.') && str_contains(WatchSheets::schedules([], ['rooms' => 5]), '0 dari 5 ruangan'), 'tanpa jadwal, lembarnya mengatakan demikian');

$forms = WatchSheets::checklists([
    ['name' => 'Checklist keamanan', 'items' => json_encode([
        ['group' => 'Keselamatan', 'object' => 'APAR', 'instruction' => 'Periksa tekanan & segel <pin>.'],
        ['group' => 'Keselamatan', 'object' => 'Jalur evakuasi', 'instruction' => ''],
    ])],
    ['name' => 'Checklist kosong', 'items' => []],
], ['printed_by' => 'Rina']);
check(str_contains($forms, 'Checklist keamanan (2 butir)') && str_contains($forms, 'APAR') && str_contains($forms, 'Periksa tekanan &amp; segel &lt;pin&gt;.'), 'tiap checklist dicetak dengan butirnya; petunjuk di-escape');
check(str_contains($forms, 'Baik / perlu tindakan / tidak diperiksa / tidak berlaku') && substr_count($forms, '>Hasil<') === 1 && substr_count($forms, '>Catatan<') === 1, 'lembar menyediakan kolom hasil dan catatan, dan menyebut pilihan hasilnya');
check(str_contains($forms, 'Checklist ini belum memiliki butir.') && str_contains(WatchSheets::checklists([]), 'Belum ada checklist.'), 'checklist tanpa butir, atau tanpa checklist, dikatakan demikian');
echo "ok   done\n";
