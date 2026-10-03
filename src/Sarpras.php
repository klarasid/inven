<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

require_once __DIR__ . '/PhotoStorage.php';
require_once __DIR__ . '/RoomAreas.php';
require_once __DIR__ . '/Sivitas.php';

/**
 * Rekap Sarpras: eleven aspects of the library's facilities, each computed from the inventory
 * (room area, the areas inside rooms, item categories and conditions), the software register, the figures
 * kept in the `inventory_sarpras` setting (building, bandwidth), sivitas counted from SLiMS's
 * active members (Sivitas), and supervision history. Each
 * aspect reports the level its data reaches (a = Sangat baik … d = Kurang), with the checks
 * that decided it, so the library can see what is missing.
 *
 * A recap is computed for one SLiMS library location: its rooms, its items and its own facility
 * figures. Where several locations hold rooms, their recaps are also combined into one for the
 * institution, each aspect taking the average of the locations that have data for it.
 */
final class Sarpras
{
    public const SETTING = 'inventory_sarpras';
    public const LEVELS = ['a' => 'Sangat baik', 'b' => 'Baik', 'c' => 'Cukup', 'd' => 'Kurang'];

    /** Short names of the aspects for the screen; the printed recap keeps their full titles. */
    private const NAMES = [
        1 => 'Luas gedung dan ruang', 2 => 'Area layanan', 3 => 'Kondisi barang', 4 => 'Perabot dan peralatan',
        5 => 'Komputer layanan', 6 => 'Jaringan internet', 7 => 'Perangkat multimedia', 8 => 'Lisensi perangkat lunak',
        9 => 'Keamanan dan keselamatan', 10 => 'Fasilitas umum', 11 => 'Pemeriksaan rutin dan tindak lanjut',
    ];

    /**
     * Kinds of area a room may hold (RoomAreas): the four basic service areas, supporting ones,
     * and public facilities, which are rooms or areas rather than items.
     */
    public const AREA_TYPES = [
        'koleksi' => ['label' => 'Area koleksi', 'group' => 'dasar'],
        'baca' => ['label' => 'Area baca', 'group' => 'dasar'],
        'kerja' => ['label' => 'Area kerja staf', 'group' => 'dasar'],
        'layanan' => ['label' => 'Area layanan (sirkulasi dan referensi)', 'group' => 'dasar'],
        'diskusi' => ['label' => 'Ruang diskusi', 'group' => 'pendukung'],
        'multimedia' => ['label' => 'Ruang multimedia / audio visual', 'group' => 'pendukung'],
        'belajar' => ['label' => 'Ruang belajar mandiri / carrel', 'group' => 'pendukung'],
        'seminar' => ['label' => 'Ruang seminar / pertemuan', 'group' => 'pendukung'],
        'literasi' => ['label' => 'Area literasi / pojok baca', 'group' => 'pendukung'],
        'pimpinan' => ['label' => 'Ruang pimpinan / administrasi', 'group' => 'pendukung'],
        'gudang' => ['label' => 'Ruang penyimpanan / gudang', 'group' => 'pendukung'],
        'lainnya' => ['label' => 'Area pendukung lainnya', 'group' => 'pendukung'],
        'toilet' => ['label' => 'Toilet', 'group' => 'umum'],
        'musala' => ['label' => 'Musala', 'group' => 'umum'],
        'parkir' => ['label' => 'Area parkir', 'group' => 'umum'],
        'kantin' => ['label' => 'Kantin / pantri', 'group' => 'umum'],
        'laktasi' => ['label' => 'Ruang laktasi', 'group' => 'umum'],
    ];

    public const AREA_GROUPS = ['dasar' => 'Area layanan dasar', 'pendukung' => 'Area pendukung', 'umum' => 'Fasilitas umum'];

    public const CATEGORIES = [
        'perabot' => 'Perabot (meja, kursi, rak)',
        'peralatan' => 'Peralatan perpustakaan',
        'komputer' => 'Komputer',
        'multimedia' => 'Perangkat multimedia',
        'keamanan' => 'Sarana keamanan dan keselamatan',
        'fasilitas_umum' => 'Fasilitas umum',
        'lainnya' => 'Lainnya',
    ];

    /** Common types per category, offered as suggestions; any text is accepted. */
    public const TYPES = [
        'komputer' => ['PC', 'Laptop', 'Server', 'Kiosk OPAC', 'Thin client'],
        'multimedia' => ['Proyektor', 'Layar proyektor', 'Televisi / monitor besar', 'Panel interaktif', 'Pengeras suara', 'Mikrofon', 'Kamera', 'Pemindai (scanner)', 'Printer', 'Headphone', 'Perangkat VR'],
        'keamanan' => ['APAR', 'CCTV', 'Security gate', 'Alarm kebakaran', 'Detektor asap', 'Hidran', 'Rambu dan jalur evakuasi', 'Kotak P3K', 'Loker penitipan', 'Pintu darurat'],
        // Toilets, prayer rooms and the like are areas of a room (AREA_TYPES), not items.
        'fasilitas_umum' => ['Akses difabel (ramp)', 'Wi-Fi publik', 'Dispenser air minum', 'Stasiun pengisian daya', 'Tempat sampah terpilah'],
        'perabot' => ['Meja baca', 'Kursi', 'Rak buku', 'Meja sirkulasi', 'Lemari katalog', 'Sofa', 'Meja komputer'],
        'peralatan' => ['Troli buku', 'Book drop', 'Mesin fotokopi', 'Barcode scanner', 'Label printer', 'Tangga rak'],
    ];

    public const LICENCES = [
        'komersial' => 'Komersial (berbayar)',
        'langganan' => 'Langganan / SaaS',
        'open_source' => 'Open source',
        'freeware' => 'Gratis (freeware)',
        'hibah' => 'Lisensi hibah / pendidikan',
        'tidak' => 'Tidak berlisensi / tidak jelas',
    ];

