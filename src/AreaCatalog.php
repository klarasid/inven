<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

require_once __DIR__ . '/AreaPhotos.php';
require_once __DIR__ . '/InventoryCatalog.php';
require_once __DIR__ . '/PdfLayout.php';
require_once __DIR__ . '/Sarpras.php';

/**
 * Daftar area dan fasilitas: the areas inside the rooms (RoomAreas) with the room each is in and
 * its photos, under their group: the basic service areas, the supporting ones, and the public
 * facilities. A public facility may also be an item rather than an area (a signboard, a water
 * dispenser): the items of that category are listed with the public facilities.
 */
final class AreaCatalog
{
    public const MAX_AREAS = 500;
    public const MAX_PHOTOS = 500;

    /**
     * @param  string  $group  a key of Sarpras::AREA_GROUPS, or '' for every group
     * @return array{group:string,library:string,areas:int,with_photos:int,photo_count:int,groups:list<array{key:string,label:string,areas:list<array<string,mixed>>,items:list<array<string,mixed>>}>}
     */
    public static function build(PDO $db, string $group = '', string $library = ''): array
    {
        if ($group !== '' && !isset(Sarpras::AREA_GROUPS[$group])) throw new RuntimeException('Pilih kelompok area: layanan dasar, pendukung, atau fasilitas umum.');
        $query = $db->prepare('SELECT a.id, a.type, a.name, l.id AS room_id, l.room_name, l.area_m2 FROM inventory_room_areas a JOIN inventory_locations l ON l.id = a.location_id'
            . ($library === '' ? '' : ' WHERE l.slims_location_id = ?') . ' ORDER BY l.room_name, l.id, a.id');
        $query->execute($library === '' ? [] : [$library]);
        // Before migration 19 no area has a photo.
        try {
            $photos = AreaPhotos::byArea($db);
        } catch (\PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            $photos = [];
        }
        $order = array_flip(array_keys(Sarpras::AREA_TYPES));
        $areas = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $type = Sarpras::AREA_TYPES[$row['type']] ?? null;
            if ($type === null || ($group !== '' && $type['group'] !== $group)) continue;
            $areas[] = $row + ['label' => $type['label'], 'group' => $type['group'], 'photos' => array_column($photos[(int) $row['id']] ?? [], 'filename')];
        }
        if (count($areas) > self::MAX_AREAS) throw new RuntimeException('Daftar memuat lebih dari ' . self::MAX_AREAS . ' area. Pilih satu kelompok area atau satu lokasi perpustakaan.');
        $photoCount = array_sum(array_map(static fn(array $area): int => count($area['photos']), $areas));
        if ($photoCount > self::MAX_PHOTOS) throw new RuntimeException('Daftar memuat lebih dari ' . self::MAX_PHOTOS . ' foto. Pilih satu kelompok area atau satu lokasi perpustakaan.');
        // By kind of area, then by room, as the Area tab lists them.
        usort($areas, static fn(array $a, array $b): int => [$order[$a['type']], $a['room_name'], $a['id']] <=> [$order[$b['type']], $b['room_name'], $b['id']]);

