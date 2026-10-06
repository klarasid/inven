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

$names = ['inventory_area_photos', 'inventory_software_files', 'inventory_support_documents', 'inventory_network_documents', 'inventory_member_fixes', 'inventory_member_locations', 'member', 'mst_member_type', 'inventory_api_codes', 'inventory_room_plans', 'inventory_room_areas', 'inventory_software', 'inventory_api_idempotency', 'inventory_api_sessions', 'inventory_watch_photos', 'inventory_watch_events', 'inventory_watch_actions', 'inventory_watch_findings', 'inventory_watch_results', 'inventory_watch_inspections', 'inventory_watch_schedules', 'inventory_watch_templates', 'inventory_item_code_reservations', 'inventory_item_code_sequences', 'inventory_item_photos', 'inventory_items', 'inventory_locations', 'stock_take_item', 'stock_take', 'mst_item_status', 'item', 'mst_location', 'group_access', 'mst_module', 'setting', 'user', 'holiday', 'slims_connect_rate_limits', 'slims_connect_settings'];
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
    $db->exec('CREATE TABLE mst_member_type (member_type_id INT PRIMARY KEY, member_type_name VARCHAR(50)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('CREATE TABLE member (member_id VARCHAR(20) PRIMARY KEY, member_type_id INT, inst_name VARCHAR(100), is_pending SMALLINT, expire_date DATE, last_update DATE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('CREATE TABLE setting (setting_id INT AUTO_INCREMENT PRIMARY KEY, setting_name VARCHAR(30) UNIQUE, setting_value MEDIUMTEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('CREATE TABLE holiday (holiday_id INT AUTO_INCREMENT PRIMARY KEY, holiday_dayname VARCHAR(20), holiday_date DATE NULL, description VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('CREATE TABLE stock_take (stock_take_id INT AUTO_INCREMENT PRIMARY KEY, stock_take_name VARCHAR(200), start_date DATETIME, end_date DATETIME NULL, init_user VARCHAR(50), total_item_stock_taked INT, total_item_lost INT, total_item_exists INT, total_item_loan INT, stock_take_users MEDIUMTEXT NULL, is_active INT(1), report_file VARCHAR(255) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec("CREATE TABLE stock_take_item (stock_take_id INT, item_id INT, item_code VARCHAR(20) UNIQUE, title VARCHAR(255), gmd_name VARCHAR(30), classification VARCHAR(30), coll_type_name VARCHAR(30), call_number VARCHAR(50), location VARCHAR(100), status ENUM('e','m','u','l'), checked_by VARCHAR(50) NULL, last_update DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec('CREATE TABLE mst_item_status (item_status_id CHAR(3) PRIMARY KEY, skip_stock_take INT(1)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('CREATE TABLE item (item_id INT PRIMARY KEY, item_code VARCHAR(20), item_status_id CHAR(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('CREATE TABLE slims_connect_rate_limits (rate_key CHAR(64) PRIMARY KEY, attempts INT, window_started_at DATETIME, blocked_until DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $db->exec('CREATE TABLE slims_connect_settings (name VARCHAR(64) PRIMARY KEY, value TEXT, updated_at DATETIME) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    foreach (['CreateInventoryTables', 'AddSlimsLocationToInventoryLocations', 'CreateInventoryItemPhotos', 'MoveInventoryPhotosToFiles', 'AllowSharedInventoryLocationCodes', 'CreateInventoryItemCodes', 'CreateInventorySupervision', 'CreateInvensyncApi', 'AddFacilityProfileData', 'AllowSeveralItemCategories', 'CreateRoomAreasAndPlans', 'AllowAgentConnections', 'MapMembersToLocations', 'CreateNetworkDocuments', 'CreateSoftwareFiles', 'RenameNetworkDocumentsToSupportDocuments', 'MoveBandwidthEvidenceToSupportDocuments', 'CreateAreaPhotos'] as $migration) (new $migration())->up();
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
    $db->exec("UPDATE inventory_items SET category = 'komputer,kenyamanan', item_type = 'Kiosk OPAC' WHERE id = 17");
    $room = call('GET', 'RoomController@items', params: ['id' => 1], headers: bearer($token))['body']['data'];
    check(count($room['items']) === 2 && $room['room']['item_count'] === 2, 'a room lists its items');
    check($room['items'][0]['categories'] === [['code' => 'komputer', 'label' => 'Komputer'], ['code' => 'kenyamanan', 'label' => 'Sarana kenyamanan']] && $room['items'][0]['type'] === 'Kiosk OPAC'
        && $room['items'][1]['categories'] === [] && $room['items'][1]['type'] === '', 'an item comes with its categories and type, or none');
    $db->exec("UPDATE inventory_items SET category = 'komputer' WHERE id = 17");

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
    $listed = call('GET', 'ReportController@inspections', headers: bearer($baca), query: ['period' => 'month'])['body']['data']['inspections'];
    $mine = array_values(array_filter($listed, static fn (array $row): bool => $row['id'] === $id))[0] ?? null;
    check($mine !== null && $mine['status'] === ['key' => 'final', 'label' => 'Difinalisasi'] && $mine['room']['name'] !== '' && $mine['performed_date'] !== null, 'the period\'s inspections are listed with their room and status, to a read-only librarian too');
    check(call('GET', 'ReportController@inspections', headers: bearer($token), query: ['period' => 'month', 'room' => 999])['body']['data']['inspections'] === [], 'the list narrows to one room');
    check($pdf(call('GET', 'ReportController@inspection', params: ['id' => $id], headers: bearer($token))), 'an inspection comes as its own document');
    check(call('GET', 'ReportController@inspection', params: ['id' => 999999], headers: bearer($token))['code'] === 'not_found', 'an unknown inspection has no document');
    $found = call('GET', 'ReportController@findings', headers: bearer($baca), query: ['period' => 'month'])['body']['data']['findings'];
    $handled = array_values(array_filter($found, static fn (array $row): bool => $row['id'] === $findingId))[0] ?? null;
    check(count($found) === 2 && $handled !== null && $handled['object'] !== '' && $handled['room'] !== '' && $handled['priority']['label'] !== '' && $handled['inspection_id'] === $id,
        'the period\'s findings are listed with what was found where, to a read-only librarian too');
    check(count($handled['actions']) >= 1 && $handled['actions'][0]['kind'] === ['key' => 'repair', 'label' => 'Perbaikan'] && $handled['actions'][0]['description'] === 'Power supply diganti' && is_bool($handled['actions'][0]['submitted']),
        'a finding comes with the work recorded on it');
    check(call('GET', 'ReportController@findings', headers: bearer($token), query: ['period' => 'month', 'room' => 999])['body']['data']['findings'] === [], 'the findings narrow to one room');
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
    $usage = static fn (): int => (int) ((@unserialize((string) $db->query("SELECT setting_value FROM setting WHERE setting_name = 'inventory_usage_counters'")->fetchColumn(), ['allowed_classes' => false]) ?: [])['agent_changes'] ?? 0);
    check($usage() === 1, 'a change made by an agent is counted for the usage report');
    call('POST', 'ScheduleController@preview', ['frequency' => 'monthly', 'start_date' => '2026-11-02'], headers: bearer($agentToken));
    call('POST', 'ScheduleController@storeTemplate', ['name' => 'Kosong', 'items' => []], headers: bearer($agentToken));
    call('POST', 'ItemController@store', ['room_id' => 1, 'name' => ''], headers: bearer($token));
    check($usage() === 1, 'previews, refused changes and changes from the phone app are not');
    check(call('POST', 'ScheduleController@storeTemplate', ['name' => 'Kosong', 'items' => []], headers: bearer($agentToken))['code'] === 'rejected', 'a checklist without items is refused with the service message');
    $preview = call('POST', 'ScheduleController@preview', ['frequency' => 'monthly', 'start_date' => '2026-11-02'], headers: bearer($baca));
    check($preview['status'] === 200 && array_slice($preview['body']['data']['dates'], 0, 2) === ['2026-11-02', '2026-12-02'] && count($preview['body']['data']['dates']) === 5, 'the next dates of a schedule are previewed, by a read-only librarian too');
    check(call('POST', 'ScheduleController@store', ['room_id' => 2, 'checklist_id' => $made['body']['data']['id'], 'frequency' => 'monthly', 'start_date' => $today, 'assignee_id' => 1], headers: bearer($baca))['code'] === 'read_only', 'a read-only librarian makes no schedule');
    $schedule = call('POST', 'ScheduleController@store', ['room_id' => 2, 'checklist_id' => $made['body']['data']['id'], 'frequency' => 'monthly', 'start_date' => $today, 'assignee_id' => 1], headers: bearer($agentToken));
    check($schedule['status'] === 201 && $schedule['body']['data']['schedule']['room']['name'] === 'Ruang referensi' && $schedule['body']['data']['schedule']['frequency']['label'] === 'Bulanan' && $schedule['body']['data']['inspections_formed'] === 1, 'an agent makes a schedule and today\'s inspection is formed');
    $schedules = call('GET', 'ScheduleController@index', headers: bearer($agentToken))['body']['data'];
    check(in_array('Ruang referensi', array_column(array_column($schedules, 'room'), 'name'), true), 'the new schedule is listed');
    $db->exec('UPDATE inventory_locations SET area_m2 = 120.5 WHERE id = 1');
    $db->exec("INSERT INTO inventory_room_areas (location_id, type, name, created_at, updated_at) VALUES (1, 'baca', '', NOW(), NOW()), (1, 'literasi', 'Pojok baca', NOW(), NOW())");
    $sarpras = call('GET', 'SarprasController@show', headers: bearer($agentToken))['body']['data'];
    check($sarpras['scope'] === 'location' && $sarpras['library'] === 'P01' && count($sarpras['aspects']) === 11, 'Rekap Sarpras comes for the one library location');
    check($sarpras['aspects'][0]['columns'] === ['Ruangan', 'Luas'] && $sarpras['aspects'][0]['rows'] === [['Ruang baca umum', '120,50 m²'], ['Ruang referensi', '—']], 'the size of each room comes with the building aspect');
    check($sarpras['aspects'][1]['rows'] === [['Ruang baca umum', 'Area baca, Area literasi / pojok baca (Pojok baca)'], ['Ruang referensi', '—']], 'the areas of each room come with the service area aspect');

    // Daftar inventaris berfoto, as an agent asks for it.
    $catalog = call('GET', 'CatalogController@summary', headers: bearer($agentToken), query: ['group' => 'area', 'categories' => 'komputer'])['body']['data'];
    check($catalog['items'] === 1 && $catalog['with_photos'] === 0 && array_column($catalog['groups'], 'items', 'label') === ['Area baca' => 1, 'Area literasi / pojok baca' => 1], 'the photo inventory is summed up by the areas of the items\' rooms');
    check($pdf(call('GET', 'CatalogController@document', headers: bearer($baca), query: ['categories' => 'komputer'])), 'the photo inventory comes as PDF, to a read-only librarian too');
    check(call('GET', 'CatalogController@summary', headers: bearer($agentToken), query: ['group' => 'ruangan'])['code'] === 'validation_failed'
        && call('GET', 'CatalogController@summary', headers: bearer($agentToken), query: ['categories' => 'mebel'])['code'] === 'validation_failed'
        && call('GET', 'CatalogController@summary', headers: bearer($agentToken), query: ['photos' => 'semua'])['code'] === 'validation_failed'
        && call('GET', 'CatalogController@document', headers: bearer($agentToken), query: ['library' => 'X99'])['details']['fields']['library'][0] === 'Lokasi perpustakaan tidak ditemukan. Pilih salah satu: P01.', 'an unknown grouping, category or location is refused, saying what to choose');

    // The areas and public facilities with their photos, as an agent asks for them.
    $db->exec("INSERT INTO inventory_room_areas (location_id, type, name, created_at, updated_at) VALUES (2, 'toilet', '', NOW(), NOW())");
    $toilet = (int) $db->lastInsertId();
    $areaStorage = \SLiMS\Plugins\Inventory\AreaPhotos::storage();
    $areaPicture = imagecreatetruecolor(640, 480);
    ob_start(); imagejpeg($areaPicture); $areaJpeg = (string) ob_get_clean();
    \SLiMS\Plugins\Inventory\AreaPhotos::store($db, $areaStorage, 2, $toilet, $areaJpeg, 1, date('Y-m-d H:i:s'));
    $areas = call('GET', 'AreaController@summary', headers: bearer($baca), query: ['group' => 'umum'])['body']['data'];
    check($areas['areas'] === 1 && $areas['with_photos'] === 1 && $areas['groups'][0]['key'] === 'umum' && $areas['groups'][0]['kinds'] === [['label' => 'Toilet', 'count' => 1]], 'public facilities are summed up by kind, with how many have a photo, to a read-only librarian too');
    check(array_column(call('GET', 'AreaController@summary', headers: bearer($agentToken))['body']['data']['groups'], 'key') === ['dasar', 'pendukung', 'umum'], 'every group of areas is listed when none is named');
    check($pdf(call('GET', 'AreaController@document', headers: bearer($agentToken), query: ['group' => 'umum'])), 'the list of areas and facilities comes as PDF, photos and all');
    check(call('GET', 'AreaController@summary', headers: bearer($agentToken), query: ['group' => 'gedung'])['code'] === 'validation_failed'
        && call('GET', 'AreaController@summary', headers: bearer($agentToken), query: ['library' => 'X99'])['code'] === 'validation_failed', 'an unknown group or location is refused');
    $db->prepare('DELETE FROM inventory_area_photos WHERE area_id = ?')->execute([$toilet]);
    $db->prepare('DELETE FROM inventory_room_areas WHERE id = ?')->execute([$toilet]);

    // The software register, as an agent asks for it.
    $db->exec("INSERT INTO inventory_software (name, version, purpose, licence, licence_ref, valid_until, installs, created_at, updated_at) VALUES ('Windows 11 Pro', '23H2', 'Sistem operasi', 'komersial', 'KUNCI-RAHASIA-123', NULL, 12, NOW(), NOW()), ('Photoshop', 'CS6', '', 'tidak', '', NULL, 2, NOW(), NOW())");
    $register = call('GET', 'SoftwareController@index', headers: bearer($baca));
    check($register['body']['data']['summary'] === ['applications' => 2, 'licensed' => 1, 'percent' => 50.0] && $register['body']['data']['software'][0]['name'] === 'Windows 11 Pro'
        && $register['body']['data']['software'][0]['purpose'] === 'Sistem operasi' && $register['body']['data']['software'][0]['licence'] === ['key' => 'komersial', 'label' => 'Komersial (berbayar)']
        && $register['body']['data']['software'][0]['licensed'] === true && $register['body']['data']['software'][1]['licensed'] === false, 'the software register comes with each application\'s use and licence, to a read-only librarian too');
    check($register['body']['data']['software'][0]['has_licence_reference'] === true && !str_contains((string) json_encode($register['body']), 'KUNCI-RAHASIA-123'), 'the licence number itself stays out of what an AI app reads');
    $licenceFile = 'lisensi-' . str_repeat('ef', 16) . '.png';
    mkdir(\SLiMS\Plugins\Inventory\SoftwareFiles::directory(), 0700, true);
    file_put_contents(\SLiMS\Plugins\Inventory\SoftwareFiles::directory() . '/' . $licenceFile, "\x89PNG licence");
    $db->prepare('INSERT INTO inventory_software_files (software_id, title, filename, mime, created_by, created_at) VALUES (?, ?, ?, ?, 1, NOW())')->execute([$register['body']['data']['software'][0]['id'], 'Stiker COA', $licenceFile, 'image/png']);
    $proofs = array_column(call('GET', 'SoftwareController@index', headers: bearer($agentToken))['body']['data']['software'], 'files', 'name');
    check(count($proofs['Windows 11 Pro']) === 1 && $proofs['Windows 11 Pro'][0]['title'] === 'Stiker COA' && $proofs['Windows 11 Pro'][0]['type'] === 'png' && $proofs['Photoshop'] === [], 'an application comes with the files that show its licence');
    $proof = call('GET', 'SoftwareController@file', params: [$proofs['Windows 11 Pro'][0]['id']], headers: bearer($baca))['body'];
    check($proof instanceof \SLiMS\Plugins\Inventory\Api\BytesResponse && $proof->bytes === "\x89PNG licence" && $proof->mimeType === 'image/png' && $proof->filename === 'stiker-coa.png', 'a licence file comes as it was uploaded');
    check(call('GET', 'SoftwareController@file', params: [999], headers: bearer($agentToken))['code'] === 'not_found', 'an unknown licence file is not found');
    check($pdf(call('GET', 'SoftwareController@document', headers: bearer($agentToken))), 'the software register comes as PDF');
    // Before migration 16 the register is still listed, without licence files.
    $db->exec('RENAME TABLE inventory_software_files TO ' . $prefix . 'licence_files_later');
    try {
        $unmigrated = call('GET', 'SoftwareController@index', headers: bearer($agentToken));
    } finally {
        $db->exec('RENAME TABLE ' . $prefix . 'licence_files_later TO inventory_software_files');
    }
    check(array_column($unmigrated['body']['data']['software'], 'files') === [[], []], 'a SLiMS that has not run the licence file migration still lists its software');
    $db->exec('DELETE FROM inventory_software');
    $db->exec('DELETE FROM inventory_software_files');

    // What shows the library's internet: the figures and the network documents.
    $network = call('GET', 'NetworkController@index', headers: bearer($agentToken))['body']['data'];
    check(count($network['locations']) === 1 && $network['locations'][0]['code'] === 'P01' && $network['locations'][0]['documents'] === [] && $network['kinds']['wifi'] === 'Peta jangkauan Wi-Fi',
        'a location\'s internet is listed before anything is uploaded');
    \SLiMS\Plugins\Inventory\Sarpras::saveSettings($db, ['bandwidth_mbps' => '300', 'bandwidth_users' => '60', 'bandwidth_coverage' => 'partial', 'bandwidth_date' => '2026-09-15'], 'P01');
    $networkFile = 'jaringan-' . str_repeat('cd', 16) . '.pdf';
    mkdir(\SLiMS\Plugins\Inventory\SupportDocuments::directory(), 0700, true);
    file_put_contents(\SLiMS\Plugins\Inventory\SupportDocuments::directory() . '/' . $networkFile, '%PDF speedtest');
    $db->prepare('INSERT INTO inventory_support_documents (library_code, kind, location_id, title, filename, mime, created_by, created_at) VALUES (?, ?, 2, ?, ?, ?, 1, NOW())')->execute(['P01', 'speedtest', 'Uji kecepatan ruang referensi', $networkFile, 'application/pdf']);
    $place = call('GET', 'NetworkController@index', headers: bearer($baca), query: ['library' => 'P01'])['body']['data']['locations'][0];
    check($place['bandwidth'] === ['mbps' => 300.0, 'users' => 60, 'coverage' => ['key' => 'partial', 'label' => 'Sebagian area layanan'], 'measured_at' => '2026-09-15'], 'the bandwidth figures come as saved, to a read-only librarian too');
    check(count($place['documents']) === 1 && $place['documents'][0]['kind'] === ['key' => 'speedtest', 'label' => 'Hasil uji kecepatan'] && $place['documents'][0]['type'] === 'pdf' && $place['documents'][0]['room'] === ['id' => 2, 'name' => 'Ruang referensi'],
        'a network document comes with its kind and the room it is of');
    $sent = call('GET', 'NetworkController@document', params: [$place['documents'][0]['id']], headers: bearer($agentToken))['body'];
    check($sent instanceof \SLiMS\Plugins\Inventory\Api\BytesResponse && $sent->bytes === '%PDF speedtest' && $sent->mimeType === 'application/pdf' && $sent->filename === 'uji-kecepatan-ruang-referensi.pdf', 'a network document comes as the file that was uploaded');
    check(call('GET', 'NetworkController@document', params: [999], headers: bearer($agentToken))['code'] === 'not_found', 'an unknown document is not found');

    // The single evidence file a location used to keep becomes a speed test among its documents (migration 18).
    $evidenceCheck = static fn (): bool => array_column(call('GET', 'SarprasController@show', headers: bearer($agentToken))['body']['data']['aspects'][5]['checks'], 'ok', 'label')['Bukti pengukuran diunggah'];
    $db->exec("DELETE FROM inventory_support_documents WHERE kind = 'speedtest'");
    check(!$evidenceCheck(), 'without a speed test the recap has no measurement evidence');
    $oldEvidence = 'bandwidth-' . str_repeat('9a', 8) . '.png';
    mkdir(\SLiMS\Plugins\Inventory\Sarpras::evidenceDir(), 0700, true);
    file_put_contents(\SLiMS\Plugins\Inventory\Sarpras::evidenceDir() . '/' . $oldEvidence, "\x89PNG evidence");
    $kept = unserialize((string) $db->query("SELECT setting_value FROM setting WHERE setting_name = 'inventory_sarpras'")->fetchColumn(), ['allowed_classes' => false]);
    $kept['locations']['P01']['evidence'] = ['file' => $oldEvidence, 'name' => 'Speedtest  September.png', 'mime' => 'image/png', 'uploaded_at' => '2026-09-15 10:00:00'];
    $db->prepare("UPDATE setting SET setting_value = ? WHERE setting_name = 'inventory_sarpras'")->execute([serialize($kept)]);
    check($evidenceCheck(), 'before migration 18 the evidence file itself still counts');
    (new MoveBandwidthEvidenceToSupportDocuments())->up();
    $moved = array_values(array_filter(call('GET', 'NetworkController@index', headers: bearer($agentToken))['body']['data']['locations'][0]['documents'], static fn (array $row): bool => $row['kind']['key'] === 'speedtest'));
    check(count($moved) === 1 && $moved[0]['title'] === 'Speedtest September' && $moved[0]['type'] === 'png' && $moved[0]['created_at'] === '2026-09-15 10:00:00' && $moved[0]['room'] === null, 'the evidence file becomes a speed test document, with its name and date');
    $movedFile = call('GET', 'NetworkController@document', params: [$moved[0]['id']], headers: bearer($agentToken))['body'];
    check($movedFile instanceof \SLiMS\Plugins\Inventory\Api\BytesResponse && $movedFile->bytes === "\x89PNG evidence" && !is_file(\SLiMS\Plugins\Inventory\Sarpras::evidenceDir() . '/' . $oldEvidence), 'its file moves with it');
    check(\SLiMS\Plugins\Inventory\Sarpras::settings($db, 'P01')['evidence'] === null && \SLiMS\Plugins\Inventory\Sarpras::settings($db, 'P01')['bandwidth_mbps'] === 300.0 && $evidenceCheck(), 'the figures stay, and the recap finds the evidence among the documents');
    (new MoveBandwidthEvidenceToSupportDocuments())->up();
    check(count(call('GET', 'NetworkController@index', headers: bearer($agentToken))['body']['data']['locations'][0]['documents']) === 1, 'running the migration again moves nothing twice');
    check(call('GET', 'NetworkController@index', headers: bearer($agentToken), query: ['library' => 'X99'])['code'] === 'validation_failed', 'an unknown location is refused');

    // Supporting documents of every topic: the security and safety ones beside the network ones.
    $safetyFile = 'dokumen-' . str_repeat('12', 16) . '.pdf';
    file_put_contents(\SLiMS\Plugins\Inventory\SupportDocuments::directory() . '/' . $safetyFile, '%PDF pos');
    $db->prepare('INSERT INTO inventory_support_documents (library_code, kind, location_id, title, filename, mime, created_by, created_at) VALUES (?, ?, NULL, ?, ?, ?, 1, NOW())')->execute(['P01', 'pos', 'POS tanggap darurat', $safetyFile, 'application/pdf']);
    $safety = call('GET', 'DocumentController@index', headers: bearer($baca), query: ['topic' => 'keamanan'])['body']['data'];
    check(array_keys($safety['kinds']) === ['uji_fungsi', 'pos', 'pelatihan'] && count($safety['locations'][0]['documents']) === 1 && $safety['locations'][0]['documents'][0]['kind'] === ['key' => 'pos', 'label' => 'POS tanggap darurat dan sistem keamanan'] && $safety['locations'][0]['documents'][0]['room'] === null,
        'security and safety documents are listed by their own topic, to a read-only librarian too');
    check(count(call('GET', 'DocumentController@index', headers: bearer($agentToken))['body']['data']['locations'][0]['documents']) === 2
        && count(call('GET', 'NetworkController@index', headers: bearer($agentToken))['body']['data']['locations'][0]['documents']) === 1, 'every topic is listed together, and the network list keeps to its own');
    $sentSafety = call('GET', 'DocumentController@show', params: [$safety['locations'][0]['documents'][0]['id']], headers: bearer($agentToken))['body'];
    check($sentSafety instanceof \SLiMS\Plugins\Inventory\Api\BytesResponse && $sentSafety->bytes === '%PDF pos' && $sentSafety->filename === 'pos-tanggap-darurat.pdf', 'a supporting document comes as the file that was uploaded');
    check(call('GET', 'DocumentController@index', headers: bearer($agentToken), query: ['topic' => 'gedung'])['code'] === 'validation_failed'
        && call('GET', 'DocumentController@show', params: [999], headers: bearer($agentToken))['code'] === 'not_found', 'an unknown topic is refused, and an unknown document is not found');

    // Floor plans, as an agent lists and fetches them.
    check(call('GET', 'PlanController@index', headers: bearer($agentToken))['body']['data'] === [], 'no plans are listed before one is uploaded');
    $planFile = 'denah-' . str_repeat('ab', 16) . '.png';
    mkdir(\SLiMS\Plugins\Inventory\RoomPlans::directory(), 0700, true);
    file_put_contents(\SLiMS\Plugins\Inventory\RoomPlans::directory() . '/' . $planFile, "\x89PNG plan");
    $db->prepare('INSERT INTO inventory_room_plans (location_id, title, filename, mime, created_by, created_at) VALUES (2, ?, ?, ?, 1, NOW())')->execute(['Denah lantai 1', $planFile, 'image/png']);
    $plans = call('GET', 'PlanController@index', headers: bearer($baca))['body']['data'];
    check(count($plans) === 1 && $plans[0]['title'] === 'Denah lantai 1' && $plans[0]['type'] === 'png' && $plans[0]['room'] === ['id' => 2, 'name' => 'Ruang referensi'], 'plans are listed with their room, to a read-only librarian too');
    $plan = call('GET', 'PlanController@show', params: [$plans[0]['id']], headers: bearer($agentToken))['body'];
    check($plan instanceof \SLiMS\Plugins\Inventory\Api\BytesResponse && $plan->bytes === "\x89PNG plan" && $plan->mimeType === 'image/png' && $plan->filename === 'denah-lantai-1.png', 'a plan comes as the file that was uploaded');
    check(call('GET', 'PlanController@show', params: [999], headers: bearer($agentToken))['code'] === 'not_found', 'an unknown plan is not found');
    check(call('GET', 'PlanController@index')['code'] === 'unauthenticated', 'plans need a session');

    // Sivitas on MySQL, whose text comparisons ignore case: spellings are still told apart.
    $db->exec("INSERT INTO mst_member_type VALUES (1, 'Mahasiswa')");
    $db->exec("INSERT INTO member VALUES ('A1', 1, 'Gizi', 0, '2099-01-01', NULL), ('A2', 1, 'gizi', 0, '2099-01-01', NULL), ('A3', 1, 'Gizi', 1, '2099-01-01', NULL)");
    check(\SLiMS\Plugins\Inventory\Sivitas::forLocation($db, 'P01') === 2, 'active members are the sivitas of a library that is one unit');
    $gizi = array_values(array_filter(\SLiMS\Plugins\Inventory\Sivitas::institutions($db), static fn ($row) => $row['key'] === 'gizi'))[0];
    check(count($gizi['variants']) === 2, 'MySQL lists "Gizi" and "gizi" as two spellings');
    $merged = \SLiMS\Plugins\Inventory\Sivitas::merge($db, ['gizi'], 'Gizi', 1, date('Y-m-d H:i:s'));
    check($merged['count'] === 1 && \SLiMS\Plugins\Inventory\Sivitas::undo($db, $merged['id'], date('Y-m-d H:i:s')) === 1
        && $db->query("SELECT inst_name FROM member WHERE member_id='A1'")->fetchColumn() === 'Gizi', 'a merge and its undo touch only the exact spelling');
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
