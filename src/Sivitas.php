<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

use PDO;
use PDOException;
use RuntimeException;

require_once __DIR__ . '/Sarpras.php';

/**
 * Sivitas: the people a library serves, counted from SLiMS's active members (not pending, not
 * expired), less the member types the library leaves out (an external member is not sivitas).
 *
 * SLiMS does not record where a member belongs. Where the library has several locations, each
 * member is placed by, in turn: their Institusi (member.inst_name, free text, compared by key so
 * case and spacing do not matter), their member type, the default location, or nowhere ("belum
 * dipetakan"). A library that is one unit needs none of this: everyone counts toward it.
 *
 * Institusi is typed by hand, so the same programme turns up spelled several ways. merge() puts
 * those right in SLiMS itself, and keeps what it changed so undo() can put it back.
 */
final class Sivitas
{
    public const SETTING = 'inventory_sivitas';
    public const BASES = ['institution', 'type'];

    /** Words that name no place: they appear in most location names, so they decide nothing. */
    private const COMMON_WORDS = ['perpustakaan', 'perpus', 'kampus', 'campus', 'ruang', 'room', 'reference', 'referensi', 'library', 'gedung', 'pusat', 'cabang', 'unit', 'lantai', 'sekolah', 'program', 'studi'];

    /** Above this many distinct values, similar spellings are only matched by exact key. */
    private const FUZZY_LIMIT = 2000;