        $groups = [];
        foreach (Sarpras::AREA_GROUPS as $key => $label) {
            if ($group !== '' && $key !== $group) continue;
            // Public facilities kept as items, which Rekap Sarpras counts beside the areas.
            $items = $key === 'umum' ? (InventoryCatalog::build($db, 'category', ['fasilitas_umum'], $library)['groups'][0]['items'] ?? []) : [];
            $inGroup = array_values(array_filter($areas, static fn(array $area): bool => $area['group'] === $key));
            if ($inGroup || $items) $groups[] = ['key' => $key, 'label' => $label, 'areas' => $inGroup, 'items' => $items];
        }
        return [
            'group' => $group, 'library' => $library,
            'areas' => count($areas),
            'with_photos' => count(array_filter($areas, static fn(array $area): bool => (bool) $area['photos'])),
            'photo_count' => $photoCount,
            'groups' => $groups,
        ];
    }

    /**
     * What the list holds, without the areas themselves.
     *
     * @return array{group:string,library:string,areas:int,with_photos:int,photo_count:int,groups:list<array{key:string,label:string,areas:int,with_photos:int,kinds:list<array{label:string,count:int}>,items:int}>}
     */
    public static function summary(array $catalog): array
    {
        $catalog['groups'] = array_map(static function (array $entry): array {
            $kinds = [];
            foreach ($entry['areas'] as $area) {
                $kinds[$area['type']] ??= ['label' => $area['label'], 'count' => 0];
                $kinds[$area['type']]['count']++;
            }
            return [
                'key' => $entry['key'], 'label' => $entry['label'],
                'areas' => count($entry['areas']),
                'with_photos' => count(array_filter($entry['areas'], static fn(array $area): bool => (bool) $area['photos'])),
                'kinds' => array_values($kinds),
                'items' => count($entry['items']),
            ];
        }, $catalog['groups']);
        return $catalog;
    }

    /**
     * @param  callable(string): ?string  $readAreaPhoto  the bytes of an area's stored photo, by its filename
     * @param  callable(string): ?string  $readItemPhoto  the same for an item's photo
     * @param  array{library_name?:string,printed_by?:string}  $context
     */
    public static function html(array $catalog, callable $readAreaPhoto, callable $readItemPhoto, array $context = []): string
    {
        $e = static fn($value): string => PdfLayout::e($value);
        $scope = array_filter([(string) ($context['library_name'] ?? ''), $catalog['group'] === '' ? 'Semua kelompok area' : Sarpras::AREA_GROUPS[$catalog['group']]]);
        $h = PdfLayout::css('.thumb{width:19mm;border:0.2mm solid #d1d5db;}.grid td.photo{width:46mm;text-align:center;}')
            . PdfLayout::header('DAFTAR AREA DAN FASILITAS', $e(implode(' · ', $scope)))
            . PdfLayout::meta([
                'Jumlah area' => (string) $catalog['areas'],
                'Area berfoto' => $catalog['with_photos'] . ' dari ' . $catalog['areas'] . ' · ' . $catalog['photo_count'] . ' foto',
                'Dicetak oleh' => $e($context['printed_by'] ?? ''),
                'Tanggal' => PdfLayout::date(new \DateTimeImmutable('now')),
            ]);
        if (!$catalog['groups']) $h .= '<p class="muted">Belum ada area yang dicatat.</p>';
        $images = static function (array $filenames, callable $read): string {
            $tags = [];
            foreach ($filenames as $filename) {
                $thumbnail = InventoryCatalog::thumbnail($read((string) $filename));
                if ($thumbnail !== null) $tags[] = '<img class="thumb" src="' . $thumbnail . '">';
            }
            return $tags ? implode(' ', $tags) : '<span class="muted small">Belum ada foto</span>';
        };
        foreach ($catalog['groups'] as $entry) {
            $h .= '<h2>' . $e($entry['label']) . ' (' . count($entry['areas']) . ' area)</h2>';
            $rows = [];
            foreach ($entry['areas'] as $n => $area) {
                $rows[] = [
                    (string) ($n + 1),
                    $images($area['photos'], $readAreaPhoto),
                    '<span class="strong">' . $e($area['label']) . '</span>' . (trim((string) $area['name']) !== '' ? '<br><span class="muted small">' . $e($area['name']) . '</span>' : ''),
                    $e($area['room_name']),
                    $area['area_m2'] === null ? '—' : number_format((float) $area['area_m2'], 2, ',', '.') . ' m²',
                ];
            }
            $h .= PdfLayout::table([['No.', 'no'], ['Foto', 'photo'], 'Area', 'Ruangan', ['Luas ruangan', 'num']], $rows, 'Belum ada area di kelompok ini.');
            if ($entry['items']) {
                $rows = [];
                foreach ($entry['items'] as $n => $item) {
                    $rows[] = [
                        (string) ($n + 1),
                        $images($item['photos'], $readItemPhoto),
                        '<span class="strong">' . $e($item['item_name']) . '</span>' . (trim((string) $item['item_type']) !== '' ? '<br><span class="muted small">' . $e($item['item_type']) . '</span>' : ''),
                        $e($item['item_code']),
                        $e($item['room_name']),
                        $e(Inventory::CONDITIONS[$item['item_condition']] ?? $item['item_condition']),
                    ];
                }
                $h .= '<h3>Barang fasilitas umum (' . count($rows) . ' barang)</h3>'
                    . PdfLayout::table([['No.', 'no'], ['Foto', 'photo'], 'Barang', 'Kode', 'Ruangan', 'Kondisi'], $rows);
            }
        }
        return $h;
    }
}
