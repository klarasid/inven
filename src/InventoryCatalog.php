<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

require_once __DIR__ . '/Inventory.php';
require_once __DIR__ . '/PdfLayout.php';
require_once __DIR__ . '/RoomAreas.php';
require_once __DIR__ . '/Sarpras.php';

/**
 * Daftar inventaris berfoto: the items of the library with one photo each, grouped the way an
 * accreditation asks for them: by category (service, access, comfort, security facilities) or
 * by the areas of the rooms they stand in (collection, reading, multimedia, a gazebo, ...).
 *
 * An item is recorded in a room, not in an area: in a room with several areas it is listed under
 * each of them, as Rekap Sarpras counts it. An item with several categories is listed under each.
 */
final class InventoryCatalog
{
    public const MAX_ITEMS = 500;
    public const MAX_PHOTOS = 500;
    public const GROUPINGS = ['category' => 'Kategori', 'area' => 'Area'];
    /** How many photos of an item are printed: its first, or all of them (a photo of it in use among them). */
    public const PHOTOS = ['first' => 'Satu foto per barang', 'all' => 'Semua foto'];
    private const THUMBNAIL = 320;

    /**
     * @param  list<string>  $categories  Sarpras::CATEGORIES codes to keep; none keeps every item
     * @return array{group:string,library:string,categories:list<string>,photos:string,items:int,with_photos:int,photo_count:int,groups:list<array{label:string,items:list<array<string,mixed>>}>}
     */
    public static function build(PDO $db, string $group, array $categories, string $library = '', string $photos = 'first'): array
    {
        if (!isset(self::GROUPINGS[$group])) throw new RuntimeException('Pilih pengelompokan menurut kategori atau area.');
        if (!isset(self::PHOTOS[$photos])) throw new RuntimeException('Pilih satu foto per barang atau semua foto.');
        if (array_diff($categories, array_keys(Sarpras::CATEGORIES))) throw new RuntimeException('Kategori barang tidak valid.');
        $categories = array_values(array_filter(array_keys(Sarpras::CATEGORIES), static fn($code) => in_array($code, $categories, true)));

        $where = $library === '' ? '' : ' WHERE l.slims_location_id = ?';
        $args = $library === '' ? [] : [$library];
        $query = $db->prepare('SELECT p.item_id, p.filename FROM inventory_item_photos p JOIN inventory_items i ON i.id = p.item_id JOIN inventory_locations l ON l.id = i.location_id'
            . ($where === '' ? ' WHERE' : $where . ' AND') . ' p.filename IS NOT NULL ORDER BY p.item_id, p.id');
        $query->execute($args);
        $filenames = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $filenames[(int) $row['item_id']][] = (string) $row['filename'];

        $query = $db->prepare('SELECT i.id, i.location_id, i.item_name, i.brand_model, i.item_code, i.quantity_register, i.item_condition, i.category, i.item_type, l.room_name'
            . ' FROM inventory_items i JOIN inventory_locations l ON l.id = i.location_id' . $where . ' ORDER BY i.item_name, i.item_code, i.id');
        $query->execute($args);
        $items = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['categories'] = Sarpras::categoryCodes($row['category']);
            if ($categories && !array_intersect($row['categories'], $categories)) continue;
            $row['photos'] = array_slice($filenames[(int) $row['id']] ?? [], 0, $photos === 'all' ? null : 1);
            $items[] = $row;
        }
        if (count($items) > self::MAX_ITEMS) {
            throw new RuntimeException('Daftar memuat lebih dari ' . self::MAX_ITEMS . ' barang. Saring menurut kategori atau lokasi perpustakaan.');
        }
        $photoCount = array_sum(array_map(static fn($item) => count($item['photos']), $items));
        if ($photoCount > self::MAX_PHOTOS) {
            throw new RuntimeException('Daftar memuat lebih dari ' . self::MAX_PHOTOS . ' foto. Saring menurut kategori atau lokasi perpustakaan, atau cetak satu foto per barang.');
        }

