<?php

namespace SLiMS\Plugins\Inventory\Api;

use SLiMS\Plugins\Inventory\Sarpras;
use SlimsConnect\Http\JsonResponse;

/**
 * Rekap Sarpras: the facility aspects of one library location, or of the institution when the
 * rooms are spread over several. Read only, computed as the Rekap Sarpras page computes it.
 */
final class SarprasController
{
    public function show(Context $context): JsonResponse
    {
        require_once dirname(__DIR__) . '/PdfLayout.php';
        require_once dirname(__DIR__) . '/Sarpras.php';
        $places = Sarpras::locations($context->db);
        $codes = array_column($places['locations'], 'code');
        $library = trim($context->request->query('library'));
        if ($library !== '' && !in_array($library, $codes, true)) {
            throw Failure::notFound('Lokasi perpustakaan tidak ditemukan. Pilih salah satu: ' . implode(', ', $codes) . '.');
        }
        if ($library === '' && count($codes) === 1 && $places['rooms'] > 0) {
            $library = $codes[0];
        }
        $several = $places['rooms'] > 0 && count($codes) > 1 && $library === '';
        $recap = $several ? Sarpras::overview($context->db, $context->watch(), $places['locations'])['recap'] : Sarpras::recap($context->db, $context->watch(), $library);
        $levels = Sarpras::LEVELS;

        return JsonResponse::ok([
            'scope' => $several ? 'institution' : 'location',
            'library' => $library,
            'locations' => array_map(static fn (array $place): array => ['code' => $place['code'], 'name' => $place['name'], 'rooms' => $place['rooms']], $places['locations']),
            'summary' => $recap['summary'],
            'aspects' => array_map(static fn (array $aspect): array => [
                'no' => $aspect['no'],
                'name' => $aspect['name'],
                'value' => $aspect['value'],
                'level' => $aspect['level'] === null ? null : ['key' => $aspect['level'], 'label' => $levels[$aspect['level']]],
                'basis' => $aspect['basis'],
                'checks' => $aspect['checks'],
                'fix' => $aspect['fix'],
            ], $recap['aspects']),
        ]);
    }
}
