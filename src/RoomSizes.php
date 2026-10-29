<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

require_once __DIR__ . '/PdfLayout.php';
require_once __DIR__ . '/PdfDocuments.php';
require_once __DIR__ . '/RoomAreas.php';
require_once __DIR__ . '/Sarpras.php';

/**
 * Daftar area dan luas ruangan: every room with its floor area and the areas inside it, under
 * its library location with the location's total, then the areas outside any room (a car park,
 * a canteen). No photos. An area has no floor area of its own: the room's is the one recorded.
 *
 * The SQL is plain, so the tests run it on SQLite.
 */
final class RoomSizes
{
    public const MAX_ROOMS = 1000;

    /** Where a room of no library location is listed. */
    public const NO_LIBRARY = 'Lokasi belum ditentukan';

    /**
     * @return array{library:string,rooms:int,areas:int,outside:int,total_m2:float,unsized:int,locations:list<array{code:string,name:string,total_m2:float,unsized:int,rooms:list<array{id:int,name:string,code:string,area_m2:?float,areas:list<array{type:string,label:string,name:string}>}>,outside:list<array{type:string,label:string,name:string}>}>}
     */
    public static function build(PDO $db, string $library = ''): array
    {
        $query = $db->prepare('SELECT id, room_name, location_code, area_m2, slims_location_id FROM inventory_locations'
            . ($library === '' ? '' : ' WHERE slims_location_id = ?')
            . ' ORDER BY room_name, id');
        $query->execute($library === '' ? [] : [$library]);
        $rooms = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($rooms) > self::MAX_ROOMS) throw new RuntimeException('Daftar memuat lebih dari ' . self::MAX_ROOMS . ' ruangan. Saring daftar ruangan ke satu lokasi perpustakaan.');

        $order = array_flip(array_keys(Sarpras::AREA_TYPES));
        $area = static fn(array $row): array => ['type' => (string) $row['type'], 'label' => Sarpras::AREA_TYPES[$row['type']]['label'], 'name' => trim((string) $row['name'])];
        $sort = static fn(array $a, array $b): int => [$order[$a['type']], $a['name']] <=> [$order[$b['type']], $b['name']];
        $inside = RoomAreas::byRoom($db, $library);
        $names = [];
        foreach ($db->query('SELECT location_id, location_name FROM mst_location')->fetchAll(PDO::FETCH_NUM) as [$code, $name]) $names[(string) $code] = (string) $name;

        $locations = [];
        $at = static function (string $code) use (&$locations, $names): string {
            $locations[$code] ??= ['code' => $code, 'name' => $code === '' ? self::NO_LIBRARY : (string) ($names[$code] ?? $code), 'total_m2' => 0.0, 'unsized' => 0, 'rooms' => [], 'outside' => []];
            return $code;
        };
        $areas = 0;
        foreach ($rooms as $room) {
            $code = $at((string) ($room['slims_location_id'] ?? ''));
            $size = $room['area_m2'] === null || $room['area_m2'] === '' || (float) $room['area_m2'] <= 0 ? null : (float) $room['area_m2'];
            $list = array_map($area, $inside[(int) $room['id']] ?? []);
            usort($list, $sort);
            $areas += count($list);
            $locations[$code]['rooms'][] = ['id' => (int) $room['id'], 'name' => (string) $room['room_name'], 'code' => (string) ($room['location_code'] ?? ''), 'area_m2' => $size, 'areas' => $list];
            if ($size === null) {
                $locations[$code]['unsized']++;
            } else {
                $locations[$code]['total_m2'] += $size;
            }
        }
        $outside = 0;
        foreach (RoomAreas::outside($db, $library) as $row) {
            if (!isset(Sarpras::AREA_TYPES[$row['type']])) continue;
            $locations[$at($row['library'])]['outside'][] = $area($row);
            $outside++;
        }
        // Locations by name, those of no location last.
        uasort($locations, static fn(array $a, array $b): int => [$a['code'] === '', $a['name'], $a['code']] <=> [$b['code'] === '', $b['name'], $b['code']]);
        foreach ($locations as &$location) usort($location['outside'], $sort);
        unset($location);

