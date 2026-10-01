<?php

namespace SLiMS\Plugins\Inventory\Api;

use PDO;
use SlimsConnect\Auth\RateLimiter;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Sendable;

final class RoomController
{
    private const MAX_ROOMS = 1000;
    private const MAX_ITEMS = 2000;

    private const ROOM_SELECT = "SELECT l.id, l.room_name, l.location_code, l.slims_location_id, ml.location_name AS library_name,
        COUNT(i.id) AS item_count, COALESCE(SUM(i.item_condition = 'B'), 0) AS count_b, COALESCE(SUM(i.item_condition = 'KB'), 0) AS count_kb, COALESCE(SUM(i.item_condition = 'RB'), 0) AS count_rb
        FROM inventory_locations l LEFT JOIN mst_location ml ON ml.location_id = l.slims_location_id LEFT JOIN inventory_items i ON i.location_id = l.id";

    /**
     * Every room at once: a library has tens of rooms, not thousands, and the app keeps them
     * for searching and scanning while offline.
     */
    public function index(Context $context): JsonResponse
    {
        $rows = $context->db->query(self::ROOM_SELECT . ' GROUP BY l.id, l.room_name, l.location_code, l.slims_location_id, ml.location_name ORDER BY l.room_name, l.id LIMIT ' . self::MAX_ROOMS)->fetchAll(PDO::FETCH_ASSOC);
        $libraries = $context->db->query('SELECT DISTINCT ml.location_id, ml.location_name FROM mst_location ml JOIN inventory_locations l ON l.slims_location_id = ml.location_id ORDER BY ml.location_name')->fetchAll(PDO::FETCH_ASSOC);

        return JsonResponse::ok([
            'rooms' => array_map([Present::class, 'room'], $rows),
            'libraries' => array_map(static fn (array $row): array => ['code' => (string) $row['location_id'], 'name' => (string) $row['location_name']], $libraries),
        ]);
    }

    /** @return array<string, mixed> */
    public static function room(Context $context, int $id): array
    {
        $statement = $context->db->prepare(self::ROOM_SELECT . ' WHERE l.id = ? GROUP BY l.id, l.room_name, l.location_code, l.slims_location_id, ml.location_name');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw Failure::notFound('Ruangan tidak ditemukan.');
        }

        return Present::room($row);
    }

    /** A room and every item in it, with the ids of their photos. */
    public function items(Context $context, int $id): JsonResponse
    {
        $room = self::room($context, $id);
        $statement = $context->db->prepare('SELECT * FROM inventory_items WHERE location_id = ? ORDER BY item_name, id LIMIT ' . self::MAX_ITEMS);
        $statement->execute([$id]);
        $items = $statement->fetchAll(PDO::FETCH_ASSOC);
        $photos = $context->db->prepare('SELECT p.item_id, p.id FROM inventory_item_photos p JOIN inventory_items i ON i.id = p.item_id WHERE i.location_id = ? AND p.filename IS NOT NULL ORDER BY p.id');
        $photos->execute([$id]);
        $byItem = [];
        foreach ($photos->fetchAll(PDO::FETCH_NUM) as [$itemId, $photoId]) {
            $byItem[(int) $itemId][] = (int) $photoId;
        }

        return JsonResponse::ok([
            'room' => $room,
            'items' => array_map(static fn (array $item): array => Present::item($item, $byItem[(int) $item['id']] ?? []), $items),
        ]);
    }

    /** Kartu Inventaris Ruangan as PDF. */
    public function kir(Context $context, int $id): Sendable
    {
        self::throttlePdf($context);
        $document = $context->documents()->kir($id, $context->request->query('layout') === 'modern', Context::libraryName());
        $context->log('Kartu inventaris lokasi #' . $id . ' diunduh (' . $document['count'] . ' barang).', 'Print');

        return new BytesResponse($document['bytes'], 'application/pdf', $document['filename']);
    }

    /** Labels for the room, or for the items listed in ?ids=1,2,3. */
    public function labels(Context $context, int $id): Sendable
    {
        self::throttlePdf($context);
        $ids = array_map('intval', array_filter(explode(',', $context->request->query('ids'))));
        $document = $context->documents()->labels($id, $ids, $context->request->query('preset', 'a4-3x8'), $context->siteUrl());
        $context->log('Label inventaris lokasi #' . $id . ' diunduh (' . $document['count'] . ' label).', 'Print');

        return new BytesResponse($document['bytes'], 'application/pdf', $document['filename']);
    }

    /** PDFs are heavy for a small library server: ten a minute per librarian, as on the admin pages. */
    public static function throttlePdf(Context $context): void
    {
        $limiter = new RateLimiter();
        $key = 'invensync-pdf:' . $context->staff()->id;
        $limiter->ensureAvailable($key);
        $limiter->hit($key, 10, 60, 60);
    }
}
