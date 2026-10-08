<?php

namespace SLiMS\Plugins\Inventory\Api;

use SLiMS\Plugins\Inventory\InventoryCatalog;
use SLiMS\Plugins\Inventory\Sarpras;
use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Sendable;

/**
 * Daftar inventaris berfoto: the items with one photo each, grouped by category (?group=category,
 * the default) or by the areas of their rooms (?group=area), for some categories
 * (?categories=perabot,peralatan) and one library location (?library=P01) when given. An item is
 * shown with its first photo, or with all of them (?photos=all). The document takes ?style= as
 * the reports do (Documents::inspection).
 */
final class CatalogController
{
    /** @return array{0: string, 1: list<string>, 2: string, 3: string} group, categories, library, photos */
    private static function filter(Context $context): array
    {
        $group = $context->request->query('group', 'category');
        if (!isset(InventoryCatalog::GROUPINGS[$group])) {
            throw ApiException::validation(['group' => ['Pilih category atau area.']]);
        }
        $categories = array_values(array_filter(array_map('trim', explode(',', $context->request->query('categories')))));
        if (array_diff($categories, array_keys(Sarpras::CATEGORIES))) {
            throw ApiException::validation(['categories' => ['Pilih dari: ' . implode(', ', array_keys(Sarpras::CATEGORIES)) . '.']]);
        }
        $library = trim($context->request->query('library'));
        $codes = array_column(Sarpras::locations($context->db)['locations'], 'code');
        if ($library !== '' && !in_array($library, $codes, true)) {
            throw ApiException::validation(['library' => ['Lokasi perpustakaan tidak ditemukan. Pilih salah satu: ' . implode(', ', $codes) . '.']]);
        }

        $photos = $context->request->query('photos', 'first');
        if (!isset(InventoryCatalog::PHOTOS[$photos])) {
            throw ApiException::validation(['photos' => ['Pilih first atau all.']]);
        }

        return [$group, $categories, $library, $photos];
    }

    /** What the list would hold: how many items, how many with a photo, and the groups. */
    public function summary(Context $context): JsonResponse
    {
        [$group, $categories, $library, $photos] = self::filter($context);

        return JsonResponse::ok(InventoryCatalog::summary(InventoryCatalog::build($context->db, $group, $categories, $library, $photos)));
    }

    public function document(Context $context): Sendable
    {
        RoomController::throttlePdf($context);
        [$group, $categories, $library, $photos] = self::filter($context);
        $document = $context->documents()->catalog($context->storage, $group, $categories, $library, $photos, Context::libraryName(), $context->staff()->name, $context->request->query('style'));
        $context->log('Daftar inventaris berfoto diunduh (' . $document['count'] . ' barang).', 'Print');

        return new BytesResponse($document['bytes'], 'application/pdf', $document['filename']);
    }
}
