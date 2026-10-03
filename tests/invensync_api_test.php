<?php
declare(strict_types=1);
// Klaras InvenSync API against a real MySQL schema: guard, staff sign-in and tokens, replay of
// offline changes, items, stock take scans and the supervision workflow.
// Needs INVENTORY_TEST_DSN/USER/PASSWORD and SLIMS_CONNECT_DIR (a checkout of SLiMS Connect).
namespace SLiMS { class DB { public static \PDO $connection; public static function getInstance(): \PDO { return self::$connection; } } }
namespace {
use SLiMS\Plugins\Inventory\Api\Http;
use SLiMS\Plugins\Inventory\Api\Licence;
use SLiMS\Plugins\Inventory\Api\Routes;
use SLiMS\Plugins\Inventory\Api\AgentCodes;
use SLiMS\Plugins\Inventory\Api\Guard;
use SLiMS\Plugins\Inventory\PhotoStorage;
use SLiMS\Plugins\Inventory\Supervision;
use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Request;
use SlimsConnect\Support\Db;
use SlimsConnect\Support\Settings;

function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException('FAIL ' . $label); echo "ok   $label\n"; }
foreach (['INVENTORY_TEST_DSN', 'SLIMS_CONNECT_DIR'] as $env) {
    if (!getenv($env)) { fwrite(STDERR, "Set INVENTORY_TEST_DSN, INVENTORY_TEST_USER, INVENTORY_TEST_PASSWORD and SLIMS_CONNECT_DIR.\n"); exit(1); }
}
$prefix = 'iv_test_' . bin2hex(random_bytes(6)) . '_';
define('SB', sys_get_temp_dir() . '/' . $prefix . 'sb/');
define('AWB', '/admin/'); define('SWB', '/'); define('FLS', 'files'); define('DS', DIRECTORY_SEPARATOR);
require getenv('SLIMS_CONNECT_DIR') . '/src/autoload.php';
spl_autoload_register(static function (string $class): void {
    $lib = dirname(__DIR__, 3) . '/lib';
    foreach (['OTPHP\\' => '/spomky-labs/otphp/src/', 'ParagonIE\\ConstantTime\\' => '/paragonie/constant_time_encoding/src/'] as $ns => $dir) {
        if (str_starts_with($class, $ns)) { require $lib . $dir . str_replace('\\', '/', substr($class, strlen($ns))) . '.php'; }
    }
});
require dirname(__DIR__, 3) . '/lib/Migration/Migration.php';
foreach (glob(__DIR__ . '/../migration/*.php') as $migration) require $migration;
require __DIR__ . '/../src/Api/bootstrap.php';

