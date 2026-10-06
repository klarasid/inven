<?php

namespace SLiMS\Plugins\Inventory\Api;

use SLiMS\Plugins\Inventory\AreaCatalog;
use SLiMS\Plugins\Inventory\Sarpras;
use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Sendable;

/**
 * Daftar area dan fasilitas: the areas inside the rooms with their rooms and photos, for one
 * group (?group=dasar, pendukung or umum) and one library location (?library=P01) when given.
 * Read only; areas and their photos are recorded on a room's Area tab.
 */
final class AreaController
{
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
        $document = $context->documents()->areas($context->storage, $group, $library, Context::libraryName(), $context->staff()->name);
        $context->log('Daftar area dan fasilitas diunduh (' . $document['count'] . ' area).', 'Print');

        return new BytesResponse($document['bytes'], 'application/pdf', $document['filename']);
    }
}
