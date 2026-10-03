<?php

namespace SLiMS\Plugins\Inventory;

use PDO;

/**
 * The daily usage report this plugin sends to Klaras, which keeps it working for every library.
 *
 * What is sent: the library's name and SLiMS address, versions of the plugin, SLiMS, PHP and the
 * database, counts (rooms, items by condition, inspections, findings, stock take sessions, and how
 * much of the data behind Rekap Sarpras is filled in: rooms with an area or a function, items with
 * a category, software in the register, locations with building figures, how many Institusi
 * values and member types are placed at a location and how many Institusi merges stand), how often
 * each feature was used, and recent technical errors with any data stripped from them.
 * What is never sent: inventory records, item names or codes, software names, building and network
 * figures, members, staff or anything typed in.
 *
 * On by default. There is no page for it in SLiMS: what is sent is described in the README, and a
 * library that wants its data removed writes to Klaras. An installation that switched reporting
 * off while there was a page for it stays off (setEnabled). Sent at most once a day, after the
 * page has been delivered, so it never slows a page and a failure is never seen.
 *
 * Runs on PHP 7.4 like the rest of the plugin.
 */
final class Telemetry
{
    public const PLUGIN = 'inventaris-barang';
    public const SETTING = 'inventory_telemetry';
    public const COUNTERS = 'inventory_usage_counters';
    public const ERRORS = 'inventory_telemetry_errors';
    public const DEFAULT_PANEL = 'https://panel.klaras.id';
    public const FEATURES = ['kir_pdf', 'labels_pdf', 'report_pdf', 'inspection_pdf', 'history_import', 'sarpras_pdf', 'institution_merge'];
    public const ERROR_CATEGORIES = ['pdf', 'db', 'photo', 'workspace', 'other'];
    private const INTERVAL = 86400;
    private const RETRY = 3600;
    private const LEASE = 120;
    private const MAX_ERRORS = 20;

    /** Tests replace the HTTP call: fn(string $url, array $payload): bool */
    public static $transport = null;

    /** @var \PDO|null */
    private static $deferred = null;

    private static function read(PDO $db, string $name): array
    {
        $statement = $db->prepare('SELECT setting_value FROM setting WHERE setting_name = ?');
        $statement->execute([$name]);
        $value = $statement->fetchColumn();
        $data = is_string($value) ? @unserialize($value, ['allowed_classes' => false]) : null;
        return is_array($data) ? $data : [];
    }