    public const COVERAGE = ['all' => 'Seluruh area layanan', 'partial' => 'Sebagian area layanan'];

    private const DEFAULTS = [
        'sivitas' => 0, 'designed' => false, 'building_area' => 0.0,
        'bandwidth_mbps' => 0.0, 'bandwidth_users' => 0, 'bandwidth_coverage' => 'all', 'bandwidth_date' => '',
        'evidence' => null,
    ];
    private const EVIDENCE_TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    public const EVIDENCE_MAX = 5 * 1024 * 1024;

    /** Classification lists for the room and item forms. */
    public static function lists(): array
    {
        return ['areaTypes' => self::AREA_TYPES, 'areaGroups' => self::AREA_GROUPS, 'categories' => self::CATEGORIES, 'types' => self::TYPES];
    }

    /** Room area in m², or null when left empty. */
    public static function area($value): ?float
    {
        $text = str_replace(',', '.', trim((string) ($value ?? '')));
        if ($text === '') return null;
        if (!is_numeric($text) || (float) $text < 0 || (float) $text > 99999999) throw new RuntimeException('Luas ruangan tidak valid.');
        return round((float) $text, 2);
    }

    /**
     * An item's categories as stored: comma-separated codes in a fixed order, or null for none.
     * An item may have several, e.g. a computer that is also multimedia equipment.
     */
    public static function categories($value): ?string
    {
        $codes = is_array($value) ? $value : explode(',', (string) ($value ?? ''));
        $codes = array_values(array_filter(array_map(static fn($code) => is_scalar($code) ? trim((string) $code) : '?', $codes), static fn($code) => $code !== ''));
        if (array_diff($codes, array_keys(self::CATEGORIES))) throw new RuntimeException('Kategori barang tidak valid.');
        return implode(',', array_values(array_filter(array_keys(self::CATEGORIES), static fn($code) => in_array($code, $codes, true)))) ?: null;
    }

    /** The category codes in a stored value. @return list<string> */
    public static function categoryCodes($stored): array
    {
        return array_values(array_filter(explode(',', (string) ($stored ?? '')), static fn($code) => isset(self::CATEGORIES[$code])));
    }

    public static function type($value): string
    {
        $value = trim((string) ($value ?? ''));
        if (mb_strlen($value) > 100) throw new RuntimeException('Jenis barang maksimal 100 karakter.');
        return $value;
    }

    // ---- Library locations ---------------------------------------------------------------------

    /**
     * The locations a recap is computed for: SLiMS library locations that hold rooms. Rooms without
     * a location belong to none of them and are counted as unassigned; when no room has a location
     * the library is one unit (no locations, code ''). With no rooms at all, every SLiMS location
     * is listed so its facility figures can be entered first.
     *
     * @return array{locations:list<array{code:string,name:string,rooms:int}>,unassigned:int,rooms:int}
     */
    public static function locations(PDO $db): array
    {
        $all = array_map(
            static fn($row) => ['code' => (string) $row['code'], 'name' => (string) $row['name'], 'rooms' => (int) $row['rooms']],
            $db->query('SELECT ml.location_id code, ml.location_name name, COUNT(l.id) rooms FROM mst_location ml LEFT JOIN inventory_locations l ON l.slims_location_id=ml.location_id GROUP BY ml.location_id, ml.location_name ORDER BY ml.location_name, ml.location_id')->fetchAll(PDO::FETCH_ASSOC)
        );
        $used = array_values(array_filter($all, static fn($location) => $location['rooms'] > 0));
        $rooms = (int) $db->query('SELECT COUNT(*) FROM inventory_locations')->fetchColumn();
        return [
            'locations' => $used ?: ($rooms === 0 ? $all : []),
            'unassigned' => $used ? $rooms - array_sum(array_column($used, 'rooms')) : 0,
            'rooms' => $rooms,
        ];
    }

    /** The location with the most rooms (the lowest code on a tie), or '' when no room has one. */
    private static function main(PDO $db): string
    {
        return (string) $db->query('SELECT l.slims_location_id FROM inventory_locations l JOIN mst_location ml ON ml.location_id=l.slims_location_id GROUP BY l.slims_location_id ORDER BY COUNT(*) DESC, l.slims_location_id LIMIT 1')->fetchColumn();
    }

    // ---- Settings ------------------------------------------------------------------------------

    /**
     * Facility figures per location code, from what the setting holds. Figures saved before
     * locations were told apart, or while no room had a location, sit under '' and belong to the
     * main location once there is one.
     *
     * @return array<string,array>
     */
    public static function profiles(array $stored, string $main): array
    {
        $profiles = is_array($stored['locations'] ?? null) ? $stored['locations'] : ($stored ? ['' => $stored] : []);
        if ($main !== '') {
            if (isset($profiles['']) && !isset($profiles[$main])) $profiles[$main] = $profiles[''];
            unset($profiles['']);
        }
        return $profiles;
    }

    /** @return array<string,array> */
    private static function all(PDO $db): array
    {
        $query = $db->prepare('SELECT setting_value FROM setting WHERE setting_name=?');
        $query->execute([self::SETTING]);
        $value = $query->fetchColumn();
        $stored = is_string($value) ? @unserialize($value, ['allowed_classes' => false]) : [];
        return self::profiles(is_array($stored) ? $stored : [], self::main($db));
    }