        $groups = $group === 'area' ? self::byArea($db, $items, $library) : self::byCategory($items, $categories);
        return [
            'group' => $group, 'library' => $library, 'categories' => $categories, 'photos' => $photos,
            'items' => count($items),
            'with_photos' => count(array_filter($items, static fn($item) => (bool) $item['photos'])),
            'photo_count' => $photoCount,
            'groups' => array_values(array_filter($groups, static fn($entry) => (bool) $entry['items'])),
        ];
    }

    /** @return list<array{label:string,items:list<array<string,mixed>>}> */
    private static function byCategory(array $items, array $categories): array
    {
        $groups = [];
        foreach ($categories ?: array_keys(Sarpras::CATEGORIES) as $code) {
            $groups[] = ['label' => Sarpras::CATEGORIES[$code], 'items' => array_values(array_filter($items, static fn($item) => in_array($code, $item['categories'], true)))];
        }
        $groups[] = ['label' => 'Belum berkategori', 'items' => array_values(array_filter($items, static fn($item) => !$item['categories']))];
        return $groups;
    }

    /** @return list<array{label:string,items:list<array<string,mixed>>}> */
    private static function byArea(PDO $db, array $items, string $library): array
    {
        $areas = [];
        foreach (RoomAreas::byRoom($db, $library) as $roomId => $roomAreas) $areas[$roomId] = array_unique(array_column($roomAreas, 'type'));
        $groups = [];
        foreach (Sarpras::AREA_TYPES as $code => $type) {
            $groups[] = ['label' => $type['label'], 'items' => array_values(array_filter($items, static fn($item) => in_array($code, $areas[(int) $item['location_id']] ?? [], true)))];
        }
        $groups[] = ['label' => 'Ruangan tanpa area', 'items' => array_values(array_filter($items, static fn($item) => empty($areas[(int) $item['location_id']])))];
        return $groups;
    }

    /**
     * What the list holds, without the items themselves.
     *
     * @return array{group:string,library:string,categories:list<string>,photos:string,items:int,with_photos:int,photo_count:int,groups:list<array{label:string,items:int,with_photos:int}>}
     */
    public static function summary(array $catalog): array
    {
        $catalog['groups'] = array_map(static fn(array $entry): array => [
            'label' => $entry['label'],
            'items' => count($entry['items']),
            'with_photos' => count(array_filter($entry['items'], static fn($item) => (bool) $item['photos'])),
        ], $catalog['groups']);
        return $catalog;
    }

    /** A photo small enough to print a few hundred of: a JPEG data URI, or null when it cannot be read. */
    public static function thumbnail(?string $bytes): ?string
    {
        $image = $bytes === null || $bytes === '' ? false : @imagecreatefromstring($bytes);
        if (!$image) return null;
        $scale = min(1, self::THUMBNAIL / max(imagesx($image), imagesy($image)));
        $thumb = imagecreatetruecolor(max(1, (int) (imagesx($image) * $scale)), max(1, (int) (imagesy($image) * $scale)));
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, imagesx($thumb), imagesy($thumb), imagesx($image), imagesy($image));
        ob_start(); imagejpeg($thumb, null, 75); $jpeg = (string) ob_get_clean(); imagedestroy($thumb); imagedestroy($image);
        return 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }

    /**
     * The list as a document in one of the print styles the reports use (WatchPdf::STYLES); for
     * `kop`, PdfLetterhead must have its template already.
     *
     * @param  callable(string): ?string  $readPhoto  the bytes of a stored photo, by its filename
     * @param  array{library_name?:string,printed_by?:string,documents?:array}  $context
     */
    public static function html(array $catalog, callable $readPhoto, array $context = [], string $style = 'latex'): string
    {
        require_once __DIR__ . '/WatchPdf.php';
        require_once __DIR__ . '/PdfDocuments.php';
        $t = WatchPdf::STYLES[$style] ?? PdfLatex::class;
        $e = static fn($value): string => PdfLayout::e($value);
        $today = date('Y-m-d');
        $scope = array_filter([
            (string) ($context['library_name'] ?? ''),
            $catalog['categories'] ? implode(', ', array_map(static fn($code) => Sarpras::CATEGORIES[$code], $catalog['categories'])) : 'Semua kategori',
            'dikelompokkan menurut ' . mb_strtolower(self::GROUPINGS[$catalog['group']]),
        ]);
        // Every photo of an item: smaller, two abreast, so five of them still fit beside its row.
        $all = $catalog['photos'] === 'all';
        $thumb = '<img style="width:' . ($all ? '19mm' : '26mm') . ';border:0.2mm solid #d1d5db;" src="';
        $muted = 'font-size:8pt;color:#555555;';
        $h = $t::begin() . $t::titleBlock(
            'Daftar Inventaris Berfoto',
            ($context['printed_by'] ?? '') !== '' ? $e($context['printed_by']) : '',
            $e(implode(' · ', $scope)) . ' · Per ' . PdfLayout::date($today),
            PdfDocuments::identity($context['documents'] ?? PdfDocuments::DEFAULTS, 'catalog', ['date' => $today], $today)
        );
        $h .= $t::facts([
            'Jumlah barang' => (string) $catalog['items'],
            'Barang berfoto' => $catalog['with_photos'] . ' dari ' . $catalog['items'] . ($all ? ' · ' . $catalog['photo_count'] . ' foto' : ''),
            'Dicetak oleh' => $e($context['printed_by'] ?? ''),
            'Tanggal' => PdfLayout::date($today),
        ]);
        if ($catalog['group'] === 'area') {
            $h .= $t::paragraph('Barang dicatat pada ruangan. Bila satu ruangan memiliki beberapa area, barangnya tercantum pada tiap area ruangan itu.');
        }
        if (!$catalog['groups']) $h .= $t::paragraph('Tidak ada barang yang sesuai.');

        // A photo is read and scaled down once, however many groups list its item.
        $thumbs = [];
        foreach ($catalog['groups'] as $entry) {
            $rows = [];
            foreach ($entry['items'] as $n => $item) {
                $images = [];
                foreach ($item['photos'] as $filename) {
                    if (!array_key_exists($filename, $thumbs)) $thumbs[$filename] = self::thumbnail($readPhoto($filename));
                    if ($thumbs[$filename] !== null) $images[] = $thumb . $thumbs[$filename] . '">';
                }
                $detail = array_filter([trim((string) $item['item_type']), trim((string) $item['brand_model'])], static fn($text) => $text !== '');
                $rows[] = [
                    (string) ($n + 1),
                    $images ? implode(' ', $images) : '<span style="' . $muted . '">Belum ada foto</span>',
                    '<b>' . $e($item['item_name']) . '</b>' . ($detail ? '<br><span style="' . $muted . '">' . $e(implode(' · ', $detail)) . '</span>' : ''),
                    $e($item['item_code']),
                    $e($item['room_name']),
                    $e($item['quantity_register']),
                    $e(Inventory::CONDITIONS[$item['item_condition']] ?? $item['item_condition']),
                ];
            }
            $h .= $t::section($entry['label'] . ' (' . count($rows) . ' barang)')
                . $t::table('Barang ' . mb_strtolower($entry['label']), [['No.', 'r'], ['Foto', 'c'], 'Barang', 'Kode', 'Ruangan', ['Jumlah', 'r'], 'Kondisi'], $rows, '', '8.8pt');
        }
        return $h;
    }
}