    private static function write(PDO $db, string $name, array $value): void
    {
        $db->prepare('INSERT INTO setting (setting_name, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([$name, serialize($value)]);
    }

    /** @return array{enabled:bool,install_id:string,last_sent:int,last_result:string,leased_until:int} */
    public static function state(PDO $db): array
    {
        $state = self::read($db, self::SETTING);
        if (empty($state['install_id']) || !preg_match('/\A[0-9a-f-]{36}\z/', (string) $state['install_id'])) {
            $state['install_id'] = self::uuid();
            $state += ['enabled' => true];
            self::write($db, self::SETTING, $state);
        }
        return [
            'enabled' => !array_key_exists('enabled', $state) || (bool) $state['enabled'],
            'install_id' => (string) $state['install_id'],
            'last_sent' => (int) ($state['last_sent'] ?? 0),
            'last_result' => (string) ($state['last_result'] ?? ''),
            'leased_until' => (int) ($state['leased_until'] ?? 0),
        ];
    }

    private static function update(PDO $db, array $changes): void
    {
        self::write($db, self::SETTING, array_merge(self::read($db, self::SETTING), $changes));
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** Where reports go: KLARAS_PANEL_URL (constant or environment), else Klaras Panel. */
    public static function panelUrl(): string
    {
        $url = defined('KLARAS_PANEL_URL') ? (string) constant('KLARAS_PANEL_URL') : (string) getenv('KLARAS_PANEL_URL');
        return rtrim($url !== '' ? $url : self::DEFAULT_PANEL, '/');
    }

    public static function endpoint(): string
    {
        return self::panelUrl() . '/api/v1/telemetry/plugins/' . self::PLUGIN;
    }

    // Counting -----------------------------------------------------------------------------

    /** One more use of a feature. Never fails the page that counts it. */
    public static function count(string $feature): void
    {
        if (!in_array($feature, self::FEATURES, true)) return;
        try {
            $db = \SLiMS\DB::getInstance();
            $counters = self::read($db, self::COUNTERS);
            $counters[$feature] = (int) ($counters[$feature] ?? 0) + 1;
            self::write($db, self::COUNTERS, $counters);
        } catch (\Throwable $error) {
            // Counting is never worth an error on the librarian's screen.
        }
    }

    /** Remembers a technical error, without any data it carried. Never fails the page. */
    public static function error(string $category, \Throwable $error): void
    {
        try {
            $db = \SLiMS\DB::getInstance();
            $category = in_array($category, self::ERROR_CATEGORIES, true) ? $category : 'other';
            $class = substr(get_class($error), 0, 100);
            $where = self::where($error);
            $message = self::clean($error->getMessage());
            $key = md5($category . '|' . $class . '|' . $where . '|' . $message);
            $errors = self::read($db, self::ERRORS);
            $entry = $errors[$key] ?? ['category' => $category, 'class' => $class, 'where' => $where, 'message' => $message, 'count' => 0];
            $entry['count'] = (int) $entry['count'] + 1;
            $entry['last_at'] = date('c');
            unset($errors[$key]);
            $errors[$key] = $entry;
            if (count($errors) > self::MAX_ERRORS) $errors = array_slice($errors, -self::MAX_ERRORS, null, true);
            self::write($db, self::ERRORS, $errors);
        } catch (\Throwable $ignored) {
        }
    }

    /** The plugin file and line the error came from; outside the plugin, only the file name. */
    private static function where(\Throwable $error): string
    {
        $root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $file = (string) $error->getFile();
        $relative = strpos($file, $root) === 0 ? substr($file, strlen($root)) : basename($file);
        return substr($relative . ':' . $error->getLine(), 0, 160);
    }

    /**
     * A message with the data taken out: quoted text, numbers, e-mail addresses and paths become
     * "?", so "Duplicate entry 'P01-INV-000012' for key …" is reported as "Duplicate entry ? for key …".
     */
    public static function clean(string $message): string
    {
        $message = preg_replace('/[\'"`][^\'"`]*[\'"`]/u', '?', $message);
        $message = preg_replace('/\S+@\S+/u', '?', $message);
        $message = preg_replace('#(?:[A-Za-z]:)?[\\\\/][^\s:]+#u', '?', $message);
        $message = preg_replace('/\d+(?:[.,]\d+)*/u', '?', $message);
        $message = trim(preg_replace('/\s+/u', ' ', $message));
        return function_exists('mb_substr') ? mb_substr($message, 0, 160) : substr($message, 0, 160);
    }

    // The report ----------------------------------------------------------------------------

    private static function value(PDO $db, string $sql, array $args = [])
    {
        try {
            $statement = $db->prepare($sql);
            $statement->execute($args);
            $value = $statement->fetchColumn();
            return $value === false ? null : (int) $value;
        } catch (\Throwable $error) {
            // A table this installation has not migrated to yet.
            return null;
        }
    }

    /**
     * How many library locations have Gedung & Jaringan filled in: only the number, never the
     * figures. Figures saved before locations were told apart count as one (see Sarpras::profiles).
     */
    private static function facilityProfiles(PDO $db): ?int
    {
        try {
            $stored = self::read($db, 'inventory_sarpras');
            return is_array($stored['locations'] ?? null) ? count($stored['locations']) : ($stored ? 1 : 0);
        } catch (\Throwable $error) {
            return null;
        }
    }

    /** 1 when Sivitas per Lokasi has a default location, else 0: whether, never which. */
    private static function defaultLocationSet(PDO $db): int
    {
        try {
            $stored = self::read($db, 'inventory_sivitas');
            return is_string($stored['default'] ?? null) && $stored['default'] !== '' ? 1 : 0;
        } catch (\Throwable $error) {
            return 0;
        }
    }

    /** Exactly what is sent. */
    public static function report(PDO $db): array
    {
        $state = self::state($db);
        $sysconf = isset($GLOBALS['sysconf']) && is_array($GLOBALS['sysconf']) ? $GLOBALS['sysconf'] : [];
        $count = function (string $sql, array $args = []) use ($db) { return self::value($db, $sql, $args); };
        $since = date('Y-m-d', strtotime('-30 days'));
        $today = date('Y-m-d');

        $stats = [
            'rooms' => $count('SELECT COUNT(*) FROM inventory_locations'),
            'items' => $count('SELECT COUNT(*) FROM inventory_items'),
            'items_good' => $count("SELECT COUNT(*) FROM inventory_items WHERE item_condition = 'B'"),
            'items_fair' => $count("SELECT COUNT(*) FROM inventory_items WHERE item_condition = 'KB'"),
            'items_poor' => $count("SELECT COUNT(*) FROM inventory_items WHERE item_condition = 'RB'"),
            'photos' => $count('SELECT COUNT(*) FROM inventory_item_photos'),
            'libraries' => $count('SELECT COUNT(DISTINCT slims_location_id) FROM inventory_locations WHERE slims_location_id IS NOT NULL'),
            'templates' => $count('SELECT COUNT(*) FROM inventory_watch_templates'),
            'schedules_active' => $count('SELECT COUNT(*) FROM inventory_watch_schedules WHERE active = 1 AND location_id IS NOT NULL AND (end_date IS NULL OR end_date >= ?)', [$today]),
            'inspections' => $count('SELECT COUNT(*) FROM inventory_watch_inspections'),
            'inspections_30d' => $count('SELECT COUNT(*) FROM inventory_watch_inspections WHERE due_date >= ?', [$since]),
            'inspections_final_30d' => $count("SELECT COUNT(*) FROM inventory_watch_inspections WHERE status = 'final' AND due_date >= ?", [$since]),
            'inspections_late' => $count("SELECT COUNT(*) FROM inventory_watch_inspections WHERE status <> 'final' AND due_date < ?", [$today]),
            'findings_open' => $count("SELECT COUNT(*) FROM inventory_watch_findings WHERE status IN ('open','working')"),
            'findings_review' => $count("SELECT COUNT(*) FROM inventory_watch_findings WHERE status = 'review'"),
            'findings_closed' => $count("SELECT COUNT(*) FROM inventory_watch_findings WHERE status = 'closed'"),
            'actions' => $count('SELECT COUNT(*) FROM inventory_watch_actions'),
            'stock_takes' => $count('SELECT COUNT(*) FROM stock_take'),
            'stock_take_active' => $count('SELECT COUNT(*) FROM stock_take WHERE is_active = 1'),
            'stock_take_items' => $count('SELECT COUNT(*) FROM stock_take_item'),
            'rooms_with_area' => $count('SELECT COUNT(*) FROM inventory_locations WHERE area_m2 IS NOT NULL'),
            // Rooms whose use is recorded: by their areas, or by ticked functions before migration 12.
            'rooms_with_functions' => $count('SELECT COUNT(DISTINCT location_id) FROM inventory_room_areas') ?? $count("SELECT COUNT(*) FROM inventory_locations WHERE room_functions <> ''"),
            'items_categorized' => $count('SELECT COUNT(*) FROM inventory_items WHERE category IS NOT NULL'),
            'software' => $count('SELECT COUNT(*) FROM inventory_software'),
            'facility_profiles' => self::facilityProfiles($db),
            // Sivitas per Lokasi: how far placing members has got, never how many members there are.
            'institutions_mapped' => $count("SELECT COUNT(*) FROM inventory_member_locations WHERE basis = 'institution'"),
            'member_types_mapped' => $count("SELECT COUNT(*) FROM inventory_member_locations WHERE basis = 'type'"),
            'institution_fixes' => $count('SELECT COUNT(*) FROM inventory_member_fixes WHERE undone_at IS NULL'),
        ];
        $counters = self::read($db, self::COUNTERS);
        $features = [];
        foreach (self::FEATURES as $feature) {
            $features[$feature] = (int) ($counters[$feature] ?? 0);
        }
        // Damage reports are incidental inspections whose snapshot carries this template name.
        $features['damage_reports'] = (int) $count("SELECT COUNT(*) FROM inventory_watch_inspections WHERE kind = 'incidental' AND snapshot LIKE ?", ['%"template_name":"Laporan kerusakan"%']);
        $features['app_enabled'] = (int) ($count("SELECT COUNT(*) FROM setting WHERE setting_name = 'invensync_enabled' AND setting_value = ?", [serialize('1')]) ?? 0);
        $features['default_location_set'] = self::defaultLocationSet($db);
        $features['app_sessions'] = (int) $count('SELECT COUNT(DISTINCT user_id) FROM inventory_api_sessions WHERE revoked_at IS NULL AND (access_expires_at > ? OR refresh_expires_at > ?)', [date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);

        $plugin = UpdateCheck::plugin();
        $dbVersion = null;
        try {
            $dbVersion = substr((string) $db->query('SELECT VERSION()')->fetchColumn(), 0, 64);
        } catch (\Throwable $error) {
        }
        $migration = null;
        try {
            $options = $db->prepare('SELECT options FROM plugins WHERE path LIKE ? LIMIT 1');
            $options->execute(['%' . DIRECTORY_SEPARATOR . 'inventory.plugin.php']);
            $decoded = json_decode((string) $options->fetchColumn(), true);
            $migration = is_array($decoded) && isset($decoded['db_version']) ? (int) $decoded['db_version'] : null;
        } catch (\Throwable $error) {
        }

        return [
            'install_id' => $state['install_id'],
            'library' => [
                'name' => substr(trim((string) ($sysconf['library_name'] ?? '')), 0, 255) ?: null,
                'site_url' => self::siteUrl(),
            ],
            'environment' => [
                'plugin_version' => $plugin['version'],
                'slims_version' => defined('SENAYAN_VERSION_TAG') ? substr((string) SENAYAN_VERSION_TAG, 0, 32) : null,
                'php_version' => PHP_VERSION,
                'db_version' => $dbVersion,
                'migration' => $migration,
                'mpdf' => class_exists('\\Mpdf\\Mpdf') || is_file(dirname(__DIR__) . '/vendor/mpdf/mpdf/src/Mpdf.php'),
                'extensions' => [
                    'gd' => extension_loaded('gd'),
                    'curl' => extension_loaded('curl'),
                    'mbstring' => extension_loaded('mbstring'),
                    'fileinfo' => extension_loaded('fileinfo'),
                ],
            ],
            'stats' => array_filter($stats, function ($value) { return $value !== null; }),
            'features' => $features,
            'errors' => array_values(self::read($db, self::ERRORS)),
        ];
    }

    /** This SLiMS's public address, as the browser reached it. */
    private static function siteUrl(): ?string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || !preg_match('/\A[A-Za-z0-9.-]+(?::\d+)?\z/', $host)) return null;
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https' : 'http') . '://' . $host . (defined('SWB') ? SWB : '/');
    }

    // Sending -------------------------------------------------------------------------------

    public static function due(array $state): bool
    {
        if (!$state['enabled'] || $state['leased_until'] > time()) return false;
        $wait = $state['last_result'] === 'ok' ? self::INTERVAL : self::RETRY;
        return time() >= $state['last_sent'] + $wait;
    }

    /**
     * Sends the report after this page has gone out, if one is due. Called from every plugin page;
     * at most one report a day leaves, whatever the traffic.
     */
    public static function sendLater(): void
    {
        if (self::$deferred !== null) return;
        try {
            $db = \SLiMS\DB::getInstance();
            if (!self::due(self::state($db))) return;
            self::$deferred = $db;
            register_shutdown_function(function () {
                if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
                self::sendIfDue(self::$deferred);
            });
        } catch (\Throwable $error) {
        }
    }

    public static function sendIfDue(PDO $db): bool
    {
        try {
            $state = self::state($db);
            if (!self::due($state)) return false;
            // Claim the slot first, so a second page opening at the same moment does not also send.
            self::update($db, ['leased_until' => time() + self::LEASE]);
            $sent = self::post(self::report($db));
            self::update($db, ['last_sent' => time(), 'last_result' => $sent ? 'ok' : 'failed', 'leased_until' => 0]);
            if ($sent) self::write($db, self::ERRORS, []);
            return $sent;
        } catch (\Throwable $error) {
            return false;
        }
    }

    /** Switches reporting off or back on. Off tells Klaras once, so it forgets this library. */
    public static function setEnabled(PDO $db, bool $enabled): void
    {
        $state = self::state($db);
        if (!$enabled && $state['enabled']) {
            self::post(['install_id' => $state['install_id'], 'opted_out' => true]);
        }
        self::update($db, ['enabled' => $enabled, 'last_sent' => $enabled ? 0 : $state['last_sent'], 'last_result' => $enabled ? '' : 'off']);
    }

    private static function post(array $payload): bool
    {
        if (is_callable(self::$transport)) return (bool) call_user_func(self::$transport, self::endpoint(), $payload);
        if (!function_exists('curl_init')) return false;
        $curl = curl_init(self::endpoint());
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'User-Agent: klaras-inven/' . UpdateCheck::plugin()['version']],
        ]);
        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return $status >= 200 && $status < 300;
    }
}
