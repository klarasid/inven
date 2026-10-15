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
 * A car park, a canteen or a garden stands outside any room: such an area has no room
 * (location_id NULL) and belongs to a library location instead (outside(), saveOutside()).
 *
 * Every time comes from PHP's clock and the SQL is plain, so the tests run it on SQLite.
 */
final class RoomAreas
{
    public const MAX_PER_ROOM = 40;
    public const MAX_OUTSIDE = 40;

    /** Where an area outside any room is said to be, in place of a room's name. */
    public const OUTSIDE = 'Luar ruangan';

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
        [$type, $name] = self::input($input, array_keys(Sarpras::AREA_TYPES));
        $room = $db->prepare('SELECT 1 FROM inventory_locations WHERE id = ?');
        $room->execute([$roomId]);
        if (!$room->fetchColumn()) throw new RuntimeException('Ruangan tidak ditemukan.');
        $areas = self::of($db, $roomId);
        self::unique($areas, $id, $type, $name, 'di ruangan ini');
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

    /**
     * Areas outside any room (a car park, a canteen, a garden), of every library location or of
     * one. An area of no location is listed only with every location, as a room of none is.
     *
     * @return list<array{id:int,library:string,type:string,name:string}> in the order of Sarpras::AREA_TYPES
     */
    public static function outside(PDO $db, string $library = ''): array
    {
        try {
            $query = $db->prepare('SELECT id, slims_location_id, type, name FROM inventory_room_areas WHERE location_id IS NULL' . ($library === '' ? '' : ' AND slims_location_id = ?') . ' ORDER BY id');
            $query->execute($library === '' ? [] : [$library]);
        } catch (\PDOException $error) {
            // Before migration 24 every area is in a room.
            if ((int) ($error->errorInfo[1] ?? 0) !== 1054) throw $error;
            return [];
        }
        $order = array_flip(array_keys(Sarpras::AREA_TYPES));
        $rows = array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'library' => (string) ($row['slims_location_id'] ?? ''), 'type' => (string) $row['type'], 'name' => (string) $row['name']], $query->fetchAll(PDO::FETCH_ASSOC));
        usort($rows, static fn(array $a, array $b): int => [$a['library'], $order[$a['type']] ?? 999, $a['id']] <=> [$b['library'], $order[$b['type']] ?? 999, $b['id']]);
        return $rows;
    }

    /**
     * Adds ($id = 0) or changes an area outside any room, of a library location. Where SLiMS
     * keeps one location the area takes it; where it keeps several one must be chosen.
     *
     * @return int the area's id
     */
    public static function saveOutside(PDO $db, string $library, int $id, array $input, string $now): int
    {
        [$type, $name] = self::input($input, Sarpras::outsideTypes(), 'Area di luar ruangan hanya untuk fasilitas umum dan area terbuka, misalnya parkir, kantin, atau taman. Area lain dicatat di ruangannya.');
        if ($id > 0 && !self::isOutside($db, $id)) throw new RuntimeException('Area di luar ruangan tidak ditemukan.');
        $library = self::library($db, $library);
        $areas = array_values(array_filter(self::outside($db, $library ?? ''), static fn(array $area): bool => $area['library'] === ($library ?? '')));
        self::unique($areas, $id, $type, $name, 'di luar ruangan');
        if ($id > 0) {
            $db->prepare('UPDATE inventory_room_areas SET slims_location_id = ?, type = ?, name = ?, updated_at = ? WHERE id = ? AND location_id IS NULL')->execute([$library, $type, $name, $now, $id]);
            return $id;
        }
        if (count($areas) >= self::MAX_OUTSIDE) throw new RuntimeException('Satu perpustakaan memuat paling banyak ' . self::MAX_OUTSIDE . ' area di luar ruangan.');
        $db->prepare('INSERT INTO inventory_room_areas (location_id, slims_location_id, type, name, created_at, updated_at) VALUES (NULL, ?, ?, ?, ?, ?)')->execute([$library, $type, $name, $now, $now]);
        return (int) $db->lastInsertId();
    }

    public static function deleteOutside(PDO $db, int $id): void
    {
        $delete = $db->prepare('DELETE FROM inventory_room_areas WHERE id = ? AND location_id IS NULL');
        $delete->execute([$id]);
        if ($delete->rowCount() < 1) throw new RuntimeException('Area di luar ruangan tidak ditemukan.');
    }

    public static function isOutside(PDO $db, int $id): bool
    {
        $query = $db->prepare('SELECT 1 FROM inventory_room_areas WHERE id = ? AND location_id IS NULL');
        $query->execute([$id]);
        return (bool) $query->fetchColumn();
    }

    /** @param list<string> $types the kinds allowed here @return array{0:string,1:string} type, name */
    private static function input(array $input, array $types, string $refused = 'Pilih jenis area.'): array
    {
        $type = is_scalar($input['type'] ?? null) ? trim((string) $input['type']) : '';
        if (!isset(Sarpras::AREA_TYPES[$type])) throw new RuntimeException('Pilih jenis area.');
        if (!in_array($type, $types, true)) throw new RuntimeException($refused);
        $name = is_scalar($input['name'] ?? '') ? trim((string) ($input['name'] ?? '')) : '';
        if (mb_strlen($name) > 150) throw new RuntimeException('Nama area maksimal 150 karakter.');
        return [$type, $name];
    }

    /** No two areas of one place share a kind and a name. */
    private static function unique(array $areas, int $id, string $type, string $name, string $place): void
    {
        foreach ($areas as $area) {
            if ($area['id'] !== $id && $area['type'] === $type && mb_strtolower($area['name']) === mb_strtolower($name)) {
                throw new RuntimeException($name === '' ? "Area ini sudah dicatat $place. Beri nama untuk membedakan area sejenis." : "Area dengan jenis dan nama ini sudah dicatat $place.");
            }
        }
    }

    /** The library location an outside area belongs to, or null where SLiMS keeps none. */
    private static function library(PDO $db, string $library): ?string
    {
        $locations = array_column(Sarpras::locations($db)['locations'], 'code');
        if ($library === '') {
            if (count($locations) > 1) throw new RuntimeException('Pilih lokasi perpustakaan area ini.');
            return $locations[0] ?? null;
        }
        $known = $db->prepare('SELECT 1 FROM mst_location WHERE location_id = ?');
        $known->execute([$library]);
        if (!$known->fetchColumn()) throw new RuntimeException('Lokasi perpustakaan tidak ditemukan.');
        return $library;
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
