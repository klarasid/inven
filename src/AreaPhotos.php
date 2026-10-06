<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

require_once __DIR__ . '/PhotoStorage.php';
require_once __DIR__ . '/ItemPhotos.php';

/**
 * Photos of the areas inside a room (RoomAreas): what a reading area, a toilet or a car park
 * looks like. An area is not an item, so it has its own photos, a few per area, kept as an
 * item's are: re-encoded as JPEG (ItemPhotos::normalize) in a folder denied to browsers, and
 * served only through the room's page and the documents the plugin prints.
 */
final class AreaPhotos
{
    public const MAX_PER_AREA = 3;

    public static function storage(): PhotoStorage
    {
        return new PhotoStorage(rtrim(SB, '/\\') . '/images/inventaris-barang/area');
    }

    /**
     * The photos of every area, of one room or of all, by area and oldest first.
     *
     * @return array<int, list<array{id:int,filename:string,created_at:string}>>
     */
    public static function byArea(PDO $db, ?int $roomId = null): array
    {
        $query = $db->prepare('SELECT p.id, p.area_id, p.filename, p.created_at FROM inventory_area_photos p JOIN inventory_room_areas a ON a.id = p.area_id'
            . ($roomId === null ? '' : ' WHERE a.location_id = ?') . ' ORDER BY p.id');
        $query->execute($roomId === null ? [] : [$roomId]);
        $photos = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $photos[(int) $row['area_id']][] = ['id' => (int) $row['id'], 'filename' => (string) $row['filename'], 'created_at' => (string) $row['created_at']];
        }
        return $photos;
    }

    /** Stores an uploaded photo of an area of the room. @return int the photo's id */
    public static function add(PDO $db, PhotoStorage $storage, int $roomId, int $areaId, array $file, ?int $uid, string $now): int
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Pilih foto area (JPEG, PNG, atau WebP) maksimal 2 MB.');
        }
        if ((int) filesize($file['tmp_name']) > ItemPhotos::MAX_BYTES) throw new RuntimeException('Ukuran setiap foto maksimal 2 MB.');
        return self::store($db, $storage, $roomId, $areaId, (string) file_get_contents($file['tmp_name']), $uid, $now);
    }

    /** Stores a photo's bytes for an area of the room, as add() does once the upload is read. @return int the photo's id */
    public static function store(PDO $db, PhotoStorage $storage, int $roomId, int $areaId, string $bytes, ?int $uid, string $now): int
    {
        $area = $db->prepare('SELECT 1 FROM inventory_room_areas WHERE id = ? AND location_id = ?');
        $area->execute([$areaId, $roomId]);
        if (!$area->fetchColumn()) throw new RuntimeException('Area tidak ditemukan di ruangan ini.');
        $count = $db->prepare('SELECT COUNT(*) FROM inventory_area_photos WHERE area_id = ?');
        $count->execute([$areaId]);
        if ((int) $count->fetchColumn() >= self::MAX_PER_AREA) {
            throw new RuntimeException('Satu area memuat paling banyak ' . self::MAX_PER_AREA . ' foto. Hapus foto lama sebelum menambah.');
        }
        $filename = $storage->write(ItemPhotos::normalize($bytes));
        try {
            $db->prepare('INSERT INTO inventory_area_photos (area_id, filename, created_by, created_at) VALUES (?, ?, ?, ?)')->execute([$areaId, $filename, $uid, $now]);
        } catch (\Throwable $error) {
            $storage->delete($filename);
            throw $error;
        }
        return (int) $db->lastInsertId();
    }

    public static function delete(PDO $db, PhotoStorage $storage, int $roomId, int $id): void
    {
        $query = $db->prepare('SELECT p.filename FROM inventory_area_photos p JOIN inventory_room_areas a ON a.id = p.area_id WHERE p.id = ? AND a.location_id = ?');
        $query->execute([$id, $roomId]);
        $filename = $query->fetchColumn();
        if ($filename === false) throw new RuntimeException('Foto tidak ditemukan pada area di ruangan ini.');
        $db->prepare('DELETE FROM inventory_area_photos WHERE id = ?')->execute([$id]);
        $storage->cleanup([(string) $filename]);
    }

    /** With the area itself. @return list<string> files to remove once the deletion is committed */
    public static function deleteArea(PDO $db, int $areaId): array
    {
        $query = $db->prepare('SELECT filename FROM inventory_area_photos WHERE area_id = ?');
        $query->execute([$areaId]);
        $files = array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
        $db->prepare('DELETE FROM inventory_area_photos WHERE area_id = ?')->execute([$areaId]);
        return $files;
    }

    /** With the room itself, before its areas go. @return list<string> files to remove once the deletion is committed */
    public static function deleteRoom(PDO $db, int $roomId): array
    {
        $query = $db->prepare('SELECT p.id, p.filename FROM inventory_area_photos p JOIN inventory_room_areas a ON a.id = p.area_id WHERE a.location_id = ?');
        $query->execute([$roomId]);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $delete = $db->prepare('DELETE FROM inventory_area_photos WHERE id = ?');
        foreach ($rows as $row) $delete->execute([$row['id']]);
        return array_map('strval', array_column($rows, 'filename'));
    }

    /** The photo's bytes, or null when there is none. */
    public static function read(PDO $db, PhotoStorage $storage, int $id): ?string
    {
        $query = $db->prepare('SELECT filename FROM inventory_area_photos WHERE id = ?');
        $query->execute([$id]);
        $filename = $query->fetchColumn();
        return $filename === false ? null : $storage->read((string) $filename);
    }
}