    private static function profile(array $profiles, string $library): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key(is_array($profiles[$library] ?? null) ? $profiles[$library] : [], self::DEFAULTS));
    }

    /** Facility figures of one location ('' while the library is one unit). */
    public static function settings(PDO $db, string $library = ''): array
    {
        return self::profile(self::all($db), $library);
    }

    private static function store(PDO $db, array $profiles): void
    {
        $db->prepare('INSERT INTO setting (setting_name,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
            ->execute([self::SETTING, serialize(['locations' => $profiles])]);
    }

    private static function number($value, string $label, float $max): float
    {
        $text = str_replace(',', '.', trim((string) ($value ?? '')));
        if ($text === '') return 0.0;
        if (!is_numeric($text) || (float) $text < 0 || (float) $text > $max) throw new RuntimeException("$label tidak valid.");
        return round((float) $text, 2);
    }

    public static function saveSettings(PDO $db, array $input, string $library = ''): array
    {
        $profiles = self::all($db);
        $settings = self::profile($profiles, $library);
        $settings['designed'] = ($input['designed'] ?? '') === '1';
        $settings['building_area'] = self::number($input['building_area'] ?? '', 'Luas gedung', 99999999);
        $settings['bandwidth_mbps'] = self::number($input['bandwidth_mbps'] ?? '', 'Bandwidth', 1000000);
        $settings['bandwidth_users'] = (int) self::number($input['bandwidth_users'] ?? '', 'Jumlah pengguna serentak', 1000000);
        $coverage = (string) ($input['bandwidth_coverage'] ?? 'all');
        $settings['bandwidth_coverage'] = isset(self::COVERAGE[$coverage]) ? $coverage : 'all';
        $date = trim((string) ($input['bandwidth_date'] ?? ''));
        if ($date !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new RuntimeException('Tanggal pengukuran tidak valid.');
        }
        $settings['bandwidth_date'] = $date;
        $profiles[$library] = $settings;
        self::store($db, $profiles);
        return $settings;
    }

    // ---- Bandwidth evidence --------------------------------------------------------------------

    /** Kept with the inventory photos and denied to browsers like them (PhotoStorage::protect), so it is only served through the page. */
    public static function evidenceDir(): string
    {
        return SB . 'images' . DIRECTORY_SEPARATOR . 'inventaris-barang' . DIRECTORY_SEPARATOR . 'sarpras';
    }

    public static function uploadEvidence(PDO $db, array $file, string $library = ''): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Pilih berkas bukti (PDF, JPEG, PNG, atau WebP) maksimal 5 MB.');
        }
        if ((int) $file['size'] > self::EVIDENCE_MAX) throw new RuntimeException('Berkas bukti maksimal 5 MB.');
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::EVIDENCE_TYPES[$mime])) throw new RuntimeException('Format bukti harus PDF, JPEG, PNG, atau WebP.');
        $dir = self::evidenceDir();
        try {
            PhotoStorage::protect($dir);
        } catch (RuntimeException $error) {
            throw new RuntimeException('Folder bukti tidak dapat dibuat atau dilindungi.');
        }
        $name = 'bandwidth-' . bin2hex(random_bytes(8)) . '.' . self::EVIDENCE_TYPES[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $name)) throw new RuntimeException('Berkas bukti tidak dapat disimpan.');
        @chmod($dir . DIRECTORY_SEPARATOR . $name, 0600);
        $profiles = self::all($db);
        $settings = self::profile($profiles, $library);
        $old = $settings['evidence']['file'] ?? null;
        $original = mb_substr(basename((string) ($file['name'] ?? 'bukti')), 0, 150);
        $settings['evidence'] = ['file' => $name, 'name' => $original, 'mime' => $mime, 'uploaded_at' => date('Y-m-d H:i:s')];
        $profiles[$library] = $settings;
        self::store($db, $profiles);
        if (is_string($old)) self::removeFile($old);
        return $settings;
    }

    public static function deleteEvidence(PDO $db, string $library = ''): array
    {
        $profiles = self::all($db);
        $settings = self::profile($profiles, $library);
        $old = $settings['evidence']['file'] ?? null;
        $settings['evidence'] = null;
        $profiles[$library] = $settings;
        self::store($db, $profiles);
        if (is_string($old)) self::removeFile($old);
        return $settings;
    }

    private static function removeFile(string $name): void
    {
        if (preg_match('/\Abandwidth-[0-9a-f]{16}\.(pdf|jpg|png|webp)\z/', $name)) @unlink(self::evidenceDir() . DIRECTORY_SEPARATOR . $name);
    }

    /** @return array{path:string,mime:string,name:string}|null */
    public static function evidenceFile(PDO $db, string $library = ''): ?array
    {
        $evidence = self::settings($db, $library)['evidence'];
        if (!is_array($evidence) || !preg_match('/\Abandwidth-[0-9a-f]{16}\.(pdf|jpg|png|webp)\z/', (string) ($evidence['file'] ?? ''))) return null;
        $path = self::evidenceDir() . DIRECTORY_SEPARATOR . $evidence['file'];
        return is_file($path) ? ['path' => $path, 'mime' => (string) $evidence['mime'], 'name' => (string) $evidence['name']] : null;
    }

    // ---- Software register ---------------------------------------------------------------------

    public static function software(PDO $db): array
    {
        return $db->query('SELECT * FROM inventory_software ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function saveSoftware(PDO $db, array $input, int $id, ?int $uid): int
    {
        $text = static function (string $key, string $label, int $max, bool $required = false) use ($input): string {
            $value = trim((string) ($input[$key] ?? ''));
            if ($required && $value === '') throw new RuntimeException("$label wajib diisi.");
            if (mb_strlen($value) > $max) throw new RuntimeException("$label maksimal $max karakter.");
            return $value;
        };
        $licence = (string) ($input['licence'] ?? '');
        if (!isset(self::LICENCES[$licence])) throw new RuntimeException('Pilih jenis lisensi.');
        $until = trim((string) ($input['valid_until'] ?? ''));
        if ($until !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $until);
            if (!$parsed || $parsed->format('Y-m-d') !== $until) throw new RuntimeException('Tanggal berlaku lisensi tidak valid.');
        }
        $installs = (int) ($input['installs'] ?? 1);
        if ($installs < 1 || $installs > 100000) throw new RuntimeException('Jumlah instalasi tidak valid.');
        $notes = (string) ($input['notes'] ?? '');
        if (strlen($notes) > 5000) throw new RuntimeException('Catatan maksimal 5.000 karakter.');
        $values = [
            'name' => $text('name', 'Nama aplikasi', 150, true),
            'version' => $text('version', 'Versi', 50),
            'purpose' => $text('purpose', 'Kegunaan', 255),
            'licence' => $licence,
            'licence_ref' => $text('licence_ref', 'Nomor / bukti lisensi', 255),
            'valid_until' => $until === '' ? null : $until,
            'installs' => $installs,
            'notes' => $notes,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($id > 0) {
            $values['id'] = $id;
            $query = $db->prepare('UPDATE inventory_software SET name=:name, version=:version, purpose=:purpose, licence=:licence, licence_ref=:licence_ref, valid_until=:valid_until, installs=:installs, notes=:notes, updated_at=:updated_at WHERE id=:id');
            $query->execute($values);
            if (!$db->query('SELECT 1 FROM inventory_software WHERE id=' . $id)->fetchColumn()) throw new RuntimeException('Aplikasi tidak ditemukan.');
            return $id;
        }
        $values['created_by'] = $uid;
        $values['created_at'] = $values['updated_at'];
        $db->prepare('INSERT INTO inventory_software (name, version, purpose, licence, licence_ref, valid_until, installs, notes, created_by, created_at, updated_at)
            VALUES (:name, :version, :purpose, :licence, :licence_ref, :valid_until, :installs, :notes, :created_by, :created_at, :updated_at)')->execute($values);
        return (int) $db->lastInsertId();
    }

    public static function deleteSoftware(PDO $db, int $id): void
    {
        $db->prepare('DELETE FROM inventory_software WHERE id=?')->execute([$id]);
    }

    /** A licence counts as legal unless it is marked unlicensed or has expired. */
    public static function licensed(array $row, string $today): bool
    {
        return $row['licence'] !== 'tidak' && ($row['valid_until'] === null || (string) $row['valid_until'] >= $today);
    }

    // ---- Recap ---------------------------------------------------------------------------------

    private static function pct(int $part, int $total): ?float
    {
        return $total > 0 ? round($part * 100 / $total, 1) : null;
    }

    /** a: more than 75 %, b: 51–75 %, c: 50 %, d: below 50 % (the percentage scale used by every percentage aspect). */
    private static function percentLevel(?float $pct): ?string
    {
        if ($pct === null) return null;
        return $pct > 75 ? 'a' : ($pct >= 51 ? 'b' : ($pct >= 50 ? 'c' : 'd'));
    }

    private static function typeKey(array $item): string
    {
        $type = trim((string) $item['item_type']);
        return mb_strtolower($type !== '' ? $type : trim((string) $item['item_name']));
    }

    /** Distinct types among working items (not Rusak berat) of a category: type => item count. */
    private static function types(array $items, string $category): array
    {
        $types = [];
        foreach ($items as $item) {
            if (!in_array($category, $item['categories'], true) || $item['item_condition'] === 'RB') continue;
            $key = self::typeKey($item);
            $label = trim((string) $item['item_type']) !== '' ? trim((string) $item['item_type']) : trim((string) $item['item_name']);
            $types[$key] ??= ['type' => $label, 'count' => 0];
            $types[$key]['count']++;
        }
        ksort($types);
        return array_values($types);
    }

    private static function fmt(float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, ',', '.');
    }

    /**
     * The recap of one location, or of every room when $library is '' (the library as one unit).
     *
     * @return array{generated_at:string,settings:array,aspects:list<array>,summary:array,counts:array}
     */
    public static function recap(PDO $db, Supervision $watch, string $library = ''): array
    {
        $settings = self::settings($db, $library);
        // Counted from active members, not typed in: what the setting may still hold is ignored.
        $settings['sivitas'] = Sivitas::forLocation($db, $library);
        $today = date('Y-m-d');
        [$where, $args] = $library === '' ? ['', []] : [' WHERE l.slims_location_id=?', [$library]];
        $query = $db->prepare('SELECT l.id, l.room_name, l.location_code, l.area_m2, (SELECT location_name FROM mst_location WHERE location_id=l.slims_location_id) library_name FROM inventory_locations l' . $where . ' ORDER BY l.room_name, l.id');
        $query->execute($args);
        $rooms = $query->fetchAll(PDO::FETCH_ASSOC);
        $query = $db->prepare('SELECT i.id, i.location_id, i.item_name, i.category, i.item_type, i.item_condition FROM inventory_items i JOIN inventory_locations l ON l.id=i.location_id' . $where);
        $query->execute($args);
        $items = $query->fetchAll(PDO::FETCH_ASSOC);
        // An item counts under each of its categories.
        foreach ($items as &$item) $item['categories'] = self::categoryCodes($item['category']);
        unset($item);
        // What each room is used for: the kinds of area recorded in it.
        $roomAreas = RoomAreas::byRoom($db, $library);
        $roomFunctions = [];
        foreach ($rooms as $room) $roomFunctions[(int) $room['id']] = array_values(array_unique(array_column($roomAreas[(int) $room['id']] ?? [], 'type')));
        $label = static fn(string $code) => self::AREA_TYPES[$code]['label'] ?? $code;
        // "Area koleksi" inside a sentence: "Komputer di area koleksi".
        $place = static fn(string $code) => mb_strtolower(mb_substr($label($code), 0, 1)) . mb_substr($label($code), 1);
        $group = static fn(array $codes, string $name) => array_values(array_filter($codes, static fn($c) => (self::AREA_TYPES[$c]['group'] ?? '') === $name));
        // Service functions only: a toilet or a car park is a public facility, counted in aspect 10.
        $split = static fn(array $codes): array => [$group($codes, 'dasar'), $group($codes, 'pendukung')];
        $aspects = [];

        // 1. Luas gedung atau ruang
        $roomArea = 0.0; $measured = 0;
        foreach ($rooms as $room) if ($room['area_m2'] !== null) { $roomArea += (float) $room['area_m2']; $measured++; }
        $area = (float) $settings['building_area'] > 0 ? (float) $settings['building_area'] : $roomArea;
        $ratio = $settings['sivitas'] > 0 && $area > 0 ? $area / $settings['sivitas'] : null;
        $large = $area > 750 || ($ratio !== null && $ratio > 0.5);
        $aspects[] = [
            'no' => 1, 'section' => 'Gedung dan ruang', 'title' => 'Luas gedung atau ruang perpustakaan',
            'value' => $area > 0 ? self::fmt($area, $area == floor($area) ? 0 : 2) . ' m²' . ($ratio !== null ? ' · ' . self::fmt($ratio, 2) . ' m²/sivitas' : '') : 'Belum diisi',
            'level' => $area <= 0 ? null : ($large && $settings['designed'] ? 'a' : ($large ? 'b' : ($area >= 750 || ($ratio !== null && $ratio >= 0.5) ? 'c' : 'd'))),
            'basis' => (float) $settings['building_area'] > 0 ? 'Luas gedung dari menu Gedung & Jaringan.' : "Jumlah luas $measured dari " . count($rooms) . ' ruangan yang sudah diisi luasnya.',
            'checks' => [
                ['label' => 'Luas lebih dari 750 m² atau lebih dari 0,5 m² per sivitas', 'ok' => $large],
                ['label' => 'Ada sivitas (anggota aktif) yang dilayani', 'ok' => $settings['sivitas'] > 0],
                ['label' => 'Gedung didesain khusus untuk perpustakaan', 'ok' => (bool) $settings['designed']],
            ],
            'rows' => array_map(static fn($r) => [$r['room_name'], $r['area_m2'] === null ? '—' : self::fmt((float) $r['area_m2'], 2) . ' m²'], $rooms),
            'columns' => ['Ruangan', 'Luas'],
            'fix' => 'Isi luas tiap ruangan di Ruangan & Barang, atau luas gedung di Gedung & Jaringan. Sivitas dihitung dari anggota aktif SLiMS; untuk beberapa lokasi, petakan anggota di Sivitas per Lokasi.',
            'sources' => ['inventory', 'facility'],
        ];

        // 2. Ruang atau area layanan
        [$basic, $support] = $split(array_values(array_unique(array_merge(...array_values($roomFunctions ?: [[]])))));
        $present = array_merge($basic, $support);
        $classified = count(array_filter($roomFunctions));
        $aspects[] = [
            'no' => 2, 'section' => 'Gedung dan ruang', 'title' => 'Ruang atau area layanan perpustakaan',
            'value' => count($basic) . ' dari 4 area dasar · ' . count($support) . ' area pendukung',
            'level' => $classified === 0 ? null : (count($basic) < 4 ? 'd' : (count($support) > 1 ? 'a' : (count($support) === 1 ? 'b' : 'c'))),
            'basis' => "Dari area yang dicatat pada $classified dari " . count($rooms) . ' ruangan.',
            'checks' => array_merge(
                array_map(static fn($code) => ['label' => self::AREA_TYPES[$code]['label'], 'ok' => in_array($code, $basic, true)], array_keys(array_filter(self::AREA_TYPES, static fn($f) => $f['group'] === 'dasar'))),
                [['label' => 'Lebih dari 1 area pendukung' . ($support ? ': ' . implode(', ', array_map($label, $support)) : ''), 'ok' => count($support) > 1]]
            ),
            'rows' => array_map(static fn($r) => [$r['room_name'], implode(', ', array_map(static fn($a) => $label($a['type']) . ($a['name'] !== '' ? ' (' . $a['name'] . ')' : ''), $roomAreas[(int) $r['id']] ?? [])) ?: '—'], $rooms),
            'columns' => ['Ruangan', 'Area'],
            'fix' => 'Buka tiap ruangan, lalu catat areanya di tab Area. Satu ruangan boleh memiliki beberapa area.',
            'sources' => ['inventory'],
        ];

        // 3. Sarana dan prasarana berfungsi baik
        $good = count(array_filter($items, static fn($i) => $i['item_condition'] === 'B'));
        $fair = count(array_filter($items, static fn($i) => $i['item_condition'] === 'KB'));
        $pct = self::pct($good, count($items));
        $aspects[] = [
            'no' => 3, 'section' => 'Kondisi sarana dan prasarana', 'title' => 'Sarana dan prasarana berfungsi baik',
            'value' => $pct === null ? 'Belum ada barang' : self::fmt($pct, 1) . '% berfungsi baik',
            'level' => self::percentLevel($pct),
            'basis' => "$good barang kondisi Baik dari " . count($items) . " barang ($fair Kurang baik, " . (count($items) - $good - $fair) . ' Rusak berat).',
            'checks' => [['label' => 'Lebih dari 75% barang dalam kondisi Baik', 'ok' => $pct !== null && $pct > 75]],
            'rows' => [], 'columns' => [],
            'fix' => 'Perbarui kondisi barang setelah pemeriksaan, atau perbaiki barang yang rusak.',
            'sources' => ['inventory'],
        ];

        // Areas served by working items of some categories, through the rooms they stand in.
        $served = static function (array $categories) use ($items, $roomFunctions): array {
            $codes = [];
            foreach ($items as $item) {
                if (!array_intersect($item['categories'], $categories) || $item['item_condition'] === 'RB') continue;
                foreach ($roomFunctions[(int) $item['location_id']] ?? [] as $code) $codes[$code] = true;
            }
            return array_keys($codes);
        };

        // 4. Perabot dan peralatan
        [$fBasic, $fSupport] = $split($served(['perabot', 'peralatan']));
        $furnished = count(array_filter($items, static fn($i) => (bool) array_intersect($i['categories'], ['perabot', 'peralatan'])));
        $aspects[] = [
            'no' => 4, 'section' => 'Perabot dan peralatan', 'title' => 'Perabot dan peralatan per fungsi layanan',
            'value' => count($fBasic) . ' dari 4 fungsi dasar · ' . count($fSupport) . ' fungsi pendukung',
            'level' => $furnished === 0 || $classified === 0 ? null : (count($fBasic) < 4 ? 'd' : (count($fSupport) > 4 ? 'a' : (count($fSupport) >= 3 ? 'b' : 'c'))),
            'basis' => "$furnished barang berkategori perabot atau peralatan, dihitung menurut area di ruangan tempatnya berada.",
            'checks' => array_merge(
                // Named for what is checked: a bare "Area koleksi" here would read as the area itself missing (that is aspect 2).
                array_map(static fn($code) => ['label' => 'Perabot atau peralatan di ' . $place($code), 'ok' => in_array($code, $fBasic, true)], array_keys(array_filter(self::AREA_TYPES, static fn($f) => $f['group'] === 'dasar'))),
                [['label' => 'Lebih dari 4 fungsi pendukung' . ($fSupport ? ': ' . implode(', ', array_map($label, $fSupport)) : ''), 'ok' => count($fSupport) > 4]]
            ),
            'rows' => [], 'columns' => [],
            'fix' => 'Beri kategori Perabot atau Peralatan pada barang, dan catat area ruangannya di tab Area.',
            'sources' => ['inventory'],
        ];

        // 5. Komputer per fungsi layanan
        $withComputer = $served(['komputer']);
        $computerPct = self::pct(count(array_intersect($withComputer, $present)), count($present));
        $computers = count(array_filter($items, static fn($i) => in_array('komputer', $i['categories'], true)));
        $aspects[] = [
            'no' => 5, 'section' => 'Perangkat TI dan multimedia', 'title' => 'Komputer untuk mendukung fungsi layanan',
            'value' => $computerPct === null ? 'Belum ada area layanan' : self::fmt($computerPct, 1) . '% fungsi layanan',
            'level' => $computers === 0 ? ($present ? 'd' : null) : self::percentLevel($computerPct),
            'basis' => "$computers komputer; " . count(array_intersect($withComputer, $present)) . ' dari ' . count($present) . ' fungsi layanan memiliki komputer yang berfungsi.',
            'checks' => array_map(static fn($code) => ['label' => 'Komputer di ' . $place($code), 'ok' => in_array($code, $withComputer, true)], $present),
            'rows' => [], 'columns' => [],
            'fix' => 'Beri kategori Komputer pada PC dan laptop layanan, lalu pastikan area ruangannya tercatat di tab Area.',
            'sources' => ['inventory'],
        ];

        // 6. Jaringan internet
        $perUser = $settings['bandwidth_users'] > 0 && $settings['bandwidth_mbps'] > 0 ? $settings['bandwidth_mbps'] / $settings['bandwidth_users'] : null;
        $all = $settings['bandwidth_coverage'] === 'all';
        $aspects[] = [
            'no' => 6, 'section' => 'Perangkat TI dan multimedia', 'title' => 'Ketersediaan jaringan internet',
            'value' => $perUser === null ? 'Belum diisi' : self::fmt($perUser, 2) . ' Mbps/orang · ' . mb_strtolower(self::COVERAGE[$settings['bandwidth_coverage']]),
            'level' => $perUser === null ? null : ($perUser > 5 && $all ? 'a' : ($perUser >= 4 && $all ? 'b' : ($perUser >= 3 ? 'c' : 'd'))),
            'basis' => $perUser === null ? 'Isi bandwidth dan jumlah pengguna serentak di menu Gedung & Jaringan.' : self::fmt((float) $settings['bandwidth_mbps'], 0) . ' Mbps untuk ' . $settings['bandwidth_users'] . ' pengguna serentak' . ($settings['bandwidth_date'] ? ', diukur ' . PdfLayout::date($settings['bandwidth_date']) : '') . '.',
            'checks' => [
                ['label' => 'Lebih dari 5 Mbps per orang', 'ok' => $perUser !== null && $perUser > 5],
                ['label' => 'Menjangkau seluruh area layanan', 'ok' => $all && $perUser !== null],
                ['label' => 'Bukti pengukuran diunggah', 'ok' => is_array($settings['evidence'])],
            ],
            'rows' => [], 'columns' => [],
            'fix' => 'Ukur bandwidth saat jam sibuk, lalu isi dan unggah buktinya di Gedung & Jaringan.',
            'sources' => ['facility'],
        ];

        // 7. Perangkat multimedia
        $multimedia = self::types($items, 'multimedia');
        $aspects[] = self::typeAspect(7, 'Perangkat TI dan multimedia', 'Perangkat multimedia tersedia, digunakan, dan terpelihara', $multimedia,
            count($multimedia) > 5 ? 'a' : (count($multimedia) >= 4 ? 'b' : (count($multimedia) === 3 ? 'c' : 'd')),
            'Lebih dari 5 jenis perangkat multimedia yang berfungsi', 'Beri kategori Multimedia pada barang, lalu isi jenisnya, misalnya Proyektor. Pemeliharaannya tercatat lewat pemeriksaan rutin di menu Tugas.');

        // 8. Legalitas perangkat lunak
        $software = self::software($db);
        $legal = count(array_filter($software, static fn($s) => self::licensed($s, $today)));
        $softwarePct = self::pct($legal, count($software));
        $aspects[] = [
            // The register is one for the institution, so this aspect is the same at every location.
            'no' => 8, 'shared' => true, 'section' => 'Perangkat TI dan multimedia', 'title' => 'Legalitas perangkat lunak',
            'value' => $softwarePct === null ? 'Belum ada aplikasi' : self::fmt($softwarePct, 1) . '% berlisensi resmi',
            'level' => self::percentLevel($softwarePct),
            'basis' => "$legal dari " . count($software) . ' aplikasi berlisensi resmi (termasuk open source); lisensi kedaluwarsa dihitung tidak berlisensi.',
            'checks' => [['label' => 'Lebih dari 75% aplikasi berlisensi resmi', 'ok' => $softwarePct !== null && $softwarePct > 75]],
            'rows' => array_map(static fn($s) => [$s['name'] . ($s['version'] !== '' ? ' ' . $s['version'] : ''), (self::LICENCES[$s['licence']] ?? $s['licence']) . (self::licensed($s, $today) ? '' : ($s['licence'] === 'tidak' ? '' : ' (kedaluwarsa)'))], $software),
            'columns' => ['Aplikasi', 'Lisensi'],
            'fix' => 'Catat semua aplikasi yang dipakai perpustakaan di menu Perangkat Lunak.',
            'sources' => ['software'],
        ];

        // 9. Sarana keamanan
        $security = self::types($items, 'keamanan');
        $aspects[] = self::typeAspect(9, 'Keamanan dan fasilitas umum', 'Sarana keamanan dan keselamatan', $security,
            count($security) > 5 ? 'a' : (count($security) === 5 ? 'b' : (count($security) === 4 ? 'c' : 'd')),
            'Lebih dari 5 jenis sarana keamanan', 'Beri kategori Keamanan pada barang, lalu isi jenisnya, misalnya APAR, CCTV, security gate, alarm, atau jalur evakuasi.');

        // 10. Fasilitas umum: areas of that kind in the rooms, and items of the category.
        $public = [];
        foreach ($roomAreas as $areas) {
            foreach ($group(array_column($areas, 'type'), 'umum') as $code) {
                $public[mb_strtolower($label($code))] ??= ['type' => $label($code), 'count' => 0];
                $public[mb_strtolower($label($code))]['count']++;
            }
        }
        // A facility recorded both ways is one facility: the area stands and the item adds nothing to it.
        foreach (self::types($items, 'fasilitas_umum') as $type) $public[mb_strtolower($type['type'])] ??= $type;
        ksort($public);
        $public = array_values($public);
        $aspects[] = self::typeAspect(10, 'Keamanan dan fasilitas umum', 'Ketersediaan fasilitas umum', $public,
            count($public) > 6 ? 'a' : (count($public) === 6 ? 'b' : (count($public) === 5 ? 'c' : 'd')),
            'Lebih dari 6 jenis fasilitas umum', 'Catat toilet, musala, parkir, kantin, atau ruang laktasi sebagai area di ruangannya (tab Area). Barang seperti dispenser air minum dicatat sebagai barang berkategori Fasilitas umum.',
            'Jenis berbeda dari area fasilitas umum di ruangan dan dari barang yang berfungsi (tidak Rusak berat).');

        // 11. Pengawasan dan pemeliharaan, over the last twelve months.
        $filter = $watch->filter(['from' => (new \DateTimeImmutable('-1 year +1 day'))->format('Y-m-d'), 'to' => $today, 'library' => $library]);
        $summary = $watch->summary($filter);
        [$where, $args] = $watch->where($filter);
        $findingTotal = (int) $summary['findings']['open'] + (int) $summary['findings']['closed'];
        $documented = (int) $watch->query("SELECT COUNT(*) FROM inventory_watch_findings f JOIN inventory_watch_inspections i ON i.id=f.inspection_id WHERE $where AND (f.status='closed' OR EXISTS (SELECT 1 FROM inventory_watch_actions a WHERE a.finding_id=f.id AND a.submitted_at IS NOT NULL))", $args)->fetchColumn();
        $routine = (int) $summary['counts']['routine_final'];
        $planned = (int) $summary['planned'];
        $donePct = self::pct($routine, $planned);
        $allRooms = count($summary['missing_rooms']) === 0 && count($rooms) > 0;
        $followed = $documented >= $findingTotal;
        $any = (int) $summary['counts']['total'] > 0;
        $aspects[] = [
            'no' => 11, 'section' => 'Pengawasan dan pemeliharaan', 'title' => 'Pengawasan berkala dan tindak lanjut',
            'value' => $planned ? "$routine dari $planned pemeriksaan terjadwal selesai" : ($any ? 'Hanya pemeriksaan insidental' : 'Belum ada pemeriksaan'),
            'level' => !$any && !$planned ? 'd' : ($routine === 0 ? 'c' : ($allRooms && $donePct !== null && $donePct >= 90 && $followed ? 'a' : 'b')),
            'basis' => '12 bulan terakhir (' . PdfLayout::date($filter['from']) . ' – ' . PdfLayout::date($filter['to']) . "). $documented dari $findingTotal temuan memiliki tindak lanjut terdokumentasi.",
            'checks' => [
                ['label' => 'Semua ruangan memiliki jadwal pemeriksaan rutin' . ($allRooms ? '' : ' (' . count($summary['missing_rooms']) . ' belum)'), 'ok' => $allRooms],
                ['label' => 'Minimal 90% pemeriksaan terjadwal selesai' . ($donePct !== null ? ' (' . self::fmt($donePct, 1) . '%)' : ''), 'ok' => $donePct !== null && $donePct >= 90],
                ['label' => 'Semua temuan memiliki tindak lanjut terdokumentasi', 'ok' => $followed],
            ],
            'rows' => array_map(static fn($r) => [$r['room_name'], (string) ($r['location_name'] ?? '—')], $summary['missing_rooms']),
            'columns' => ['Ruangan tanpa jadwal', 'Perpustakaan'],
            'fix' => 'Buat jadwal untuk ruangan yang belum, selesaikan pemeriksaan tepat waktu, dan catat pekerjaan untuk setiap temuan.',
            'sources' => ['schedules'],
        ];

        foreach ($aspects as &$aspect) $aspect['name'] = self::NAMES[$aspect['no']];
        unset($aspect);
        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'settings' => $settings,
            'aspects' => $aspects,
            'summary' => self::summary($aspects),
            'counts' => ['rooms' => count($rooms), 'items' => count($items), 'uncategorized' => count(array_filter($items, static fn($i) => !$i['categories'])), 'unclassified_rooms' => count($rooms) - $classified, 'no_area' => count($rooms) - $measured],
        ];
    }

    /** How many aspects reach each level, and how many have no data. */
    private static function summary(array $aspects): array
    {
        $levels = array_count_values(array_map(static fn($aspect) => $aspect['level'] ?? '-', $aspects));
        return ['a' => $levels['a'] ?? 0, 'b' => $levels['b'] ?? 0, 'c' => $levels['c'] ?? 0, 'd' => $levels['d'] ?? 0, 'empty' => $levels['-'] ?? 0];
    }

    // ---- Institution ---------------------------------------------------------------------------

    private const SCORES = ['a' => 4, 'b' => 3, 'c' => 2, 'd' => 1];

    /**
     * One recap for the institution out of its locations' recaps, in the shape of a location's
     * recap. An aspect takes the average of the locations that have data for it (Sangat baik 4 …
     * Kurang 1, rounded to the nearest level, a half going up); locations without data are left
     * out of the average and named beside it. Its rows are the locations, the weakest first.
     *
     * @param array<string,array> $recaps location code => its recap
     * @param array<string,string> $names location code => its name
     */
    public static function combine(array $recaps, array $names): array
    {
        $aspects = [];
        foreach ((reset($recaps) ?: ['aspects' => []])['aspects'] as $index => $base) {
            if (!empty($base['shared'])) {
                $aspects[] = $base;
                continue;
            }
            $rows = [];
            foreach ($recaps as $code => $recap) {
                $aspect = $recap['aspects'][$index];
                $rows[] = ['code' => (string) $code, 'name' => $names[$code] ?? (string) $code, 'level' => $aspect['level'], 'value' => $aspect['value']];
            }
            // Weakest first; locations without data come last.
            usort($rows, static fn($x, $y) => [$x['level'] === null, $x['level'] === null ? 0 : self::SCORES[$x['level']], $x['name']] <=> [$y['level'] === null, $y['level'] === null ? 0 : self::SCORES[$y['level']], $y['name']]);
            $scored = array_values(array_filter($rows, static fn($row) => $row['level'] !== null));
            $empty = count($rows) - count($scored);
            $average = $scored ? array_sum(array_map(static fn($row) => self::SCORES[$row['level']], $scored)) / count($scored) : null;
            $spread = array_count_values(array_column($scored, 'level'));
            $parts = [];
            foreach (self::LEVELS as $level => $label) if (!empty($spread[$level])) $parts[] = $label . ' ' . $spread[$level];
            if ($empty) $parts[] = $empty . ' belum ada data';
            // Where to look first: the weakest locations.
            $targets = array_filter($scored, static fn($row) => $row['level'] === $scored[0]['level']);
            $aspects[] = [
                'no' => $base['no'], 'section' => $base['section'], 'title' => $base['title'], 'name' => $base['name'],
                'value' => $scored ? 'Rata-rata ' . count($scored) . ($empty ? ' dari ' . count($rows) : '') . ' lokasi' : 'Belum ada data',
                'level' => $average === null ? null : ($average >= 3.5 ? 'a' : ($average >= 2.5 ? 'b' : ($average >= 1.5 ? 'c' : 'd'))),
                'basis' => 'Dari ' . count($rows) . ' lokasi: ' . implode(' · ', $parts) . '.',
                'checks' => [],
                'rows' => array_map(static fn($row) => [$row['name'], $row['level'] === null ? 'Belum ada data' : self::LEVELS[$row['level']], $row['value']], $rows),
                'columns' => ['Lokasi', 'Kondisi', 'Capaian'],
                'fix' => $scored ? 'Buka rekap lokasi dengan kondisi terendah untuk melihat cara memperbaikinya.' : 'Belum ada lokasi yang memiliki data untuk aspek ini. Pilih lokasi di Perbandingan lokasi untuk melihat data yang perlu dilengkapi.',
                'sources' => [],
                'targets' => array_map(static fn($row) => ['code' => $row['code'], 'name' => $row['name']], array_slice(array_values($targets), 0, 3)),
            ];
        }
        return ['generated_at' => date('Y-m-d H:i:s'), 'aspects' => $aspects, 'summary' => self::summary($aspects)];
    }

    /**
     * Every location's recap in brief, and the institution's recap combined from them.
     *
     * @param list<array{code:string,name:string,rooms:int}> $locations
     * @return array{locations:list<array>,recap:array}
     */
    public static function overview(PDO $db, Supervision $watch, array $locations): array
    {
        $recaps = [];
        foreach ($locations as $location) $recaps[$location['code']] = self::recap($db, $watch, $location['code']);
        return [
            'locations' => array_map(static fn($location) => $location + ['summary' => $recaps[$location['code']]['summary']], $locations),
            'recap' => self::combine($recaps, array_column($locations, 'name', 'code')),
        ];
    }

    private static function typeAspect(int $no, string $section, string $title, array $types, string $level, string $check, string $fix, string $basis = 'Jenis berbeda dari barang yang berfungsi (tidak Rusak berat).'): array
    {
        return [
            'no' => $no, 'section' => $section, 'title' => $title,
            'value' => count($types) . ' jenis',
            'level' => $types ? $level : null,
            'basis' => $basis,
            'checks' => [['label' => $check, 'ok' => $level === 'a']],
            'rows' => array_map(static fn($t) => [$t['type'], (string) $t['count']], $types),
            'columns' => ['Jenis', 'Jumlah'],
            'fix' => $fix,
            'sources' => ['inventory'],
        ];
    }
}
