<?php

namespace SLiMS\Plugins\Inventory\Api;

use SLiMS\Plugins\Inventory\NetworkDocuments;
use SLiMS\Plugins\Inventory\RoomPlans;
use SLiMS\Plugins\Inventory\Sarpras;
use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Sendable;

/**
 * What shows a library location's internet (Gedung & Jaringan): the bandwidth figures with their
 * evidence file, and the network documents: speed tests, the ISP's service, the Wi-Fi coverage
 * map. Read only; the files are uploaded on the page.
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

    /** Every location with its figures, its evidence and its documents, or the one asked for. */
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
            $evidence = is_array($settings['evidence']) && Sarpras::evidenceFile($context->db, $place['code']) !== null ? $settings['evidence'] : null;
            $locations[] = [
                'code' => $place['code'],
                'name' => $place['name'],
                'bandwidth' => [
                    'mbps' => (float) $settings['bandwidth_mbps'],
                    'users' => (int) $settings['bandwidth_users'],
                    'coverage' => ['key' => $settings['bandwidth_coverage'], 'label' => Sarpras::COVERAGE[$settings['bandwidth_coverage']] ?? ''],
                    'measured_at' => $settings['bandwidth_date'] !== '' ? $settings['bandwidth_date'] : null,
                ],
                'evidence' => $evidence === null ? null : ['name' => (string) $evidence['name'], 'type' => self::type((string) $evidence['mime']), 'uploaded_at' => (string) $evidence['uploaded_at']],
                'documents' => array_map(static fn (array $document): array => [
                    'id' => $document['id'],
                    'kind' => ['key' => $document['kind'], 'label' => NetworkDocuments::KINDS[$document['kind']] ?? $document['kind']],
                    'title' => $document['title'],
                    'type' => self::type($document['mime']),
                    'created_at' => $document['created_at'],
                    'room' => $document['room'],
                ], NetworkDocuments::of($context->db, $place['code'])),
            ];
        }

        return JsonResponse::ok(['kinds' => NetworkDocuments::KINDS, 'locations' => $locations]);
    }

    /** A location's bandwidth evidence file, as it was uploaded. */
    public function evidence(Context $context): Sendable
    {
        $codes = array_column(Sarpras::locations($context->db)['locations'], 'code');
        $file = Sarpras::evidenceFile($context->db, self::library($context, $codes));

        return self::send($file, 'Bukti pengukuran tidak ditemukan.', 'bukti-pengukuran');
    }

    /** A network document, as it was uploaded. */
    public function document(Context $context, int $id): Sendable
    {
        return self::send(NetworkDocuments::file($context->db, $id), 'Dokumen jaringan tidak ditemukan.', null);
    }

    /** @param array{path:string,mime:string,name:string}|null $file */
    private static function send(?array $file, string $missing, ?string $basename): Sendable
    {
        $type = $file === null ? '' : self::type($file['mime']);
        $bytes = $file === null || $type === '' ? false : @file_get_contents($file['path']);
        if ($bytes === false) {
            throw Failure::notFound($missing);
        }

        return new BytesResponse($bytes, $file['mime'], $basename === null ? $file['name'] : $basename . '.' . $type);
    }
}