    /** The key two spellings of one Institusi share: lower case, single spaces. */
    public static function key(string $value): string
    {
        return mb_substr(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value))), 0, 100);
    }

    /** Looser still, for spotting typos: punctuation gone too. */
    private static function loose(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value))));
    }

    // ---- Settings and mappings ----------------------------------------------------------------

    /** @return array{default:string,excluded:list<int>} */
    public static function settings(PDO $db): array
    {
        $query = $db->prepare('SELECT setting_value FROM setting WHERE setting_name=?');
        $query->execute([self::SETTING]);
        $value = $query->fetchColumn();
        $stored = is_string($value) ? @unserialize($value, ['allowed_classes' => false]) : [];
        $stored = is_array($stored) ? $stored : [];
        return [
            'default' => is_string($stored['default'] ?? null) ? $stored['default'] : '',
            'excluded' => array_values(array_map('intval', is_array($stored['excluded'] ?? null) ? $stored['excluded'] : [])),
        ];
    }

    /** @param array{default?:mixed,excluded?:mixed} $input */
    public static function saveSettings(PDO $db, array $input): array
    {
        $default = trim((string) ($input['default'] ?? ''));
        if ($default !== '') self::assertLocation($db, $default);
        $types = array_map('intval', array_column(self::types($db), 'id'));
        $excluded = is_array($input['excluded'] ?? null) ? $input['excluded'] : array_filter(explode(',', (string) ($input['excluded'] ?? '')), 'strlen');
        $excluded = array_values(array_unique(array_map('intval', $excluded)));
        if (array_diff($excluded, $types)) throw new RuntimeException('Tipe anggota tidak dikenal.');
        sort($excluded);
        $settings = ['default' => $default, 'excluded' => $excluded];
        $value = serialize($settings);
        $update = $db->prepare('UPDATE setting SET setting_value=? WHERE setting_name=?');
        $update->execute([$value, self::SETTING]);
        if ($update->rowCount() === 0 && !$db->query("SELECT 1 FROM setting WHERE setting_name='" . self::SETTING . "'")->fetchColumn()) {
            $db->prepare('INSERT INTO setting (setting_name, setting_value) VALUES (?, ?)')->execute([self::SETTING, $value]);
        }
        return $settings;
    }

    /**
     * Where Institusi values and member types are placed. Empty until the plugin's migration 14
     * has run, so the recap keeps working before it.
     *
     * @return array{institution:array<string,string>,type:array<string,string>}
     */
    public static function maps(PDO $db): array
    {
        $maps = ['institution' => [], 'type' => []];
        try {
            foreach ($db->query('SELECT basis, value_key, location_code FROM inventory_member_locations') as $row) {
                if (isset($maps[$row['basis']])) $maps[$row['basis']][(string) $row['value_key']] = (string) $row['location_code'];
            }
        } catch (PDOException $error) {
            if (!in_array((int) ($error->errorInfo[1] ?? 0), [1146, 1], true)) throw $error;
        }
        return $maps;
    }

    /**
     * Places an Institusi value (by its key) or a member type at a location; an empty location
     * removes the placement.
     */
    public static function map(PDO $db, string $basis, string $value, string $location, string $now): void
    {
        if (!in_array($basis, self::BASES, true)) throw new RuntimeException('Dasar pemetaan tidak dikenal.');
        if ($basis === 'type') {
            if (!in_array((int) $value, array_map('intval', array_column(self::types($db), 'id')), true)) throw new RuntimeException('Tipe anggota tidak dikenal.');
            $key = (string) (int) $value;
        } else {
            $key = self::key($value);
        }
        $db->prepare('DELETE FROM inventory_member_locations WHERE basis=? AND value_key=?')->execute([$basis, $key]);
        if ($location === '') return;
        self::assertLocation($db, $location);
        $db->prepare('INSERT INTO inventory_member_locations (basis, value_key, label, location_code, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$basis, $key, mb_substr(trim($value), 0, 100), $location, $now, $now]);
    }

    private static function assertLocation(PDO $db, string $code): void
    {
        $query = $db->prepare('SELECT 1 FROM mst_location WHERE location_id=?');
        $query->execute([$code]);
        if (!$query->fetchColumn()) throw new RuntimeException('Lokasi perpustakaan tidak dikenal.');
    }

    // ---- Counting ------------------------------------------------------------------------------

    /** The member filter: not pending, and not expired today. */
    private static function active(string $alias = ''): string
    {
        return "COALESCE({$alias}is_pending, 0) = 0 AND ({$alias}expire_date IS NULL OR {$alias}expire_date >= ?)";
    }

    /** Library locations as SLiMS lists them. @return list<array{code:string,name:string}> */
    public static function locations(PDO $db): array
    {
        return array_map(
            static fn(array $row) => ['code' => (string) $row['code'], 'name' => (string) $row['name']],
            $db->query('SELECT location_id code, location_name name FROM mst_location ORDER BY location_name, location_id')->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /** Whether the library counts as one unit: as Rekap Sarpras sees it, rooms in one location or none. */
    public static function single(PDO $db): bool
    {
        $places = Sarpras::locations($db);
        return $places['rooms'] === 0 || count($places['locations']) <= 1;
    }

    /**
     * Active members counted as sivitas, per location.
     *
     * @return array{total:int,unmapped:int,single:bool,locations:array<string,int>}
     */
    public static function counts(PDO $db, ?string $today = null): array
    {
        $settings = self::settings($db);
        $maps = self::maps($db);
        $single = self::single($db);
        $excluded = array_flip($settings['excluded']);
        $query = $db->prepare('SELECT inst_name, member_type_id, COUNT(*) n FROM member WHERE ' . self::active() . ' GROUP BY inst_name, member_type_id');
        $query->execute([$today ?? date('Y-m-d')]);
        $result = ['total' => 0, 'unmapped' => 0, 'single' => $single, 'locations' => []];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($excluded[(int) $row['member_type_id']])) continue;
            $count = (int) $row['n'];
            $result['total'] += $count;
            if ($single) continue;
            $location = $maps['institution'][self::key((string) $row['inst_name'])]
                ?? $maps['type'][(string) (int) $row['member_type_id']]
                ?? ($settings['default'] !== '' ? $settings['default'] : null);
            if ($location === null) {
                $result['unmapped'] += $count;
            } else {
                $result['locations'][$location] = ($result['locations'][$location] ?? 0) + $count;
            }
        }
        ksort($result['locations'], SORT_STRING);
        return $result;
    }

    /** Sivitas of one location, or of the whole library ('' or a library that is one unit). */
    public static function forLocation(PDO $db, string $library = ''): int
    {
        $counts = self::counts($db);
        return $library === '' || $counts['single'] ? $counts['total'] : ($counts['locations'][$library] ?? 0);
    }

    // ---- What there is to map -----------------------------------------------------------------

    /** Member types, with how many active members each has. @return list<array{id:int,name:string,active:int}> */
    public static function types(PDO $db, ?string $today = null): array
    {
        $query = $db->prepare('SELECT t.member_type_id id, t.member_type_name name, (SELECT COUNT(*) FROM member m WHERE m.member_type_id=t.member_type_id AND ' . self::active('m.') . ') active FROM mst_member_type t ORDER BY t.member_type_name, t.member_type_id');
        $query->execute([$today ?? date('Y-m-d')]);
        return array_map(static fn(array $row) => ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'active' => (int) $row['active']], $query->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Every Institusi value in use, grouped by key, with its spellings, members (all, and active
     * sivitas), the location it is placed at, and a suggested one.
     *
     * @return list<array{key:string,label:string,variants:list<array{value:string,members:int,active:int}>,members:int,active:int,location:string,suggestion:?string}>
     */
    public static function institutions(PDO $db, ?string $today = null): array
    {
        $excluded = self::settings($db)['excluded'];
        $skip = $excluded ? ' AND member_type_id NOT IN (' . implode(',', $excluded) . ')' : '';
        // Spellings told apart exactly: MySQL would otherwise fold "Gizi" and "gizi" into one row.
        $query = $db->prepare('SELECT MIN(inst_name) value, COUNT(*) members, SUM(CASE WHEN ' . self::active() . $skip . ' THEN 1 ELSE 0 END) active FROM member GROUP BY ' . self::exact($db, 'inst_name'));
        $query->execute([$today ?? date('Y-m-d')]);
        $groups = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $value = trim((string) $row['value']);
            $key = self::key($value);
            $groups[$key]['variants'][] = ['value' => (string) $row['value'], 'members' => (int) $row['members'], 'active' => (int) $row['active']];
        }
        $maps = self::maps($db)['institution'];
        $suggest = self::suggester(self::locations($db));
        $rows = [];
        foreach ($groups as $key => $group) {
            $variants = $group['variants'];
            usort($variants, static fn($a, $b) => [$b['members'], $a['value']] <=> [$a['members'], $b['value']]);
            $key = (string) $key;
            $rows[] = [
                'key' => $key,
                'label' => $key === '' ? '' : trim($variants[0]['value']),
                'variants' => $variants,
                'members' => array_sum(array_column($variants, 'members')),
                'active' => array_sum(array_column($variants, 'active')),
                'location' => $maps[$key] ?? '',
                'suggestion' => $key === '' ? null : $suggest($key),
            ];
        }
        usort($rows, static fn($a, $b) => [$b['active'], $a['label']] <=> [$a['active'], $b['label']]);
        return $rows;
    }

    /**
     * Suggests a location for an Institusi from the words that tell locations apart: "Keperawatan
     * Blora …" goes to the one location whose name says Blora. A word several locations share
     * (two campuses in one town) suggests nothing.
     *
     * @param list<array{code:string,name:string}> $locations
     * @return callable(string):?string
     */
    public static function suggester(array $locations): callable
    {
        $owners = [];
        foreach ($locations as $location) {
            foreach (array_unique(self::words($location['name'])) as $word) $owners[$word][] = $location['code'];
        }
        $telling = array_map(static fn(array $codes) => $codes[0], array_filter($owners, static fn(array $codes) => count($codes) === 1));
        return static function (string $value) use ($telling): ?string {
            $found = array_values(array_unique(array_filter(array_map(static fn($word) => $telling[$word] ?? null, self::words($value)))));
            return count($found) === 1 ? $found[0] : null;
        };
    }

    /** Words that may name a place: four letters or more, no numerals, not a common word. @return list<string> */
    private static function words(string $text): array
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_filter($words, static fn($word) => mb_strlen($word) >= 4
            && !in_array($word, self::COMMON_WORDS, true)
            && preg_match('/^[ivxlcdm]+$/', $word) !== 1));
    }

    /**
     * Institusi values that look like one value typed differently: the same but for case, spacing
     * or punctuation, or a letter or two apart. Values naming a different number or level ("D-III"
     * and "D-IV", "Kelas 7A" and "Kelas 7B") or a different one-letter word are left apart.
     * The suggested spelling is the one most members have.
     *
     * @return list<array{variants:list<array{value:string,members:int}>,suggestion:string}>
     */
    public static function similar(PDO $db): array
    {
        $values = [];
        foreach ($db->query("SELECT MIN(inst_name) value, COUNT(*) members FROM member WHERE inst_name IS NOT NULL AND TRIM(inst_name) <> '' GROUP BY " . self::exact($db, 'inst_name')) as $row) {
            $values[] = ['value' => (string) $row['value'], 'members' => (int) $row['members'], 'loose' => self::loose((string) $row['value'])];
        }
        $count = count($values);
        $parent = range(0, max(0, $count - 1));
        $find = static function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) $i = $parent[$i] = $parent[$parent[$i]];
            return $i;
        };
        $join = static function (int $a, int $b) use (&$parent, $find): void {
            $parent[$find($a)] = $find($b);
        };
        $byLoose = [];
        foreach ($values as $i => $value) $byLoose[$value['loose']][] = $i;
        foreach ($byLoose as $same) foreach (array_slice($same, 1) as $i) $join($same[0], $i);
        if ($count <= self::FUZZY_LIMIT) {
            $keys = array_keys($byLoose);
            foreach ($keys as $a => $left) {
                for ($b = $a + 1, $n = count($keys); $b < $n; $b++) {
                    if (self::typo((string) $left, (string) $keys[$b])) $join($byLoose[$left][0], $byLoose[$keys[$b]][0]);
                }
            }
        }
        $groups = [];
        foreach ($values as $i => $value) $groups[$find($i)][] = ['value' => $value['value'], 'members' => $value['members']];
        $result = [];
        foreach ($groups as $variants) {
            if (count($variants) < 2) continue;
            usort($variants, static fn($a, $b) => [$b['members'], $a['value']] <=> [$a['members'], $b['value']]);
            $result[] = ['variants' => $variants, 'suggestion' => trim((string) preg_replace('/\s+/u', ' ', $variants[0]['value']))];
        }
        usort($result, static fn($a, $b) => array_sum(array_column($b['variants'], 'members')) <=> array_sum(array_column($a['variants'], 'members')));
        return $result;
    }

    /**
     * The numbers and levels a value names, which tell programmes and classes apart however close
     * the rest is: "D-III", "D III" and "DIII" are all d3, "DIV" is d4, "Kelas 7A" is 7a.
     */
    private static function marks(string $loose): string
    {
        $roman = ['iii' => '3', 'ii' => '2', 'iv' => '4', 'vi' => '6', 'v' => '5', 'i' => '1'];
        $text = (string) preg_replace_callback('/\b([ds]) ?(iii|ii|iv|vi|v|i|\d)\b/u', static fn(array $m) => $m[1] . ($roman[$m[2]] ?? $m[2]), $loose);
        preg_match_all('/\b(?:\p{L}*\d+\p{L}*|ii|iii|iv|vi|vii|viii|ix|x)\b/u', $text, $found);
        return implode(' ', $found[0]);
    }

    /** Whether two loose keys read as one value mistyped. */
    private static function typo(string $a, string $b): bool
    {
        $shorter = min(mb_strlen($a), mb_strlen($b));
        if ($shorter < 8 || abs(mb_strlen($a) - mb_strlen($b)) > 2) return false;
        if (self::marks($a) !== self::marks($b)) return false;
        $distance = levenshtein($a, $b);
        if ($distance > ($shorter >= 20 ? 2 : 1)) return false;
        // Differing only in a number or a one-letter word: different classes, not a typo.
        $left = explode(' ', $a);
        $right = explode(' ', $b);
        if (count($left) === count($right)) {
            $diff = array_keys(array_diff_assoc($left, $right));
            if (count($diff) === 1) {
                [$x, $y] = [$left[$diff[0]], $right[$diff[0]]];
                if (mb_strlen($x) <= 2 || mb_strlen($y) <= 2) return false;
            }
        }
        return true;
    }

    // ---- Putting Institusi right in SLiMS ----------------------------------------------------

    /** MySQL compares text without regard to case; a merge must match spellings exactly. */
    private static function exact(PDO $db, string $column): string
    {
        return $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? "BINARY $column" : $column;
    }

    /**
     * Rewrites the Institusi of every member (active or not) spelled one of $from to $to, and
     * records each member's old spelling for undo(). Where $to has no location yet, it takes the
     * one its spellings had.
     *
     * @param list<string> $from
     * @return array{id:int,count:int}
     */
    public static function merge(PDO $db, array $from, string $to, int $uid, string $now): array
    {
        $to = trim((string) preg_replace('/\s+/u', ' ', $to));
        if ($to === '' || mb_strlen($to) > 100) throw new RuntimeException('Isi nama institusi yang benar, maksimal 100 karakter.');
        $from = array_values(array_unique(array_filter(array_map('strval', $from), static fn($value) => $value !== $to && trim($value) !== '')));
        if (!$from || count($from) > 50) throw new RuntimeException('Pilih 1–50 ejaan yang akan digabungkan.');
        $db->beginTransaction();
        try {
            $marks = implode(',', array_fill(0, count($from), '?'));
            $query = $db->prepare('SELECT member_id, inst_name FROM member WHERE ' . self::exact($db, 'inst_name') . " IN ($marks)");
            $query->execute($from);
            $members = $query->fetchAll(PDO::FETCH_NUM);
            if (!$members) throw new RuntimeException('Tidak ada anggota dengan ejaan tersebut. Muat ulang halaman.');
            $update = $db->prepare('UPDATE member SET inst_name=?, last_update=? WHERE member_id=? AND ' . self::exact($db, 'inst_name') . '=?');
            foreach ($members as [$id, $old]) $update->execute([$to, substr($now, 0, 10), $id, $old]);
            $maps = self::maps($db)['institution'];
            $target = self::key($to);
            if (!isset($maps[$target])) {
                $placed = array_values(array_unique(array_filter(array_map(static fn($value) => $maps[self::key($value)] ?? null, $from))));
                if (count($placed) === 1) self::map($db, 'institution', $to, $placed[0], $now);
            }
            $db->prepare('INSERT INTO inventory_member_fixes (from_values, to_value, members, member_count, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([json_encode($from, JSON_UNESCAPED_UNICODE), $to, json_encode($members, JSON_UNESCAPED_UNICODE), count($members), $uid, $now]);
            $id = (int) $db->lastInsertId();
            $db->commit();
            return ['id' => $id, 'count' => count($members)];
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    /** Puts back the spellings a merge changed, for members still spelled as it left them. */
    public static function undo(PDO $db, int $fixId, string $now): int
    {
        $query = $db->prepare('SELECT * FROM inventory_member_fixes WHERE id=?');
        $query->execute([$fixId]);
        $fix = $query->fetch(PDO::FETCH_ASSOC);
        if (!$fix) throw new RuntimeException('Perbaikan tidak ditemukan.');
        if ($fix['undone_at'] !== null) throw new RuntimeException('Perbaikan ini sudah diurungkan.');
        $db->beginTransaction();
        try {
            $restore = $db->prepare('UPDATE member SET inst_name=?, last_update=? WHERE member_id=? AND ' . self::exact($db, 'inst_name') . '=?');
            $restored = 0;
            foreach (json_decode((string) $fix['members'], true) ?: [] as [$id, $old]) {
                $restore->execute([$old, substr($now, 0, 10), $id, $fix['to_value']]);
                $restored += $restore->rowCount();
            }
            $db->prepare('UPDATE inventory_member_fixes SET undone_at=? WHERE id=?')->execute([$now, $fixId]);
            $db->commit();
            return $restored;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    /** Recent merges, newest first. @return list<array<string,mixed>> */
    public static function fixes(PDO $db, int $limit = 20): array
    {
        try {
            $rows = $db->query('SELECT f.id, f.from_values, f.to_value, f.member_count, f.created_at, f.undone_at, u.realname FROM inventory_member_fixes f LEFT JOIN user u ON u.user_id=f.user_id ORDER BY f.id DESC LIMIT ' . $limit)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $error) {
            if (!in_array((int) ($error->errorInfo[1] ?? 0), [1146, 1], true)) throw $error;
            return [];
        }
        return array_map(static fn(array $row) => [
            'id' => (int) $row['id'],
            'from' => json_decode((string) $row['from_values'], true) ?: [],
            'to' => (string) $row['to_value'],
            'count' => (int) $row['member_count'],
            'created_at' => (string) $row['created_at'],
            'undone_at' => $row['undone_at'] === null ? null : (string) $row['undone_at'],
            'by' => (string) ($row['realname'] ?? ''),
        ], $rows);
    }
}
