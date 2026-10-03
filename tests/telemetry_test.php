<?php
declare(strict_types=1);
// The daily usage report: what it carries, what it must never carry, how often it goes and how
// switching it off works. Needs INVENTORY_TEST_DSN/USER/PASSWORD (a MySQL test database).
namespace SLiMS { class DB { public static \PDO $connection; public static function getInstance(): \PDO { return self::$connection; } } }
namespace {
use SLiMS\Plugins\Inventory\Telemetry;

function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL ' . $label); echo "ok   $label\n"; }
if (!getenv('INVENTORY_TEST_DSN')) { fwrite(STDERR, "Set INVENTORY_TEST_DSN, INVENTORY_TEST_USER and INVENTORY_TEST_PASSWORD.\n"); exit(1); }
define('SB', sys_get_temp_dir() . '/');
define('SWB', '/slims/');
define('SENAYAN_VERSION_TAG', 'v9.8.0');
require __DIR__ . '/../src/UpdateCheck.php';
require __DIR__ . '/../src/Telemetry.php';

$prefix = 'it_test_' . bin2hex(random_bytes(6)) . '_';
$names = ['inventory_member_locations', 'inventory_member_fixes', 'inventory_watch_inspections', 'inventory_watch_findings', 'inventory_items', 'inventory_locations', 'inventory_software', 'stock_take', 'setting', 'plugins'];
final class PrefixedConnection extends PDO {
    public array $names = []; public string $prefix = '';
    private function sql(string $sql): string { foreach ($this->names as $name) $sql = preg_replace('/(?<![A-Za-z0-9_.])' . $name . '\b/', $this->prefix . $name, $sql); return $sql; }
    public function exec(string $statement): int|false { return parent::exec($this->sql($statement)); }
    public function prepare(string $query, array $options = []): \PDOStatement|false { return parent::prepare($this->sql($query), $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): \PDOStatement|false { return $fetchMode === null ? parent::query($this->sql($query)) : parent::query($this->sql($query), $fetchMode, ...$args); }
}
$db = new PrefixedConnection(getenv('INVENTORY_TEST_DSN'), (string) getenv('INVENTORY_TEST_USER'), (string) getenv('INVENTORY_TEST_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->names = $names; $db->prefix = $prefix;
\SLiMS\DB::$connection = $db;
$sent = [];
Telemetry::$transport = static function (string $url, array $payload) use (&$sent): bool { $sent[] = [$url, $payload]; return true; };
$GLOBALS['sysconf'] = ['library_name' => 'Perpustakaan Uji Telemetri'];
$_SERVER['HTTP_HOST'] = 'perpus.example.id';
$_SERVER['HTTPS'] = 'on';

try {
    $db->exec('CREATE TABLE setting (setting_id INT AUTO_INCREMENT PRIMARY KEY, setting_name VARCHAR(30) UNIQUE, setting_value MEDIUMTEXT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE plugins (id VARCHAR(32) PRIMARY KEY, options TEXT, path TEXT) ENGINE=InnoDB');
    $db->exec("CREATE TABLE inventory_locations (id INT PRIMARY KEY, room_name VARCHAR(255), slims_location_id VARCHAR(3) NULL, area_m2 DECIMAL(10,2) NULL, room_functions VARCHAR(255) NOT NULL DEFAULT '') ENGINE=InnoDB");
    $db->exec("CREATE TABLE inventory_items (id INT PRIMARY KEY, location_id INT, item_name VARCHAR(255), item_code VARCHAR(150), item_condition ENUM('B','KB','RB'), category VARCHAR(30) NULL) ENGINE=InnoDB");
    $db->exec('CREATE TABLE inventory_software (id INT PRIMARY KEY, name VARCHAR(150)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE inventory_watch_inspections (id INT PRIMARY KEY, kind VARCHAR(20), status VARCHAR(20), due_date DATE, snapshot LONGTEXT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE inventory_watch_findings (id INT PRIMARY KEY, status VARCHAR(20)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE stock_take (stock_take_id INT PRIMARY KEY, is_active INT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE inventory_member_locations (id INT PRIMARY KEY, basis VARCHAR(12), value_key VARCHAR(100), label VARCHAR(100), location_code VARCHAR(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE inventory_member_fixes (id INT PRIMARY KEY, from_values TEXT, to_value VARCHAR(100), members MEDIUMTEXT, member_count INT, undone_at DATETIME NULL) ENGINE=InnoDB');
    $db->prepare('INSERT INTO plugins VALUES (?, ?, ?)')->execute(['x', '{"version":"2.2.0","db_version":8}', '/srv/slims/plugins/inventaris-barang/inventory.plugin.php']);
    // Traps: none of this may ever leave the library.
    $db->exec("INSERT INTO inventory_locations VALUES (1, 'RUANG RAHASIA', 'P01', 48.5, 'koleksi,baca'), (2, 'Ruang Baca', 'P01', NULL, '')");
    $db->exec("INSERT INTO inventory_items VALUES (1, 1, 'Brankas RAHASIA-ITEM', 'P01-INV-TRAP01', 'B', 'keamanan'), (2, 1, 'Meja', 'P01-INV-000002', 'RB', NULL)");
    $db->exec("INSERT INTO inventory_software VALUES (1, 'Aplikasi RAHASIA'), (2, 'SLiMS')");
    $db->prepare('INSERT INTO setting (setting_name, setting_value) VALUES (?, ?)')->execute(['inventory_sarpras', serialize(['locations' => ['P01' => ['sivitas' => 987654, 'bandwidth_mbps' => 4321.5]]])]);
    $today = date('Y-m-d');
    $db->exec("INSERT INTO inventory_watch_inspections VALUES (1, 'incidental', 'final', '$today', '{\"template_name\":\"Laporan kerusakan\",\"room_name\":\"RUANG RAHASIA\"}')");
    $db->exec("INSERT INTO inventory_watch_findings VALUES (1, 'open')");
    $db->exec('INSERT INTO stock_take VALUES (1, 1)');
    $db->exec("INSERT INTO inventory_member_locations VALUES (1, 'institution', 'prodi rahasia', 'Prodi RAHASIA', 'P01'), (2, 'institution', 'gizi', 'Gizi', 'P01'), (3, 'type', '1', 'Mahasiswa', 'P01')");
    $db->exec("INSERT INTO inventory_member_fixes VALUES (1, '[\"Prodi RAHASIA-LAMA\"]', 'Prodi RAHASIA', '[[\"M-TRAP01\",\"x\"]]', 777001, NULL), (2, '[]', 'Gizi', '[]', 2, NOW())");
    $db->prepare('INSERT INTO setting (setting_name, setting_value) VALUES (?, ?)')->execute(['inventory_sivitas', serialize(['default' => 'P01', 'excluded' => [4]])]);

    Telemetry::count('kir_pdf');
    Telemetry::count('kir_pdf');
    Telemetry::count('institution_merge');
    Telemetry::count('not_a_feature');
    try {
        $db->exec("INSERT INTO inventory_items VALUES (1, 1, 'Duplikat', 'P01-INV-TRAP01', 'B')");
    } catch (PDOException $error) {
        Telemetry::error('db', $error);
    }

    $report = Telemetry::report($db);
    $json = json_encode($report);
    check($report['library']['name'] === 'Perpustakaan Uji Telemetri' && $report['library']['site_url'] === 'https://perpus.example.id/slims/', 'the report names the library and its address');
    check($report['stats']['rooms'] === 2 && $report['stats']['items'] === 2 && $report['stats']['items_poor'] === 1 && $report['stats']['findings_open'] === 1 && $report['stats']['stock_take_active'] === 1, 'the report counts rooms, items, findings and the running stock take');
    check($report['features']['kir_pdf'] === 2 && !isset($report['features']['not_a_feature']) && $report['features']['damage_reports'] === 1, 'features are counted, unknown ones ignored');
    check($report['environment']['migration'] === 8 && $report['environment']['slims_version'] === 'v9.8.0', 'the report carries versions and the migration level');
    check($report['stats']['rooms_with_area'] === 1 && $report['stats']['rooms_with_functions'] === 1 && $report['stats']['items_categorized'] === 1 && $report['stats']['software'] === 2 && $report['stats']['facility_profiles'] === 1, 'the report counts how much of the Rekap Sarpras data is filled in');
    check($report['stats']['institutions_mapped'] === 2 && $report['stats']['member_types_mapped'] === 1 && $report['stats']['institution_fixes'] === 1
        && $report['features']['institution_merge'] === 1 && $report['features']['default_location_set'] === 1, 'the report counts how far members are placed at locations, and merges that stand');
    check(!str_contains($json, 'RAHASIA') && !str_contains($json, 'TRAP01') && !str_contains($json, 'Duplikat'), 'no room name, item name, item code, software name, Institusi or member id ever appears in the report');
    check(!str_contains($json, '777001') && !str_contains($json, '"P01"'), 'neither how many members a merge changed nor which location is the default is sent');
    $counts = json_encode([$report['stats'], $report['features']]);
    check(!str_contains($json, 'sivitas') && !str_contains($json, 'bandwidth') && !str_contains($counts, '987654') && !str_contains($counts, '4321'), 'no building or network figure ever appears in the report');
    check(count($report['errors']) === 1 && $report['errors'][0]['category'] === 'db' && $report['errors'][0]['count'] === 1, 'a database error is remembered');
    check(Telemetry::clean("Duplicate entry 'P01-INV-000012' for key 'inventory_items.PRIMARY' at /srv/www/slims/plugins/x.php line 12") === 'Duplicate entry ? for key ? at ? line ?', 'error messages lose quoted values, paths and numbers');
    check(Telemetry::clean('Gagal mengirim ke kepala@sekolah.sch.id') === 'Gagal mengirim ke ?', 'error messages lose e-mail addresses');

    check(Telemetry::sendIfDue($db) && count($sent) === 1, 'the first report is sent');
    check($sent[0][0] === 'https://panel.klaras.id/api/v1/telemetry/plugins/inventaris-barang' && $sent[0][1]['install_id'] === $report['install_id'], 'it goes to Klaras Panel with the installation id');
    check(Telemetry::report($db)['errors'] === [], 'errors are cleared once reported');
    check(!Telemetry::sendIfDue($db) && count($sent) === 1, 'no second report the same day');

    Telemetry::setEnabled($db, false);
    check(count($sent) === 2 && $sent[1][1] === ['install_id' => $report['install_id'], 'opted_out' => true], 'switching off tells Klaras once, with nothing but the id');
    $db->exec("UPDATE setting SET setting_value = REPLACE(setting_value, 's:9:\"last_sent\";i:', 's:9:\"last_sent\";i:1') WHERE setting_name = 'inventory_telemetry'");
    check(!Telemetry::sendIfDue($db) && count($sent) === 2, 'nothing is sent while switched off');
    Telemetry::setEnabled($db, false);
    check(count($sent) === 2, 'switching off twice tells Klaras only once');
    Telemetry::setEnabled($db, true);
    check(Telemetry::sendIfDue($db) && count($sent) === 3, 'switching back on sends a report again');
    echo "ok   done\n";
} finally {
    foreach ($names as $name) $db->exec('DROP TABLE IF EXISTS ' . $name);
}
}
