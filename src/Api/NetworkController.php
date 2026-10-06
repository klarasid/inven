<?php

namespace SLiMS\Plugins\Inventory\Api;

use SLiMS\Plugins\Inventory\RoomPlans;
use SLiMS\Plugins\Inventory\Sarpras;
use SLiMS\Plugins\Inventory\SupportDocuments;
use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Sendable;

/**
 * What shows a library location's internet (Gedung & Jaringan): the bandwidth figures, and the
 * supporting documents of the topic: speed tests, the ISP's service, the Wi-Fi coverage map
 * (SupportDocuments; every topic is at /documents). Read only; the files are uploaded on the page.
 */
final class NetworkController
{
    /** The location asked for (?library=P01), or '' for all; '' is also the library as one unit. */
    private static function library(Context $context, array $codes): string
    {
        $library = trim($context->request->query('library'));
        if ($library !== '' && !in_array($library, $codes, true)) {
            throw ApiException::validation(['library' => ['Lokasi perpustakaan tidak ditemukan. Pilih salah satu: ' . implode(', ', $codes) . '.']]);
        }

        return $library;
    }

    private static function type(string $mime): string
    {
        return RoomPlans::TYPES[$mime] ?? '';
    }

    /** Every location with its figures and its documents, or the one asked for. */
    public function index(Context $context): JsonResponse
    {
        $places = Sarpras::locations($context->db)['locations'] ?: [['code' => '', 'name' => Context::libraryName()]];
        $library = self::library($context, array_values(array_filter(array_column($places, 'code'), static fn (string $code): bool => $code !== '')));

        $locations = [];
        foreach ($places as $place) {
            if ($library !== '' && $place['code'] !== $library) {
                continue;
            }
            $settings = Sarpras::settings($context->db, $place['code']);
            $locations[] = [
                'code' => $place['code'],
                'name' => $place['name'],
                'bandwidth' => [
                    'mbps' => (float) $settings['bandwidth_mbps'],
                    'users' => (int) $settings['bandwidth_users'],
                    'coverage' => ['key' => $settings['bandwidth_coverage'], 'label' => Sarpras::COVERAGE[$settings['bandwidth_coverage']] ?? ''],
                    'measured_at' => $settings['bandwidth_date'] !== '' ? $settings['bandwidth_date'] : null,
                ],
                'documents' => array_map(static fn (array $document): array => [
                    'id' => $document['id'],
                    'kind' => ['key' => $document['kind'], 'label' => SupportDocuments::KINDS[$document['kind']]['label'] ?? $document['kind']],
                    'title' => $document['title'],
                    'type' => self::type($document['mime']),
                    'created_at' => $document['created_at'],
                    'room' => $document['room'],
                ], SupportDocuments::of($context->db, $place['code'], 'jaringan')),
            ];
        }

        return JsonResponse::ok(['kinds' => SupportDocuments::labels('jaringan'), 'locations' => $locations]);
    }

    /** A network document, as it was uploaded. */
    public function document(Context $context, int $id): Sendable
    {
        return (new DocumentController())->show($context, $id);
    }
}
