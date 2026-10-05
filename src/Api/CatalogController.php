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
 * (?categories=perabot,peralatan) and one library location (?library=P01) when given.
 */
final class CatalogController
{
    /** @return array{0: string, 1: list<string>, 2: string} group, categories, library */
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

        return [$group, $categories, $library];
    }

    /** What the list would hold: how many items, how many with a photo, and the groups. */
    public function summary(Context $context): JsonResponse
    {
        [$group, $categories, $library] = self::filter($context);

        return JsonResponse::ok(InventoryCatalog::summary(InventoryCatalog::build($context->db, $group, $categories, $library)));
    }

    public function document(Context $context): Sendable
    {
        RoomController::throttlePdf($context);
        [$group, $categories, $library] = self::filter($context);
        $document = $context->documents()->catalog($context->storage, $group, $categories, $library, Context::libraryName(), $context->staff()->name);
        $context->log('Daftar inventaris berfoto diunduh (' . $document['count'] . ' barang).', 'Print');

        return new BytesResponse($document['bytes'], 'application/pdf', $document['filename']);
    }
}