        return [
            'library' => $library,
            'rooms' => count($rooms),
            'areas' => $areas,
            'outside' => $outside,
            'total_m2' => array_sum(array_column($locations, 'total_m2')),
            'unsized' => array_sum(array_column($locations, 'unsized')),
            'locations' => array_values($locations),
        ];
    }

    public static function m2(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 2, ',', '.') . ' m²';
    }

    /**
     * The list as a document in one of the print styles the reports use (WatchPdf::STYLES).
     *
     * @param  array{library_name?:string,printed_by?:string,documents?:array}  $context
     */
    public static function html(array $list, array $context = [], string $style = 'latex'): string
    {
        require_once __DIR__ . '/WatchPdf.php';
        $t = WatchPdf::STYLES[$style] ?? PdfLatex::class;
        $e = static fn($value): string => PdfLayout::e($value);
        $today = date('Y-m-d');
        $muted = 'font-size:8pt;color:#555555;';
        $scope = array_filter([(string) ($context['library_name'] ?? ''), $list['library'] !== '' ? (string) ($list['locations'][0]['name'] ?? '') : 'Semua lokasi perpustakaan']);
        $h = $t::begin()
            . $t::titleBlock(
                'Daftar Area dan Luas Ruangan',
                ($context['printed_by'] ?? '') !== '' ? $e($context['printed_by']) : '',
                $e(implode(' · ', $scope)) . ' · Per ' . PdfLayout::date($today),
                PdfDocuments::identity($context['documents'] ?? PdfDocuments::DEFAULTS, 'rooms', ['date' => $today], $today)
            )
            . $t::facts([
                'Jumlah ruangan' => (string) $list['rooms'],
                'Jumlah luas ruangan' => self::m2((float) $list['total_m2']),
                'Area di ruangan' => (string) $list['areas'],
                'Area di luar ruangan' => (string) $list['outside'],
                'Ruangan tanpa luas' => (string) $list['unsized'],
                'Tanggal' => PdfLayout::date($today),
            ]);
        if (!$list['locations']) return $h . $t::paragraph('Belum ada ruangan yang dicatat.');
        $areaList = static function (array $areas) use ($e, $muted): string {
            if (!$areas) return '<span style="' . $muted . '">Belum ada area</span>';
            return implode('<br>', array_map(static fn(array $area): string => $e($area['label']) . ($area['name'] !== '' ? ' <span style="' . $muted . '">(' . $e($area['name']) . ')</span>' : ''), $areas));
        };
        foreach ($list['locations'] as $location) {
            $h .= PdfLayout::CHUNK . $t::section($location['name']);
            $rows = [];
            foreach ($location['rooms'] as $n => $room) {
                $rows[] = [
                    (string) ($n + 1),
                    '<b>' . $e($room['name']) . '</b>' . ($room['code'] !== '' ? '<br><span style="' . $muted . '">' . $e($room['code']) . '</span>' : ''),
                    $areaList($room['areas']),
                    self::m2($room['area_m2']),
                ];
            }
            if ($rows) {
                $rows[] = ['', '<b>Jumlah</b>' . ($location['unsized'] ? '<br><span style="' . $muted . '">' . $location['unsized'] . ' ruangan belum diisi luasnya</span>' : ''), '', '<b>' . self::m2((float) $location['total_m2']) . '</b>'];
            }
            $h .= $t::table('Ruangan ' . $location['name'], [['No.', 'r'], 'Ruangan', 'Area di dalamnya', ['Luas', 'r']], $rows, 'Belum ada ruangan di lokasi ini.', '8.8pt');
            if ($location['outside']) {
                $rows = [];
                foreach ($location['outside'] as $n => $area) $rows[] = [(string) ($n + 1), '<b>' . $e($area['label']) . '</b>', $area['name'] !== '' ? $e($area['name']) : '—'];
                $h .= $t::subsection('Area di luar ruangan (' . count($rows) . ' area)')
                    . $t::table('Area di luar ruangan ' . $location['name'], [['No.', 'r'], 'Area', 'Keterangan'], $rows, '', '8.8pt');
            }
        }
        if (count($list['locations']) > 1) {
            $rows = [];
            foreach ($list['locations'] as $n => $location) {
                $rows[] = [(string) ($n + 1), $e($location['name']), (string) count($location['rooms']), self::m2((float) $location['total_m2'])];
            }
            $rows[] = ['', '<b>Jumlah</b>', '<b>' . $list['rooms'] . '</b>', '<b>' . self::m2((float) $list['total_m2']) . '</b>'];
            $h .= PdfLayout::CHUNK . $t::section('Rekap per lokasi')
                . $t::table('Luas ruangan per lokasi perpustakaan', [['No.', 'r'], 'Lokasi perpustakaan', ['Ruangan', 'r'], ['Luas', 'r']], $rows, '', '8.8pt');
        }
        return $h . $t::paragraph('<span style="' . $muted . '">Luas dicatat per ruangan di Ruangan & Barang; area di dalam ruangan dan di luar ruangan tidak memiliki luas sendiri.</span>');
    }
}
