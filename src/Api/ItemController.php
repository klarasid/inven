<?php

namespace SLiMS\Plugins\Inventory\Api;

use PDO;
use SLiMS\Plugins\Inventory\Inventory;
use SLiMS\Plugins\Inventory\ItemCodes;
use SLiMS\Plugins\Inventory\ItemPhotos;
use SLiMS\Plugins\Inventory\PublicLink;
use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Sendable;

final class ItemController
{
    /** App field → inventory_items column. */
    private const FIELDS = [
        'room_id' => 'location_id', 'name' => 'item_name', 'brand' => 'brand_model', 'serial' => 'serial_number',
        'size' => 'item_size', 'material' => 'material', 'year' => 'acquisition_year', 'code' => 'item_code',
        'quantity' => 'quantity_register', 'price' => 'acquisition_price', 'condition' => 'item_condition', 'notes' => 'notes',
        'categories' => 'category', 'type' => 'item_type',
    ];

    /** @return array<string, mixed> */
    private static function row(Context $context, int $id): array
    {
        $statement = $context->db->prepare('SELECT * FROM inventory_items WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw Failure::notFound('Barang tidak ditemukan. Barang mungkin sudah dihapus dari inventaris.');
        }

        return $row;
    }

    /** @return list<int> */
    private static function photoIds(Context $context, int $itemId): array
    {
        $statement = $context->db->prepare('SELECT id FROM inventory_item_photos WHERE item_id = ? AND filename IS NOT NULL ORDER BY id');
        $statement->execute([$itemId]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string, mixed> */
    private static function full(Context $context, int $id): array
    {
        $row = self::row($context, $id);

        return [
            'item' => Present::item($row, self::photoIds($context, $id)),
            'room' => RoomController::room($context, (int) $row['location_id']),
            'history' => self::history($context, $id),
        ];
    }

    public function show(Context $context, int $id): JsonResponse
    {
        return JsonResponse::ok(self::full($context, $id));
    }

    /**
     * Finished checks of this item and the work done on it, newest first.
     *
     * Checklist rows keep the item they covered inside a JSON snapshot. MySQL 5.6 has no JSON
     * functions, so the id is matched in the encoded text, as an integer or as a string.
     *
     * @return list<array<string, mixed>>
     */
    private static function history(Context $context, int $id): array
    {
        $statement = $context->db->prepare(
            "SELECT r.id, r.outcome, i.kind, i.performed_date, i.examiner_name FROM inventory_watch_results r JOIN inventory_watch_inspections i ON i.id = r.inspection_id
             WHERE i.status = 'final' AND (r.snapshot LIKE ? OR r.snapshot LIKE ?) ORDER BY i.performed_date DESC, i.id DESC LIMIT 20"
        );
        $statement->execute(['%"item_id":' . $id . ',%', '%"item_id":"' . $id . '",%']);
        $results = $statement->fetchAll(PDO::FETCH_ASSOC);
        $outcomes = ['good' => 'Baik', 'action' => 'Perlu tindakan', 'unchecked' => 'Tidak diperiksa', 'na' => 'Tidak berlaku'];
        $history = [];
        foreach ($results as $result) {
            $history[] = [
                'kind' => 'inspection',
                'title' => ($result['kind'] === 'routine' ? 'Pemeriksaan rutin' : 'Pemeriksaan insidental') . ' • ' . ($outcomes[$result['outcome']] ?? $result['outcome']),
                'date' => $result['performed_date'],
                'by' => (string) $result['examiner_name'],
            ];
        }
        $resultIds = array_map('intval', array_column($results, 'id'));
        if ($resultIds) {
            $actions = $context->db->prepare(
                'SELECT a.kind, a.description, a.performed_date, a.actor_name FROM inventory_watch_actions a JOIN inventory_watch_findings f ON f.id = a.finding_id
                 WHERE a.submitted_at IS NOT NULL AND f.result_id IN (' . implode(',', array_fill(0, count($resultIds), '?')) . ')'
            );
            $actions->execute($resultIds);
            $kinds = ['repair' => 'Perbaikan', 'maintenance' => 'Pemeliharaan', 'none' => 'Tanpa tindakan'];
            foreach ($actions->fetchAll(PDO::FETCH_ASSOC) as $action) {
                $history[] = [
                    'kind' => 'action',
                    'title' => ($kinds[$action['kind']] ?? 'Tindakan') . ' • ' . mb_strimwidth((string) $action['description'], 0, 80, '…'),
                    'date' => $action['performed_date'],
                    'by' => (string) $action['actor_name'],
                ];
            }
        }
        usort($history, static fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));

        return array_slice($history, 0, 20);
    }

    /**
     * The item a scanned label or typed code points to.
     *
     * Accepts the label's public link (checked against its signature), the staff link printed
     * by older labels, or an item code.
     */
    public function lookup(Context $context): JsonResponse
    {
        $scanned = trim($context->request->query('qr'));
        $code = trim($context->request->query('code'));
        $id = null;
        if ($scanned !== '') {
            parse_str((string) (parse_url($scanned, PHP_URL_QUERY) ?? ''), $query);
            if (isset($query['i'], $query['t']) && is_string($query['i']) && is_string($query['t'])) {
                if (!PublicLink::verify($context->db, (int) $query['i'], $query['t'])) {
                    throw new ApiException('invalid_label', 'Label ini bukan label inventaris perpustakaan ini.', 422);
                }
                $id = (int) $query['i'];
            } elseif (isset($query['qr']) && is_string($query['qr']) && ctype_digit($query['qr'])) {
                $id = (int) $query['qr'];
            } else {
                $code = $scanned;
            }
        }
        if ($id === null) {
            if ($code === '') {
                throw ApiException::validation(['code' => ['Wajib diisi.']]);
            }
            $statement = $context->db->prepare('SELECT id FROM inventory_items WHERE item_code = ? ORDER BY id LIMIT 1');
            $statement->execute([mb_substr($code, 0, 150)]);
            $found = $statement->fetchColumn();
            if ($found === false) {
                throw Failure::notFound('Tidak ada barang dengan kode ' . mb_substr($code, 0, 40) . '. Periksa label, lalu coba lagi.');
            }
            $id = (int) $found;
        }

        return JsonResponse::ok(self::full($context, $id));
    }

    /** @return array<string, mixed> Inventory::values() input, from the app's field names. */
    private static function fields(Input $input, array $base): array
    {
        $values = $base;
        foreach (self::FIELDS as $field => $column) {
            if ($input->has($field)) {
                $value = $input->get($field);
                // Categories come as codes, or as the item was presented: an entry per category with its code.
                if ($field === 'categories' && is_array($value)) {
                    $value = array_map(static fn (mixed $entry): mixed => is_array($entry) ? ($entry['code'] ?? '?') : $entry, $value);
                }
                $values[$column] = $value === null ? '' : $value;
            }
        }

        return $values;
    }

    private static function codeOwner(Context $context, Input $input): string
    {
        $token = $input->string('code_token', 64);

        return $token === '' ? '' : ItemCodes::owner($token, 'invensync:' . $context->staff()->sessionId);
    }

    public function store(Context $context): JsonResponse
    {
        $input = $context->input();
        $photos = ItemPhotos::uploads($input->files('photos'));
        $id = Inventory::saveItem($context->db, $context->storage, self::fields($input, []), 0, $context->staff()->id, self::codeOwner($context, $input), $photos);
        $context->log('Barang inventaris #' . $id . ' ditambahkan' . ($photos ? ' dengan ' . count($photos) . ' foto' : '') . '.', 'Create');

        return JsonResponse::ok(self::full($context, $id), 201);
    }

    /**
     * Changes the fields sent and keeps the rest. An item may have several categories: `categories`
     * replaces them all with the codes sent, and an empty list leaves the item uncategorised. With updated_at, refuses a change made on a
     * copy older than what SLiMS holds now, so an offline edit never silently undoes another.
     */
    public function update(Context $context, int $id): JsonResponse
    {
        $input = $context->input();
        $row = self::row($context, $id);
        if ($input->has('updated_at') && $input->string('updated_at', 30) !== (string) $row['updated_at']) {
            throw new ApiException('stale_version', 'Barang ini sudah diubah di perangkat lain. Muat ulang, lalu simpan lagi.', 409, ['item' => Present::item($row, self::photoIds($context, $id))]);
        }
        Inventory::saveItem($context->db, $context->storage, self::fields($input, $row), $id, $context->staff()->id, self::codeOwner($context, $input));
        $context->log('Barang inventaris #' . $id . ' diperbarui.', 'Update');

        return JsonResponse::ok(self::full($context, $id));
    }

    /** Reserves the next item code of the room's library, for the form that sent code_token. */
    public function reserveCode(Context $context): JsonResponse
    {
        $input = $context->input();
        $token = $input->required('code_token', 64);
        $owner = ItemCodes::owner($token, 'invensync:' . $context->staff()->sessionId);
        $context->db->beginTransaction();
        try {
            $code = ItemCodes::reserve($context->db, $input->int('room_id'), $owner);
            $context->db->commit();
        } catch (\Throwable $error) {
            if ($context->db->inTransaction()) {
                $context->db->rollBack();
            }
            throw $error;
        }

        return JsonResponse::ok(['code' => $code]);
    }

    public function addPhotos(Context $context, int $id): JsonResponse
    {
        $photos = ItemPhotos::uploads($context->input()->files('photos'));
        if (!$photos) {
            throw ApiException::validation(['photos' => ['Pilih minimal satu foto.']]);
        }
        $created = [];
        $removed = [];
        $context->db->beginTransaction();
        try {
            $lock = $context->db->prepare('SELECT id FROM inventory_items WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            if (!$lock->fetchColumn()) {
                throw Failure::notFound('Barang tidak ditemukan.');
            }
            ItemPhotos::apply($context->db, $id, $photos, [], $context->storage, $created, $removed);
            $context->db->prepare('UPDATE inventory_items SET updated_at = NOW() WHERE id = ?')->execute([$id]);
            $context->db->commit();
        } catch (\Throwable $error) {
            if ($context->db->inTransaction()) {
                $context->db->rollBack();
            }
            $context->storage->cleanup($created);
            throw $error;
        }
        $context->log('Foto barang #' . $id . ' ditambahkan: ' . count($photos) . '.', 'Update');

        return JsonResponse::ok(self::full($context, $id), 201);
    }

    public function deletePhoto(Context $context, int $id, int $photoId): JsonResponse
    {
        $context->db->beginTransaction();
        try {
            $filename = ItemPhotos::deleteOne($context->db, $id, $photoId);
            $context->db->prepare('UPDATE inventory_items SET updated_at = NOW() WHERE id = ?')->execute([$id]);
            $context->db->commit();
        } catch (\Throwable $error) {
            if ($context->db->inTransaction()) {
                $context->db->rollBack();
            }
            throw $error;
        }
        $context->storage->cleanup([$filename]);
        $context->log('Foto #' . $photoId . ' barang #' . $id . ' dihapus.', 'Delete');

        return JsonResponse::ok(self::full($context, $id));
    }

    public function photo(Context $context, int $id, int $photoId): Sendable
    {
        $statement = $context->db->prepare('SELECT filename FROM inventory_item_photos WHERE id = ? AND item_id = ?');
        $statement->execute([$photoId, $id]);
        $filename = $statement->fetchColumn();
        $bytes = is_string($filename) ? $context->storage->read($filename) : null;
        if ($bytes === null) {
            throw Failure::notFound('Foto tidak ditemukan.');
        }

        return new BytesResponse($bytes, 'image/jpeg', 'foto-barang-' . $photoId . '.jpg');
    }

    public function label(Context $context, int $id): Sendable
    {
        RoomController::throttlePdf($context);
        $row = self::row($context, $id);
        $document = $context->documents()->labels((int) $row['location_id'], [$id], $context->request->query('preset', 'thermal-50x30'), $context->siteUrl());
        $context->log('Label barang #' . $id . ' diunduh.', 'Print');

        return new BytesResponse($document['bytes'], 'application/pdf', $document['filename']);
    }
}
