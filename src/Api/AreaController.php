<?php

namespace SLiMS\Plugins\Inventory\Api;

use PDO;
use SLiMS\Plugins\Inventory\AreaCatalog;
use SLiMS\Plugins\Inventory\AreaPhotos;
use SLiMS\Plugins\Inventory\RoomAreas;
use SLiMS\Plugins\Inventory\Sarpras;
use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Sendable;

/**
 * Daftar area dan fasilitas: the areas inside the rooms with their rooms and photos, for one
 * group (?group=dasar, pendukung or umum) and one library location (?library=P01) when given.
 *
 * And one room's areas, whose photos the app can add and remove. The areas themselves are
 * recorded on the room's Area tab in SLiMS; the app does not add or rename them.
 */
final class AreaController
{
    /** A room's areas with the ids of their photos. */
    public function ofRoom(Context $context, int $id): JsonResponse
    {
        return JsonResponse::ok(self::room($context, $id));
    }

    /**
     * Photos of one area of the room, as multipart photos[]: all of them are kept or none is, and
     * never more than AreaPhotos::MAX_PER_AREA to an area.
     */
    public function addPhotos(Context $context, int $id, int $areaId): JsonResponse
    {
        $files = $context->input()->files('photos');
        $uploads = [];
        foreach ($files['error'] ?? [] as $key => $error) {
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $uploads[] = ['error' => $error, 'tmp_name' => $files['tmp_name'][$key] ?? null];
        }
        if (!$uploads) {
            throw ApiException::validation(['photos' => ['Pilih minimal satu foto.']]);
        }

        $db = $context->db;
        $storage = AreaPhotos::storage();
        $added = [];
        $db->beginTransaction();
        try {
            // Serialises photo changes to this area, so two phones cannot pass the limit together.
            $lock = $db->prepare('SELECT id FROM inventory_room_areas WHERE id = ? AND location_id = ? FOR UPDATE');
            $lock->execute([$areaId, $id]);
            if (!$lock->fetchColumn()) {
                throw Failure::notFound('Area tidak ditemukan di ruangan ini.');
            }
            $count = $db->prepare('SELECT COUNT(*) FROM inventory_area_photos WHERE area_id = ?');
            $count->execute([$areaId]);
            $left = AreaPhotos::MAX_PER_AREA - (int) $count->fetchColumn();
            if (count($uploads) > $left) {
                throw Failure::rejected($left < 1
                    ? 'Satu area memuat paling banyak ' . AreaPhotos::MAX_PER_AREA . ' foto. Hapus foto lama sebelum menambah.'
                    : 'Area ini hanya bisa menerima ' . $left . ' foto lagi (paling banyak ' . AreaPhotos::MAX_PER_AREA . ').');
            }
            foreach ($uploads as $upload) {
                $added[] = AreaPhotos::add($db, $storage, $id, $areaId, $upload, $context->staff()->id, date('Y-m-d H:i:s'));
            }
            $db->commit();
        } catch (\Throwable $error) {
            $written = [];
            if ($added) {
                try {
                    $query = $db->prepare('SELECT filename FROM inventory_area_photos WHERE id IN (' . implode(',', array_fill(0, count($added), '?')) . ')');
                    $query->execute($added);
                    $written = $query->fetchAll(PDO::FETCH_COLUMN);
                } catch (\Throwable) {
                    // The files stay behind; the rows that named them go with the rollback.
                }
            }
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $storage->cleanup($written);
            throw $error;
        }
        $context->log('Foto area #' . $areaId . ' di ruangan #' . $id . ' ditambahkan: ' . count($added) . '.', 'Update');

        return JsonResponse::ok(self::room($context, $id), 201);
    }

    public function photo(Context $context, int $id, int $areaId, int $photoId): Sendable
    {
        $filename = self::photoFile($context, $id, $areaId, $photoId);
        $bytes = $filename === null ? null : AreaPhotos::storage()->read($filename);
        if ($bytes === null) {
            throw Failure::notFound('Foto tidak ditemukan.');
        }

        return new BytesResponse($bytes, 'image/jpeg', 'foto-area-' . $photoId . '.jpg');
    }

    public function deletePhoto(Context $context, int $id, int $areaId, int $photoId): JsonResponse
    {
        if (self::photoFile($context, $id, $areaId, $photoId) === null) {
            throw Failure::notFound('Foto tidak ditemukan pada area ini atau sudah dihapus.');
        }
        AreaPhotos::delete($context->db, AreaPhotos::storage(), $id, $photoId);
        $context->log('Foto #' . $photoId . ' area #' . $areaId . ' di ruangan #' . $id . ' dihapus.', 'Delete');

        return JsonResponse::ok(self::room($context, $id));
    }

    /** The stored file of a photo of this area of this room, or null when it is not one. */
    private static function photoFile(Context $context, int $id, int $areaId, int $photoId): ?string
    {
        $query = $context->db->prepare('SELECT p.filename FROM inventory_area_photos p JOIN inventory_room_areas a ON a.id = p.area_id WHERE p.id = ? AND p.area_id = ? AND a.location_id = ?');
        $query->execute([$photoId, $areaId, $id]);
        $filename = $query->fetchColumn();

        return is_string($filename) ? $filename : null;
    }

    /** @return array{room: array<string, mixed>, areas: list<array<string, mixed>>, max_photos: int} */
    private static function room(Context $context, int $id): array
    {
        $room = RoomController::room($context, $id);
        $photos = AreaPhotos::byArea($context->db, $id);

        return [
            'room' => $room,
            'areas' => array_map(static fn (array $area): array => [
                'id' => $area['id'],
                'type' => $area['type'],
                'type_label' => Sarpras::AREA_TYPES[$area['type']]['label'] ?? $area['type'],
                'name' => $area['name'],
                'photo_ids' => array_column($photos[$area['id']] ?? [], 'id'),
            ], RoomAreas::of($context->db, $id)),
            'max_photos' => AreaPhotos::MAX_PER_AREA,
        ];
    }

    /** @return array{0: string, 1: string} group, library */
    private static function filter(Context $context): array
    {
        $group = trim($context->request->query('group'));
        if ($group !== '' && !isset(Sarpras::AREA_GROUPS[$group])) {
            throw ApiException::validation(['group' => ['Pilih dari: ' . implode(', ', array_keys(Sarpras::AREA_GROUPS)) . '.']]);
        }
        $library = trim($context->request->query('library'));
        $codes = array_column(Sarpras::locations($context->db)['locations'], 'code');
        if ($library !== '' && !in_array($library, $codes, true)) {
            throw ApiException::validation(['library' => ['Lokasi perpustakaan tidak ditemukan. Pilih salah satu: ' . implode(', ', $codes) . '.']]);
        }

        return [$group, $library];
    }

    /** What the list would hold: the areas of each group by kind, and how many have a photo. */
    public function summary(Context $context): JsonResponse
    {
        [$group, $library] = self::filter($context);

        return JsonResponse::ok(AreaCatalog::summary(AreaCatalog::build($context->db, $group, $library)));
    }

    public function document(Context $context): Sendable
    {
        RoomController::throttlePdf($context);
        [$group, $library] = self::filter($context);
        $document = $context->documents()->areas($context->storage, $group, $library, Context::libraryName(), $context->staff()->name, $context->request->query('style'));
        $context->log('Daftar area dan fasilitas diunduh (' . $document['count'] . ' area).', 'Print');

        return new BytesResponse($document['bytes'], 'application/pdf', $document['filename']);
    }
}
