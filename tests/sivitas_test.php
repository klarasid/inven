<?php

declare(strict_types=1);
// Sivitas counted from SLiMS's active members, placed at library locations by Institusi, member
// type or a default; and mistyped Institusi values merged in SLiMS and put back. Runs on SQLite.

define('SB', sys_get_temp_dir() . '/');
require __DIR__ . '/../src/PhotoStorage.php';
require __DIR__ . '/../src/Sivitas.php';

use SLiMS\Plugins\Inventory\Sivitas;

function check(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'ok   ' . $label . PHP_EOL;
}
function rejects(callable $operation, string $label): void
{
    try { $operation(); } catch (RuntimeException $e) { check(true, $label); return; }
    throw new RuntimeException('Tidak ditolak: ' . $label);
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([
    'CREATE TABLE setting (setting_id INTEGER PRIMARY KEY, setting_name TEXT UNIQUE, setting_value TEXT)',
    'CREATE TABLE user (user_id INTEGER PRIMARY KEY, realname TEXT)',
    'CREATE TABLE mst_location (location_id TEXT PRIMARY KEY, location_name TEXT)',
    'CREATE TABLE inventory_locations (id INTEGER PRIMARY KEY, room_name TEXT, slims_location_id TEXT)',
    'CREATE TABLE mst_member_type (member_type_id INTEGER PRIMARY KEY, member_type_name TEXT)',
    'CREATE TABLE member (member_id TEXT PRIMARY KEY, member_type_id INTEGER, inst_name TEXT, is_pending INTEGER, expire_date TEXT, last_update TEXT)',
    'CREATE TABLE inventory_member_locations (id INTEGER PRIMARY KEY, basis TEXT, value_key TEXT, label TEXT, location_code TEXT, created_at TEXT, updated_at TEXT, UNIQUE (basis, value_key))',
    'CREATE TABLE inventory_member_fixes (id INTEGER PRIMARY KEY, from_values TEXT, to_value TEXT, members TEXT, member_count INTEGER, user_id INTEGER, created_at TEXT, undone_at TEXT)',
    "INSERT INTO user VALUES (1, 'Rina')",
    "INSERT INTO mst_location VALUES ('00', 'Perpustakaan Kampus 1 Tembalang'), ('04', 'Perpustakaan Kampus IV Blora'), ('07', 'Perpustakaan Kampus VII Purwokerto'), ('08', 'Perpustakaan Kampus VIII Purwokerto')",
    "INSERT INTO mst_member_type VALUES (1, 'Mahasiswa'), (2, 'Dosen'), (4, 'Luar')",
] as $sql) $db->exec($sql);
$today = date('Y-m-d');
$member = $db->prepare('INSERT INTO member VALUES (?, ?, ?, ?, ?, NULL)');
$people = [
    ['M1', 1, 'Keperawatan Blora Program Diploma Tiga', 0, '2099-01-01'],
    ['M2', 1, 'keperawatan  blora program diploma tiga', 0, '2099-01-01'],
    ['M3', 1, 'Keperawatan Blora Program Diploma Tiga', 1, '2099-01-01'],   // pending
    ['M4', 1, 'Keperawatan Blora Program Diploma Tiga', 0, '2000-01-01'],   // expired
    ['M5', 1, 'Terapi Gigi Program Sarjana Terapan', 0, '2099-01-01'],
    ['M6', 1, 'Terapi Gigi Program Sarjna Terapan', 0, '2099-01-01'],       // typo
    ['M7', 2, '', 0, '2099-01-01'],
    ['M8', 4, '', 0, '2099-01-01'],                                         // external
    ['M9', 1, 'Keperawatan Purwokerto Program Diploma Tiga', 0, $today],    // expires today: still active
];
foreach ($people as $row) $member->execute($row);

// A library that is one unit counts everyone, with nothing to map.
check(Sivitas::counts($db) === ['total' => 7, 'unmapped' => 0, 'single' => true, 'locations' => []], 'one unit: every active member counts, pending and expired ones do not');
Sivitas::saveSettings($db, ['excluded' => ['4']]);
check(Sivitas::forLocation($db) === 6, 'an excluded member type is not sivitas');
rejects(static fn () => Sivitas::saveSettings($db, ['excluded' => ['99']]), 'an unknown member type is refused');

// Several locations: members are placed by Institusi, then type, then the default.
$db->exec("INSERT INTO inventory_locations (room_name, slims_location_id) VALUES ('Ruang baca', '00'), ('Ruang baca', '04')");
$counts = Sivitas::counts($db);
check(!$counts['single'] && $counts['unmapped'] === 6 && $counts['locations'] === [], 'with several locations, nobody is placed until mapped');
Sivitas::map($db, 'institution', 'Keperawatan Blora Program Diploma Tiga', '04', '2026-10-03 10:00:00');
check(Sivitas::forLocation($db, '04') === 2, 'an Institusi is matched whatever its case and spacing');
Sivitas::map($db, 'type', '2', '00', '2026-10-03 10:00:00');
check(Sivitas::forLocation($db, '00') === 1, 'a member type places members with no mapped Institusi');
Sivitas::saveSettings($db, ['default' => '00', 'excluded' => ['4']]);
$counts = Sivitas::counts($db);
check($counts['locations'] === ['00' => 4, '04' => 2] && $counts['unmapped'] === 0 && $counts['total'] === 6, 'the default location takes the rest');
check(Sivitas::forLocation($db) === 6, 'the institution counts everyone');
Sivitas::map($db, 'institution', 'Keperawatan Blora Program Diploma Tiga', '', '2026-10-03 10:00:00');
check(Sivitas::forLocation($db, '04') === 0, 'an empty location removes the mapping');
rejects(static fn () => Sivitas::map($db, 'institution', 'X', '99', '2026-10-03 10:00:00'), 'an unknown location is refused');
rejects(static fn () => Sivitas::map($db, 'room', 'X', '00', '2026-10-03 10:00:00'), 'an unknown basis is refused');

// What there is to map.
$institutions = Sivitas::institutions($db);
$blora = array_values(array_filter($institutions, static fn ($row) => str_starts_with($row['key'], 'keperawatan blora')))[0];
check(count($blora['variants']) === 2 && $blora['members'] === 4 && $blora['active'] === 2, 'spellings of one Institusi are listed together, with all and active members');
check($blora['suggestion'] === '04', 'a location is suggested from the place its name shares with the Institusi');
$purwokerto = array_values(array_filter($institutions, static fn ($row) => str_contains($row['key'], 'purwokerto')))[0];
check($purwokerto['suggestion'] === null, 'no suggestion where two locations share the place');
$suggest = Sivitas::suggester(Sivitas::locations($db));
check($suggest('Kelas 7A') === null && $suggest('Perpustakaan Kampus') === null, 'common words and classes suggest nothing');
check(array_column(Sivitas::types($db), 'active', 'name') === ['Dosen' => 1, 'Luar' => 1, 'Mahasiswa' => 5], 'member types come with their active members');

// Mistyped Institusi.
$db->exec("INSERT INTO member VALUES ('S1', 1, 'Kelas 7A', 0, '2099-01-01', NULL), ('S2', 1, 'Kelas 7B', 0, '2099-01-01', NULL),
    ('S3', 1, 'D-III Keperawatan Semarang', 0, '2099-01-01', NULL), ('S4', 1, 'DIV Keperawatan Semarang', 0, '2099-01-01', NULL),
    ('S5', 1, 'D III Keperawatan Semarang', 0, '2099-01-01', NULL), ('S6', 1, 'Kelas A IPA', 0, '2099-01-01', NULL), ('S7', 1, 'Kelas B IPA', 0, '2099-01-01', NULL)");
$similar = Sivitas::similar($db);
$values = array_map(static fn ($group) => array_column($group['variants'], 'value'), $similar);
check(in_array(['Terapi Gigi Program Sarjana Terapan', 'Terapi Gigi Program Sarjna Terapan'], array_map(static function ($v) { sort($v); return $v; }, $values), true), 'a value a letter apart is offered as a typo');
check(!array_filter($values, static fn ($v) => in_array('Kelas 7A', $v, true)), 'values that differ in a class letter are left apart');
check(!array_filter($values, static fn ($v) => in_array('Kelas A IPA', $v, true)), 'values that differ in a one-letter word are left apart');
$keperawatan = array_values(array_filter($values, static fn ($v) => in_array('D-III Keperawatan Semarang', $v, true)))[0] ?? [];
check(in_array('D III Keperawatan Semarang', $keperawatan, true) && !in_array('DIV Keperawatan Semarang', $keperawatan, true), 'D-III and D III are one programme; DIV is another');
check(array_filter($similar, static fn ($group) => in_array('keperawatan  blora program diploma tiga', array_column($group['variants'], 'value'), true)) !== [], 'spellings that differ in case and spacing are offered too');

Sivitas::map($db, 'institution', 'Terapi Gigi Program Sarjna Terapan', '00', '2026-10-03 10:00:00');
$fix = Sivitas::merge($db, ['Terapi Gigi Program Sarjna Terapan'], 'Terapi Gigi Program Sarjana Terapan', 1, '2026-10-03 11:00:00');
check($fix['count'] === 1 && $db->query("SELECT inst_name FROM member WHERE member_id='M6'")->fetchColumn() === 'Terapi Gigi Program Sarjana Terapan', 'a merge rewrites the Institusi in SLiMS');
check(Sivitas::maps($db)['institution']['terapi gigi program sarjana terapan'] === '00', 'the merged value keeps the location its spelling had');
check(Sivitas::fixes($db)[0]['by'] === 'Rina' && Sivitas::fixes($db)[0]['count'] === 1, 'the merge is kept with who made it');
// A member edited since the merge keeps the edit.
$db->exec("INSERT INTO member VALUES ('M10', 1, 'Terapi Gigi Program Sarjna Terapan', 0, '2099-01-01', NULL)");
$fix2 = Sivitas::merge($db, ['Terapi Gigi Program Sarjna Terapan'], 'Terapi Gigi Program Sarjana Terapan', 1, '2026-10-03 11:05:00');
$db->exec("UPDATE member SET inst_name='Gizi' WHERE member_id='M10'");
check(Sivitas::undo($db, $fix['id'], '2026-10-03 12:00:00') === 1 && $db->query("SELECT inst_name FROM member WHERE member_id='M6'")->fetchColumn() === 'Terapi Gigi Program Sarjna Terapan', 'an undo puts the old spelling back');
check(Sivitas::undo($db, $fix2['id'], '2026-10-03 12:00:00') === 0 && $db->query("SELECT inst_name FROM member WHERE member_id='M10'")->fetchColumn() === 'Gizi', 'an undo leaves members edited since alone');
rejects(static fn () => Sivitas::undo($db, $fix['id'], '2026-10-03 12:00:00'), 'a merge is undone once');
rejects(static fn () => Sivitas::merge($db, ['Tidak ada'], 'Gizi', 1, '2026-10-03 12:00:00'), 'a spelling no member has is refused');
rejects(static fn () => Sivitas::merge($db, ['Gizi'], '  ', 1, '2026-10-03 12:00:00'), 'an empty target is refused');
echo "ok   done\n";
