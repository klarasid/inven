<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

require_once __DIR__ . '/Sarpras.php';

/**
 * The areas inside a room: what a room is used for, one row per area. A reading room may hold a
 * reading area, a collection area and a service desk; a toilet is a room with one area of that
 * kind. Rekap Sarpras counts service areas and public facilities from these rows.
 *
 * Every time comes from PHP's clock and the SQL is plain, so the tests run it on SQLite.
 */
final class RoomAreas
{
    public const MAX_PER_ROOM = 40;

    /** @return list<array{id:int,location_id:int,type:string,name:string}> in the order of Sarpras::AREA_TYPES */
    public static function of(PDO $db, int $roomId): array
    {
        $query = $db->prepare('SELECT id, location_id, type, name FROM inventory_room_areas WHERE location_id = ? ORDER BY id');
        $query->execute([$roomId]);
        $order = array_flip(array_keys(Sarpras::AREA_TYPES));
        $rows = array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'location_id' => (int) $row['location_id'], 'type' => (string) $row['type'], 'name' => (string) $row['name']], $query->fetchAll(PDO::FETCH_ASSOC));
        usort($rows, static fn(array $a, array $b): int => [$order[$a['type']] ?? 999, $a['id']] <=> [$order[$b['type']] ?? 999, $b['id']]);
        return $rows;
    }

    /**
     * Areas of every room, or of the rooms of one library location.
     *
     * @return array<int, list<array{type:string,name:string}>> room id => its areas of a known type
     */
    public static function byRoom(PDO $db, string $library = ''): array
    {
        $query = $db->prepare('SELECT a.location_id, a.type, a.name FROM inventory_room_areas a JOIN inventory_locations l ON l.id = a.location_id' . ($library === '' ? '' : ' WHERE l.slims_location_id = ?') . ' ORDER BY a.id');
        $query->execute($library === '' ? [] : [$library]);
        $rooms = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset(Sarpras::AREA_TYPES[$row['type']])) $rooms[(int) $row['location_id']][] = ['type' => (string) $row['type'], 'name' => (string) $row['name']];
        }
        return $rooms;
    }

    /** Adds ($id = 0) or changes an area of a room. @return int the area's id */
    public static function save(PDO $db, int $roomId, int $id, array $input, string $now): int
    {
        $type = is_scalar($input['type'] ?? null) ? trim((string) $input['type']) : '';
        if (!isset(Sarpras::AREA_TYPES[$type])) throw new RuntimeException('Pilih jenis area.');
        $name = is_scalar($input['name'] ?? '') ? trim((string) ($input['name'] ?? '')) : '';
        if (mb_strlen($name) > 150) throw new RuntimeException('Nama area maksimal 150 karakter.');
        $room = $db->prepare('SELECT 1 FROM inventory_locations WHERE id = ?');
        $room->execute([$roomId]);
        if (!$room->fetchColumn()) throw new RuntimeException('Ruangan tidak ditemukan.');
        $areas = self::of($db, $roomId);
        foreach ($areas as $area) {
            if ($area['id'] !== $id && $area['type'] === $type && mb_strtolower($area['name']) === mb_strtolower($name)) {
                throw new RuntimeException($name === '' ? 'Area ini sudah dicatat di ruangan ini. Beri nama untuk membedakan area sejenis.' : 'Area dengan jenis dan nama ini sudah dicatat di ruangan ini.');
            }
        }
        if ($id > 0) {
            if (!in_array($id, array_column($areas, 'id'), true)) throw new RuntimeException('Area tidak ditemukan di ruangan ini.');
            $db->prepare('UPDATE inventory_room_areas SET type = ?, name = ?, updated_at = ? WHERE id = ? AND location_id = ?')->execute([$type, $name, $now, $id, $roomId]);
            return $id;
        }
        if (count($areas) >= self::MAX_PER_ROOM) throw new RuntimeException('Satu ruangan memuat paling banyak ' . self::MAX_PER_ROOM . ' area.');
        $db->prepare('INSERT INTO inventory_room_areas (location_id, type, name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')->execute([$roomId, $type, $name, $now, $now]);
        return (int) $db->lastInsertId();
    }

    public static function delete(PDO $db, int $roomId, int $id): void
    {
        $delete = $db->prepare('DELETE FROM inventory_room_areas WHERE id = ? AND location_id = ?');
        $delete->execute([$id, $roomId]);
        if ($delete->rowCount() < 1) throw new RuntimeException('Area tidak ditemukan di ruangan ini.');
    }

    /** With the room itself. */
    public static function deleteRoom(PDO $db, int $roomId): void
    {
        $db->prepare('DELETE FROM inventory_room_areas WHERE location_id = ?')->execute([$roomId]);
    }

    /**
     * Rooms used to keep their functions as ticked codes (inventory_locations.room_functions). Each
     * ticked function becomes an area of that room. Rooms that already have areas are left alone,
     * so running this again adds nothing.
     *
     * @return int areas added
     */
    public static function importFunctions(PDO $db, string $now): int
    {
        $has = $db->prepare('SELECT 1 FROM inventory_room_areas WHERE location_id = ? LIMIT 1');
        $insert = $db->prepare('INSERT INTO inventory_room_areas (location_id, type, name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
        $added = 0;
        foreach ($db->query("SELECT id, room_functions FROM inventory_locations WHERE room_functions <> ''")->fetchAll(PDO::FETCH_ASSOC) as $room) {
            $has->execute([$room['id']]);
            if ($has->fetchColumn()) continue;
            $codes = array_map('trim', explode(',', (string) $room['room_functions']));
            foreach (array_keys(Sarpras::AREA_TYPES) as $code) {
                if (!in_array($code, $codes, true)) continue;
                $insert->execute([$room['id'], $code, '', $now, $now]);
                $added++;
            }
        }
        return $added;
    }
}
