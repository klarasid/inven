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
$names = ['inventory_watch_inspections', 'inventory_watch_findings', 'inventory_items', 'inventory_locations', 'stock_take', 'setting', 'plugins'];
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
    $db->exec('CREATE TABLE inventory_locations (id INT PRIMARY KEY, room_name VARCHAR(255), slims_location_id VARCHAR(3) NULL) ENGINE=InnoDB');
    $db->exec("CREATE TABLE inventory_items (id INT PRIMARY KEY, location_id INT, item_name VARCHAR(255), item_code VARCHAR(150), item_condition ENUM('B','KB','RB')) ENGINE=InnoDB");
    $db->exec('CREATE TABLE inventory_watch_inspections (id INT PRIMARY KEY, kind VARCHAR(20), status VARCHAR(20), due_date DATE, snapshot LONGTEXT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE inventory_watch_findings (id INT PRIMARY KEY, status VARCHAR(20)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE stock_take (stock_take_id INT PRIMARY KEY, is_active INT) ENGINE=InnoDB');
    $db->prepare('INSERT INTO plugins VALUES (?, ?, ?)')->execute(['x', '{"version":"2.2.0","db_version":8}', '/srv/slims/plugins/inventaris-barang/inventory.plugin.php']);
    // Traps: none of this may ever leave the library.
    $db->exec("INSERT INTO inventory_locations VALUES (1, 'RUANG RAHASIA', 'P01'), (2, 'Ruang Baca', 'P01')");
    $db->exec("INSERT INTO inventory_items VALUES (1, 1, 'Brankas RAHASIA-ITEM', 'P01-INV-TRAP01', 'B'), (2, 1, 'Meja', 'P01-INV-000002', 'RB')");
    $today = date('Y-m-d');
    $db->exec("INSERT INTO inventory_watch_inspections VALUES (1, 'incidental', 'final', '$today', '{\"template_name\":\"Laporan kerusakan\",\"room_name\":\"RUANG RAHASIA\"}')");
    $db->exec("INSERT INTO inventory_watch_findings VALUES (1, 'open')");
    $db->exec('INSERT INTO stock_take VALUES (1, 1)');

    Telemetry::count('kir_pdf');
    Telemetry::count('kir_pdf');
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
    check(!str_contains($json, 'RAHASIA') && !str_contains($json, 'TRAP01') && !str_contains($json, 'Duplikat'), 'no room name, item name or item code ever appears in the report');
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