$names = ['inventory_api_codes', 'inventory_room_plans', 'inventory_room_areas', 'inventory_software', 'inventory_api_idempotency', 'inventory_api_sessions', 'inventory_watch_photos', 'inventory_watch_events', 'inventory_watch_actions', 'inventory_watch_findings', 'inventory_watch_results', 'inventory_watch_inspections', 'inventory_watch_schedules', 'inventory_watch_templates', 'inventory_item_code_reservations', 'inventory_item_code_sequences', 'inventory_item_photos', 'inventory_items', 'inventory_locations', 'stock_take_item', 'stock_take', 'mst_item_status', 'item', 'mst_location', 'group_access', 'mst_module', 'setting', 'user', 'holiday', 'slims_connect_rate_limits', 'slims_connect_settings'];
final class PrefixedConnection extends PDO {
    public array $names = []; public string $prefix = '';
    private function sql(string $sql): string { foreach ($this->names as $name) $sql = preg_replace('/(?<![A-Za-z0-9_.])' . $name . '\b/', $this->prefix . $name, $sql); return $sql; }
    public function exec(string $statement): int|false { return parent::exec($this->sql($statement)); }
    public function prepare(string $query, array $options = []): \PDOStatement|false { return parent::prepare($this->sql($query), $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): \PDOStatement|false { return $fetchMode === null ? parent::query($this->sql($query)) : parent::query($this->sql($query), $fetchMode, ...$args); }
}
$db = new PrefixedConnection(getenv('INVENTORY_TEST_DSN'), (string) getenv('INVENTORY_TEST_USER'), (string) getenv('INVENTORY_TEST_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->names = $names; $db->prefix = $prefix;
\SLiMS\DB::$connection = $db; Db::useConnection($db);
Http::$storage = new PhotoStorage(sys_get_temp_dir() . '/' . $prefix . 'photos');
$verdict = ['state' => 'active', 'features' => ['invensync' => true], 'limits' => ['invensync_staff' => null]];
Licence::$verdict = static function () use (&$verdict): array { return $verdict; };

/** One request through the API's dispatcher, as the router would send it. */
function call(string $method, string $action, array $body = [], array $params = [], array $headers = [], array $query = [], bool $https = true): array {
    [$class, $name] = explode('@', $action);
    $server = ['REQUEST_METHOD' => $method, 'REMOTE_ADDR' => '10.0.0.7'] + ($https ? ['HTTPS' => 'on'] : []);
    foreach ($headers as $header => $value) $server['HTTP_' . strtoupper(str_replace('-', '_', $header))] = $value;
    $public = in_array($action, ['AuthController@discovery', 'AuthController@issue'], true);
    // Routes that change nothing although they are not GET, as Routes::register marks them.
    $options = in_array($action, ['AuthController@revoke', 'ScheduleController@preview'], true) ? ['write' => false] : [];
    try {
        $response = Http::dispatch(new Request($body, $query, $server), [new ('SLiMS\\Plugins\\Inventory\\Api\\' . $class)(), $name], $params, ['public' => $public, 'route' => $method . ' ' . $action] + $options);
        return $response instanceof JsonResponse ? ['status' => $response->status, 'body' => $response->body] : ['status' => 200, 'body' => $response];
    } catch (ApiException $e) {
        return ['status' => $e->status, 'code' => $e->errorCode, 'message' => $e->getMessage(), 'details' => $e->details];
    }
}
function bearer(string $token): array { return ['Authorization' => 'Bearer ' . $token]; }
function login(string $username, string $password, array $extra = []): array {
    return call('POST', 'AuthController@issue', $extra + ['grant_type' => 'password', 'username' => $username, 'password' => $password, 'device_name' => 'Uji', 'remember' => true]);
}

$photos = sys_get_temp_dir() . '/' . $prefix . 'photos';
try {
    // SLiMS core tables, as far as the API reads them.
    $db->exec("CREATE TABLE user (user_id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) UNIQUE, realname VARCHAR(100), passwd VARCHAR(64), is_active ENUM('0','1') DEFAULT '1', `2fa` TEXT NULL, groups VARCHAR(200), last_update DATE NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec('CREATE TABLE mst_module (module_id INT PRIMARY KEY, module_path VARCHAR(200)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('CREATE TABLE group_access (group_id INT, module_id INT, menus LONGTEXT NULL, r INT(1), w INT(1)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('CREATE TABLE mst_location (location_id VARCHAR(3) PRIMARY KEY, location_name VARCHAR(100)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('CREATE TABLE setting (setting_id INT AUTO_INCREMENT PRIMARY KEY, setting_name VARCHAR(30) UNIQUE, setting_value MEDIUMTEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('CREATE TABLE holiday (holiday_id INT AUTO_INCREMENT PRIMARY KEY, holiday_dayname VARCHAR(20), holiday_date DATE NULL, description VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('CREATE TABLE stock_take (stock_take_id INT AUTO_INCREMENT PRIMARY KEY, stock_take_name VARCHAR(200), start_date DATETIME, end_date DATETIME NULL, init_user VARCHAR(50), total_item_stock_taked INT, total_item_lost INT, total_item_exists INT, total_item_loan INT, stock_take_users MEDIUMTEXT NULL, is_active INT(1), report_file VARCHAR(255) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec("CREATE TABLE stock_take_item (stock_take_id INT, item_id INT, item_code VARCHAR(20) UNIQUE, title VARCHAR(255), gmd_name VARCHAR(30), classification VARCHAR(30), coll_type_name VARCHAR(30), call_number VARCHAR(50), location VARCHAR(100), status ENUM('e','m','u','l'), checked_by VARCHAR(50) NULL, last_update DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec('CREATE TABLE mst_item_status (item_status_id CHAR(3) PRIMARY KEY, skip_stock_take INT(1)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('CREATE TABLE item (item_id INT PRIMARY KEY, item_code VARCHAR(20), item_status_id CHAR(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('CREATE TABLE slims_connect_rate_limits (rate_key CHAR(64) PRIMARY KEY, attempts INT, window_started_at DATETIME, blocked_until DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('CREATE TABLE slims_connect_settings (name VARCHAR(64) PRIMARY KEY, value TEXT, updated_at DATETIME) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    foreach (['CreateInventoryTables', 'AddSlimsLocationToInventoryLocations', 'CreateInventoryItemPhotos', 'MoveInventoryPhotosToFiles', 'AllowSharedInventoryLocationCodes', 'CreateInventoryItemCodes', 'CreateInventorySupervision', 'CreateInvensyncApi', 'AddFacilityProfileData', 'AllowSeveralItemCategories', 'CreateRoomAreasAndPlans', 'AllowAgentConnections'] as $migration) (new $migration())->up();
    (new CreateInvensyncApi())->up();
    check(true, 'migration 8 is repeatable');

    // Bound, not inline: the test connection renames table names that appear in SQL text.
    $db->prepare('INSERT INTO mst_module VALUES (1, ?), (2, ?)')->execute(['stock_take', 'system']);
    $db->exec('INSERT INTO group_access (group_id,module_id,r,w) VALUES (1,1,1,1),(2,1,1,0),(3,2,1,1)');
    $hash = password_hash('rahasia', PASSWORD_BCRYPT);
    $insert = $db->prepare('INSERT INTO user (username, realname, passwd, is_active, `2fa`, groups) VALUES (?, ?, ?, ?, ?, ?)');
    $insert->execute(['rina', 'Rina Wulandari', $hash, '1', null, serialize(['1'])]);
    $insert->execute(['dimas', 'Dimas Prasetyo', $hash, '1', null, serialize(['1'])]);
    $insert->execute(['baca', 'Petugas Baca', $hash, '1', null, serialize(['2'])]);
    $insert->execute(['sistem', 'Admin Sistem', $hash, '1', null, serialize(['3'])]);
    $insert->execute(['lama', 'Akun Lama', md5('rahasia'), '1', null, serialize(['1'])]);
    $insert->execute(['nonaktif', 'Akun Nonaktif', $hash, '0', null, serialize(['1'])]);
    $secret = \OTPHP\TOTP::generate()->getSecret();
    $insert->execute(['aman', 'Akun Aman', $hash, '1', $secret, serialize(['1'])]);
    $db->exec("INSERT INTO mst_location VALUES ('P01','Perpustakaan Pusat')");
    $db->exec("INSERT INTO inventory_locations (id, slims_location_id, location_code, room_name, created_at, updated_at) VALUES (1,'P01','P01-RUANG-001','Ruang baca umum',NOW(),NOW()),(2,'P01','P01-RUANG-002','Ruang referensi',NOW(),NOW())");
    $db->exec("INSERT INTO inventory_items (id, location_id, item_name, brand_model, item_code, item_condition, created_at, updated_at) VALUES (17,1,'Komputer OPAC','Lenovo M70','P01-INV-000017','KB',NOW(),'2026-09-01 10:00:00'),(18,1,'Meja baca','','P01-INV-000018','B',NOW(),NOW())");

    // Guard, in the order a library meets it.
    check(call('GET', 'AuthController@discovery')['code'] === 'invensync_disabled', 'the app stays off until the administrator allows it');
    Guard::setEnabled($db, true);
    $verdict['state'] = 'unconfigured';
    check(call('GET', 'AuthController@discovery')['code'] === 'klaras_not_linked', 'a SLiMS not linked to Klaras Panel is told so');
    $verdict = ['state' => 'active', 'features' => ['invensync' => false], 'limits' => []];
    check(call('GET', 'AuthController@discovery')['code'] === 'invensync_not_licensed', 'a plan without InvenSync is refused');
    $verdict['features']['invensync'] = true;
    Settings::set(Settings::REQUIRE_HTTPS, '1');
    check(call('GET', 'AuthController@discovery', https: false)['code'] === 'https_required', 'plain http is refused');
    check(call('GET', 'AuthController@discovery')['body']['data']['api_version'] === 1, 'discovery answers once everything holds');

    // Signing in.
    $wrong = login('rina', 'salah');
    $unknown = login('siapa', 'salah');
    check($wrong['code'] === 'invalid_credentials' && $unknown['code'] === 'invalid_credentials' && $wrong['message'] === $unknown['message'], 'a wrong password and an unknown user get the same answer');
    check(login('sistem', 'rahasia')['code'] === 'no_stock_take_access', 'an account without Stock Take cannot sign in');
    check(login('nonaktif', 'rahasia')['code'] === 'account_inactive', 'an inactive account cannot sign in');
    check(login('aman', 'rahasia')['code'] === 'otp_required', 'an account with two-step verification is asked for its code');
    check(login('aman', 'rahasia', ['otp' => '000000'])['code'] === 'invalid_otp', 'a wrong one-time code is refused');
    $aman = login('aman', 'rahasia', ['otp' => \OTPHP\TOTP::createFromSecret($secret)->now()]);
    check($aman['status'] === 200, 'the right one-time code signs in');
    $lama = login('lama', 'rahasia');
    check($lama['status'] === 200 && str_starts_with((string) $db->query("SELECT passwd FROM user WHERE username='lama'")->fetchColumn(), '$2y$'), 'an MD5 password signs in and is upgraded to bcrypt');
    for ($i = 0; $i < 5; $i++) login('dimas', 'salah');
    check(login('dimas', 'rahasia')['code'] === 'rate_limited', 'five wrong passwords lock the username for a while');
    $db->exec('DELETE FROM slims_connect_rate_limits');

    $rina = login('rina', 'rahasia');
    $token = $rina['body']['data']['access_token'];
    $refresh = $rina['body']['data']['refresh_token'];
    check(str_starts_with($token, 'isa_') && str_starts_with($refresh, 'isr_') && $rina['body']['data']['staff']['initials'] === 'RW', 'sign-in returns tokens and the librarian');
    check(!str_contains((string) $db->query('SELECT CONCAT(access_hash, refresh_hash) FROM inventory_api_sessions ORDER BY id DESC LIMIT 1')->fetchColumn(), explode('.', $token)[1]), 'only hashes of the tokens are stored');
    check(call('GET', 'AuthController@me', headers: bearer($token))['body']['data']['name'] === 'Rina Wulandari', 'the access token reaches the API');
    check(call('GET', 'AuthController@me')['code'] === 'unauthenticated', 'no token, no data');
    $notRemembered = login('dimas', 'rahasia', ['remember' => false]);
    check($notRemembered['body']['data']['refresh_token'] === null, 'without "Ingat saya" there is no refresh token');

    $rotated = call('POST', 'AuthController@issue', ['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
    check($rotated['status'] === 200 && $rotated['body']['data']['refresh_token'] !== $refresh, 'a refresh token rotates both tokens');
    check(call('GET', 'AuthController@me', headers: bearer($token))['code'] === 'unauthenticated', 'the old access token stops working after rotation');
    $reuse = call('POST', 'AuthController@issue', ['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
    check($reuse['code'] === 'refresh_reused' && call('GET', 'AuthController@me', headers: bearer($rotated['body']['data']['access_token']))['code'] === 'unauthenticated', 'reusing an old refresh token ends the whole session');

    $rina = login('rina', 'rahasia');
    $token = $rina['body']['data']['access_token'];
    $db->prepare("UPDATE user SET passwd = ? WHERE username = 'lama'")->execute([password_hash('baru', PASSWORD_BCRYPT)]);
    check(call('GET', 'AuthController@me', headers: bearer($lama['body']['data']['access_token']))['code'] === 'unauthenticated', 'changing the password in SLiMS ends app sessions');

    $baca = login('baca', 'rahasia')['body']['data']['access_token'];
    check(call('POST', 'ItemController@store', ['room_id' => 1, 'name' => 'Kursi'], headers: bearer($baca))['code'] === 'read_only', 'a read-only librarian cannot change data');

    $verdict['limits']['invensync_staff'] = 3;
    check(login('dimas', 'rahasia')['status'] === 200, 'an already signed-in librarian does not take a second seat');
    $db->exec("UPDATE user SET is_active='1' WHERE username='nonaktif'");
    check(login('nonaktif', 'rahasia')['code'] === 'staff_limit_reached', 'the plan limits librarians signed in at once');
    $verdict['limits']['invensync_staff'] = null;

    // Items.
    $rooms = call('GET', 'RoomController@index', headers: bearer($token))['body']['data'];
    check(count($rooms['rooms']) === 2 && $rooms['rooms'][0]['conditions']['KB'] === 1 && $rooms['libraries'][0]['code'] === 'P01', 'rooms come with condition counts and libraries');
    $room = call('GET', 'RoomController@items', params: ['id' => 1], headers: bearer($token))['body']['data'];
    check(count($room['items']) === 2 && $room['room']['item_count'] === 2, 'a room lists its items');

    $formToken = bin2hex(random_bytes(32));
    $code = call('POST', 'ItemController@reserveCode', ['room_id' => 1, 'code_token' => $formToken], headers: bearer($token))['body']['data']['code'];
    check($code === 'P01-INV-000019', 'the next item code is reserved for the form');
    $new = ['room_id' => 1, 'name' => 'Rak buku besi', 'brand' => 'Lion L-502', 'code' => $code, 'code_token' => $formToken, 'year' => '2019', 'quantity' => '12 unit', 'price' => '2100000', 'condition' => 'B'];
    $key = ['Idempotency-Key' => 'item-' . bin2hex(random_bytes(8))];
    $created = call('POST', 'ItemController@store', $new, headers: bearer($token) + $key);
    $again = call('POST', 'ItemController@store', $new, headers: bearer($token) + $key);
    check($created['status'] === 201 && $again['status'] === 201 && $again['body']['data']['item']['id'] === $created['body']['data']['item']['id'], 'a change sent twice with the same key is applied once');
    check((int) $db->query('SELECT COUNT(*) FROM inventory_items')->fetchColumn() === 3, 'the replay created no second item');
    check(call('POST', 'ItemController@store', ['room_id' => 1, 'name' => ''], headers: bearer($token))['code'] === 'rejected', 'an item without a name is refused with the service message');

    $stale = call('PATCH', 'ItemController@update', ['condition' => 'RB', 'updated_at' => '2026-01-01 00:00:00'], ['id' => 17], bearer($token));
    check($stale['code'] === 'stale_version' && $stale['details']['item']['condition'] === 'KB', 'an edit made on an old copy is refused with the current one');
    $updated = call('PATCH', 'ItemController@update', ['condition' => 'RB', 'updated_at' => '2026-09-01 10:00:00'], ['id' => 17], bearer($token));
    check($updated['body']['data']['item']['condition'] === 'RB' && $updated['body']['data']['item']['brand'] === 'Lenovo M70', 'an edit changes only the fields sent');

    $link = 'https://perpus.example.id/index.php?' . \SLiMS\Plugins\Inventory\PublicLink::query($db, 18);
    check(call('GET', 'ItemController@lookup', headers: bearer($token), query: ['qr' => $link])['body']['data']['item']['id'] === 18, 'a scanned label finds its item');
    check(call('GET', 'ItemController@lookup', headers: bearer($token), query: ['qr' => 'https://x/index.php?p=info_barang&i=18&t=000000000000'])['code'] === 'invalid_label', 'a label with a forged signature is refused');
    check(call('GET', 'ItemController@lookup', headers: bearer($token), query: ['code' => 'P01-INV-000017'])['body']['data']['room']['id'] === 1, 'a typed code finds its item');

    // Stock take.
    $db->exec("INSERT INTO stock_take VALUES (1,'Stock opname 2026','2026-09-15 08:00:00',NULL,'Rina',4,3,0,1,NULL,1,NULL)");
    $db->exec("INSERT INTO stock_take_item (stock_take_id,item_id,item_code,title,call_number,location,status) VALUES (1,1,'B00412','Laskar pelangi','899 HIR l','Rak 1','m'),(1,2,'B01877','Bumi manusia','899 PRA b','Rak 2','m'),(1,3,'B00093','Filosofi teras','158 MAN f','Rak 3','m'),(1,4,'B02210','Cantik itu luka','899 KUR c','Rak 2','l')");
    $scans = call('POST', 'StockTakeController@scan', ['scans' => [['code' => 'B00412', 'client_id' => 'a'], ['code' => 'B00412'], ['code' => 'B02210'], ['code' => 'X99001']]], ['id' => 1], bearer($token));
    $outcomes = array_column($scans['body']['data']['results'], 'outcome');
    check($outcomes === ['found', 'already', 'on_loan', 'unknown'], 'scans report found, repeated, on loan and unknown copies');
    $session = $scans['body']['data']['session'];
    check($session['found'] === 1 && $session['missing'] === 2 && $session['participants'] === ['Rina Wulandari'], 'the session counts the copy and the scanner');
    check((int) $db->query('SELECT total_item_exists FROM stock_take WHERE stock_take_id=1')->fetchColumn() === 1, 'the session totals move as SLiMS moves them');
    $missing = call('GET', 'StockTakeController@items', params: ['id' => 1], headers: bearer($token), query: ['status' => 'missing']);
    check($missing['body']['meta']['pagination']['total'] === 2, 'the missing list is paginated');
    $codes = call('GET', 'StockTakeController@codes', params: ['id' => 1], headers: bearer($token))['body']['data'];
    check(count($codes) === 4 && $codes[0] === ['B00093', 'missing'], 'codes come in order for offline checks');
    $pdf = static fn (array $response): bool => $response['body'] instanceof \SLiMS\Plugins\Inventory\Api\BytesResponse && str_starts_with($response['body']->bytes, '%PDF');
    check($pdf(call('GET', 'RoomController@kir', params: ['id' => 1], headers: bearer($token))), 'a room\'s KIR comes as PDF');
    check($pdf(call('GET', 'ItemController@label', params: ['id' => 18], headers: bearer($token))), 'an item label comes as PDF');
    check($pdf(call('GET', 'StockTakeController@document', params: ['id' => 1], headers: bearer($token))), 'the missing list comes as PDF');
    $db->exec('UPDATE stock_take SET is_active=0');
    check(call('POST', 'StockTakeController@scan', ['scans' => ['B01877']], ['id' => 1], bearer($token))['code'] === 'session_closed', 'a closed session takes no scans');

    // Supervision workflow.
    $watch = new Supervision($db, Http::$storage);
    $template = $watch->mutate('template', ['name' => 'Checklist sarana v2', 'items' => [['group' => 'Sarana', 'object' => 'Komputer OPAC', 'instruction' => 'Nyalakan'], ['group' => 'Prasarana', 'object' => 'Pencahayaan', 'instruction' => 'Lampu menyala']]], [], 1)['template_id'];
    $today = date('Y-m-d');
    $watch->mutate('schedule', ['location_id' => 1, 'template_id' => $template, 'mapping' => [0 => 17], 'frequency' => 'daily', 'start_date' => $today, 'end_date' => $today, 'assignee_id' => 1], [], 1);
    $watch->mutate('sync', [], [], 1);
    $tasks = call('GET', 'TaskController@index', headers: bearer($token), query: ['tab' => 'inspections']);
    check(count($tasks['body']['data']) === 1 && $tasks['body']['data'][0]['room']['name'] === 'Ruang baca umum', 'my inspections are listed');
    $id = $tasks['body']['data'][0]['id'];
    $doc = call('GET', 'TaskController@inspection', params: ['id' => $id], headers: bearer($token))['body']['data'];
    [$first, $second] = array_column($doc['results'], 'id');
    check($doc['results'][0]['item']['id'] === 17, 'a checklist row names the item it covers');
    $final = ['version' => $doc['version'], 'submit_mode' => 'final', 'results' => [$first => ['outcome' => 'action', 'notes' => 'Satu unit tidak menyala', 'assignee_id' => 2, 'priority' => 'medium', 'deadline' => $today], $second => ['outcome' => 'good']]];
    check(call('PUT', 'TaskController@saveInspection', ['version' => 99] + $final, ['id' => $id], bearer($token))['code'] === 'stale_version', 'saving over a newer version is refused');
    $saved = call('PUT', 'TaskController@saveInspection', $final, ['id' => $id], bearer($token));
    check($saved['body']['data']['status'] === 'final' && $saved['body']['data']['results'][0]['finding']['status'] === 'open', 'finalizing turns "Perlu tindakan" into a finding');
    $history = call('GET', 'ItemController@show', params: ['id' => 17], headers: bearer($token))['body']['data']['history'];
    check(($history[0]['title'] ?? '') === 'Pemeriksaan rutin • Perlu tindakan', 'the item shows the check in its history');

    $findingId = $saved['body']['data']['results'][0]['finding']['id'];
    $draft = call('POST', 'TaskController@finding', ['version' => 1, 'mode' => 'draft', 'kind' => 'repair', 'description' => 'Power supply diganti'], ['id' => $findingId], bearer($dimas ?? login('dimas', 'rahasia')['body']['data']['access_token']));
    $work = $draft['body']['data']['inspection']['results'][0]['finding']['work'] ?? null;
    check(($work['description'] ?? '') === 'Power supply diganti' && $work['submitted'] === false, 'a saved draft of the work comes back with the finding');
    $report = call('POST', 'TaskController@report', ['room_id' => 1, 'item_id' => 18, 'problem' => 'Kaki meja patah', 'handler_id' => 2, 'priority' => 'high'], headers: bearer($token));
    check($report['status'] === 201 && $report['body']['data']['handler']['name'] === 'Dimas Prasetyo', 'a damage report becomes a finding for the handler');
    $dimas = login('dimas', 'rahasia')['body']['data']['access_token'];
    $theirs = call('GET', 'TaskController@index', headers: bearer($dimas), query: ['tab' => 'findings'])['body'];
    check($theirs['meta']['counts']['findings'] === 2 && count($theirs['data']) === 2, 'the handler sees both findings');

    $summary = call('GET', 'ReportController@summary', headers: bearer($token), query: ['period' => 'month'])['body']['data'];
    check($summary['findings']['open'] === 2 && $summary['inspections']['routine_final'] === 1, 'the report counts this month');
    check($pdf(call('GET', 'ReportController@document', headers: bearer($token), query: ['period' => 'month'])), 'the period report comes as PDF');
    $home = call('GET', 'HomeController@show', headers: bearer($token))['body']['data'];
    check($home['stock_take'] === null && $home['counts']['inspections'] === 0, 'home shows no running stock take and nothing left to inspect');

    // Agent AI: the librarian allows it in SLiMS, Klaras Panel trades the code for a session.
    Settings::set(Settings::PANEL_URL, 'https://panel.klaras.id');
    $callback = 'https://panel.klaras.id' . AgentCodes::CALLBACK_PATH;
    $verifier = bin2hex(random_bytes(32));
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $agentCode = AgentCodes::create($db, 1, 'Claude', $challenge, $callback, time());
    $exchange = ['grant_type' => 'authorization_code', 'code' => $agentCode, 'code_verifier' => $verifier, 'redirect_uri' => $callback];
    check(call('POST', 'AuthController@issue', $exchange)['code'] === 'agents_disabled', 'no AI app connects until the administrator allows agents');
    check(!in_array('authorization_code', call('GET', 'AuthController@discovery')['body']['data']['grant_types'], true), 'discovery offers no agent sign-in while agents are off');
    AgentCodes::setEnabled($db, true, date('Y-m-d H:i:s'));
    $discovery = call('GET', 'AuthController@discovery')['body']['data'];
    check(in_array('authorization_code', $discovery['grant_types'], true) && str_contains((string) $discovery['agent_authorize_url'], 'agent=authorize'), 'discovery tells Klaras Panel where librarians allow AI apps');
    $agent = call('POST', 'AuthController@issue', $exchange);
    check($agent['status'] === 200 && $agent['body']['data']['staff']['name'] === 'Rina Wulandari', 'the code becomes a session of the librarian who allowed it');
    check($db->query('SELECT kind, device_name FROM inventory_api_sessions ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_NUM) === ['agent', 'Agent AI · Claude'], 'the session is an agent session named after the app');
    check(call('POST', 'AuthController@issue', $exchange)['code'] === 'invalid_grant', 'a consent code works once');
    $agentToken = $agent['body']['data']['access_token'];

    // Checklists, schedules, Rekap Sarpras and custom periods, as an agent asks for them.
    $templates = call('GET', 'ScheduleController@templates', headers: bearer($agentToken))['body']['data'];
    check($templates[0]['name'] === 'Checklist sarana v2' && count($templates[0]['items']) === 2, 'checklists are listed with their items');
    $made = call('POST', 'ScheduleController@storeTemplate', ['name' => 'Checklist ruang referensi', 'items' => [['group' => 'Sarana', 'object' => 'Rak referensi', 'instruction' => 'Periksa sambungan rak']]], headers: bearer($agentToken));
    check($made['status'] === 201 && $made['body']['data']['items'][0]['object'] === 'Rak referensi', 'an agent makes a checklist');
    check(call('POST', 'ScheduleController@storeTemplate', ['name' => 'Kosong', 'items' => []], headers: bearer($agentToken))['code'] === 'rejected', 'a checklist without items is refused with the service message');
    $preview = call('POST', 'ScheduleController@preview', ['frequency' => 'monthly', 'start_date' => '2026-11-02'], headers: bearer($baca));
    check($preview['status'] === 200 && array_slice($preview['body']['data']['dates'], 0, 2) === ['2026-11-02', '2026-12-02'] && count($preview['body']['data']['dates']) === 5, 'the next dates of a schedule are previewed, by a read-only librarian too');
    check(call('POST', 'ScheduleController@store', ['room_id' => 2, 'checklist_id' => $made['body']['data']['id'], 'frequency' => 'monthly', 'start_date' => $today, 'assignee_id' => 1], headers: bearer($baca))['code'] === 'read_only', 'a read-only librarian makes no schedule');
    $schedule = call('POST', 'ScheduleController@store', ['room_id' => 2, 'checklist_id' => $made['body']['data']['id'], 'frequency' => 'monthly', 'start_date' => $today, 'assignee_id' => 1], headers: bearer($agentToken));
    check($schedule['status'] === 201 && $schedule['body']['data']['schedule']['room']['name'] === 'Ruang referensi' && $schedule['body']['data']['schedule']['frequency']['label'] === 'Bulanan' && $schedule['body']['data']['inspections_formed'] === 1, 'an agent makes a schedule and today\'s inspection is formed');
    $schedules = call('GET', 'ScheduleController@index', headers: bearer($agentToken))['body']['data'];
    check(in_array('Ruang referensi', array_column(array_column($schedules, 'room'), 'name'), true), 'the new schedule is listed');
    $sarpras = call('GET', 'SarprasController@show', headers: bearer($agentToken))['body']['data'];
    check($sarpras['scope'] === 'location' && $sarpras['library'] === 'P01' && count($sarpras['aspects']) === 11, 'Rekap Sarpras comes for the one library location');
    check(call('GET', 'SarprasController@show', headers: bearer($agentToken), query: ['library' => 'X99'])['code'] === 'not_found', 'an unknown location is refused');
    $custom = call('GET', 'ReportController@summary', headers: bearer($agentToken), query: ['from' => date('Y-m-01'), 'to' => date('Y-m-t')])['body']['data'];
    check($custom['period']['key'] === 'custom' && $custom['findings']['open'] === 2, 'a report covers any period given by dates');

    AgentCodes::setEnabled($db, false, date('Y-m-d H:i:s'));
    check(call('GET', 'AuthController@me', headers: bearer($agentToken))['code'] === 'unauthenticated' && call('GET', 'AuthController@me', headers: bearer($token))['status'] === 200, 'switching agents off ends agent sessions and leaves phones signed in');

    (new \SLiMS\Plugins\Inventory\Api\StaffTokens($db))->revoke((int) $db->query("SELECT MAX(id) FROM inventory_api_sessions WHERE user_id=2")->fetchColumn());
    check(call('GET', 'AuthController@me', headers: bearer($dimas))['code'] === 'unauthenticated', 'a session revoked by the administrator stops at once');
    Guard::setEnabled($db, false);
    check(call('GET', 'AuthController@me', headers: bearer($token))['code'] === 'invensync_disabled', 'switching the app off stops every session');
    echo "ok   done\n";
} finally {
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($names as $name) $db->exec('DROP TABLE IF EXISTS ' . $name);
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach ([$photos, SB] as $dir) {
        if (!is_dir($dir)) continue;
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($dir);
    }
}
}
