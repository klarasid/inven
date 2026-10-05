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
    public const GROUPINGS = ['category' => 'Kategori', 'area' => 'Area'];
    private const THUMBNAIL = 320;

    /**
     * @param  list<string>  $categories  Sarpras::CATEGORIES codes to keep; none keeps every item
     * @return array{group:string,library:string,categories:list<string>,items:int,with_photos:int,groups:list<array{label:string,items:list<array<string,mixed>>}>}
     */
    public static function build(PDO $db, string $group, array $categories, string $library = ''): array
    {
        if (!isset(self::GROUPINGS[$group])) throw new RuntimeException('Pilih pengelompokan menurut kategori atau area.');
        if (array_diff($categories, array_keys(Sarpras::CATEGORIES))) throw new RuntimeException('Kategori barang tidak valid.');
        $categories = array_values(array_filter(array_keys(Sarpras::CATEGORIES), static fn($code) => in_array($code, $categories, true)));

        $query = $db->prepare('SELECT i.id, i.location_id, i.item_name, i.brand_model, i.item_code, i.quantity_register, i.item_condition, i.category, i.item_type, l.room_name,'
            . ' (SELECT p.filename FROM inventory_item_photos p WHERE p.item_id = i.id AND p.filename IS NOT NULL ORDER BY p.id LIMIT 1) AS photo'
            . ' FROM inventory_items i JOIN inventory_locations l ON l.id = i.location_id' . ($library === '' ? '' : ' WHERE l.slims_location_id = ?')
            . ' ORDER BY i.item_name, i.item_code, i.id');
        $query->execute($library === '' ? [] : [$library]);
        $items = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['categories'] = Sarpras::categoryCodes($row['category']);
            if ($categories && !array_intersect($row['categories'], $categories)) continue;
            $items[] = $row;
        }
        if (count($items) > self::MAX_ITEMS) {
            throw new RuntimeException('Daftar memuat lebih dari ' . self::MAX_ITEMS . ' barang. Saring menurut kategori atau lokasi perpustakaan.');
        }

        $groups = $group === 'area' ? self::byArea($db, $items, $library) : self::byCategory($items, $categories);
        return [
            'group' => $group, 'library' => $library, 'categories' => $categories,
            'items' => count($items),
            'with_photos' => count(array_filter($items, static fn($item) => $item['photo'] !== null)),
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
     * @return array{group:string,library:string,categories:list<string>,items:int,with_photos:int,groups:list<array{label:string,items:int,with_photos:int}>}
     */
    public static function summary(array $catalog): array
    {
        $catalog['groups'] = array_map(static fn(array $entry): array => [
            'label' => $entry['label'],
            'items' => count($entry['items']),
            'with_photos' => count(array_filter($entry['items'], static fn($item) => $item['photo'] !== null)),
        ], $catalog['groups']);
        return $catalog;
    }

    /** A photo small enough to print a few hundred of: a JPEG data URI, or null when it cannot be read. */
    private static function thumbnail(?string $bytes): ?string
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
     * @param  callable(string): ?string  $readPhoto  the bytes of a stored photo, by its filename
     * @param  array{library_name?:string,printed_by?:string}  $context
     */
    public static function html(array $catalog, callable $readPhoto, array $context = []): string
    {
        $e = static fn($value): string => PdfLayout::e($value);
        $scope = array_filter([
            (string) ($context['library_name'] ?? ''),
            $catalog['categories'] ? implode(', ', array_map(static fn($code) => Sarpras::CATEGORIES[$code], $catalog['categories'])) : 'Semua kategori',
            'dikelompokkan menurut ' . mb_strtolower(self::GROUPINGS[$catalog['group']]),
        ]);
        $h = PdfLayout::css('.thumb{width:26mm;border:0.2mm solid #d1d5db;}.grid td.photo{width:28mm;text-align:center;}')
            . PdfLayout::header('DAFTAR INVENTARIS BERFOTO', $e(implode(' · ', $scope)))
            . PdfLayout::meta([
                'Jumlah barang' => (string) $catalog['items'],
                'Barang berfoto' => $catalog['with_photos'] . ' dari ' . $catalog['items'],
                'Dicetak oleh' => $e($context['printed_by'] ?? ''),
                'Tanggal' => PdfLayout::date(new \DateTimeImmutable('now')),
            ]);
        if ($catalog['group'] === 'area') {
            $h .= '<p class="note">Barang dicatat pada ruangan. Bila satu ruangan memiliki beberapa area, barangnya tercantum pada tiap area ruangan itu.</p>';
        }
        if (!$catalog['groups']) $h .= '<p class="muted">Tidak ada barang yang sesuai.</p>';

        // One thumbnail per item, however many groups list it.
        $thumbs = [];
        foreach ($catalog['groups'] as $entry) {
            $rows = [];
            foreach ($entry['items'] as $n => $item) {
                $id = (int) $item['id'];
                if (!array_key_exists($id, $thumbs)) $thumbs[$id] = $item['photo'] === null ? null : self::thumbnail($readPhoto((string) $item['photo']));
                $detail = array_filter([trim((string) $item['item_type']), trim((string) $item['brand_model'])], static fn($text) => $text !== '');
                $rows[] = [
                    (string) ($n + 1),
                    $thumbs[$id] === null ? '<span class="muted small">Belum ada foto</span>' : '<img class="thumb" src="' . $thumbs[$id] . '">',
                    '<span class="strong">' . $e($item['item_name']) . '</span>' . ($detail ? '<br><span class="muted small">' . $e(implode(' · ', $detail)) . '</span>' : ''),
                    $e($item['item_code']),
                    $e($item['room_name']),
                    $e($item['quantity_register']),
                    $e(Inventory::CONDITIONS[$item['item_condition']] ?? $item['item_condition']),
                ];
            }
            $h .= '<h2>' . $e($entry['label']) . ' (' . count($rows) . ' barang)</h2>'
                . PdfLayout::table([['No.', 'no'], ['Foto', 'photo'], 'Barang', 'Kode', 'Ruangan', ['Jumlah', 'num'], 'Kondisi'], $rows);
        }
        return $h;
    }
}
