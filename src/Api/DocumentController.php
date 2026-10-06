<?php

namespace SLiMS\Plugins\Inventory\Api;

use SLiMS\Plugins\Inventory\RoomPlans;
use SLiMS\Plugins\Inventory\Sarpras;
use SLiMS\Plugins\Inventory\SupportDocuments;
use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Sendable;

/**
 * Dokumen pendukung: the files a library location keeps as evidence of its facilities, each of a
 * kind under a topic (its internet, its security and safety), for one topic (?topic=keamanan) and
 * one location (?library=P01) when given. Read only; the files are uploaded on Gedung & Jaringan.
 */
final class DocumentController
{
    public function index(Context $context): JsonResponse
    {
        $topic = trim($context->request->query('topic'));
        if ($topic !== '' && !isset(SupportDocuments::TOPICS[$topic])) {
            throw ApiException::validation(['topic' => ['Pilih dari: ' . implode(', ', array_keys(SupportDocuments::TOPICS)) . '.']]);
        }
        $places = Sarpras::locations($context->db)['locations'] ?: [['code' => '', 'name' => Context::libraryName()]];
        $codes = array_values(array_filter(array_column($places, 'code'), static fn (string $code): bool => $code !== ''));
        $library = trim($context->request->query('library'));
        if ($library !== '' && !in_array($library, $codes, true)) {
            throw ApiException::validation(['library' => ['Lokasi perpustakaan tidak ditemukan. Pilih salah satu: ' . implode(', ', $codes) . '.']]);
        }

        $kinds = [];
        foreach (SupportDocuments::KINDS as $kind => $about) {
            if ($topic === '' || $about['topic'] === $topic) {
                $kinds[$kind] = ['label' => $about['label'], 'topic' => $about['topic']];
            }
        }
        $locations = [];
        foreach ($places as $place) {
            if ($library !== '' && $place['code'] !== $library) {
                continue;
            }
            $locations[] = [
                'code' => $place['code'],
                'name' => $place['name'],
                'documents' => array_map(static fn (array $document): array => [
                    'id' => $document['id'],
                    'topic' => $document['topic'],
                    'kind' => ['key' => $document['kind'], 'label' => SupportDocuments::KINDS[$document['kind']]['label'] ?? $document['kind']],
                    'title' => $document['title'],
                    'type' => RoomPlans::TYPES[$document['mime']] ?? '',
                    'created_at' => $document['created_at'],
                    'room' => $document['room'],
                ], SupportDocuments::of($context->db, $place['code'], $topic === '' ? null : $topic)),
            ];
        }

        return JsonResponse::ok([
            'topics' => $topic === '' ? SupportDocuments::TOPICS : [$topic => SupportDocuments::TOPICS[$topic]],
            'kinds' => $kinds,
            'locations' => $locations,
        ]);
    }

    /** A supporting document, as it was uploaded. */
    public function show(Context $context, int $id): Sendable
    {
        $file = SupportDocuments::file($context->db, $id);
        $bytes = $file === null ? false : @file_get_contents($file['path']);
        if ($file === null || $bytes === false) {
            throw Failure::notFound('Dokumen tidak ditemukan.');
        }

        return new BytesResponse($bytes, $file['mime'], $file['name']);
    }
}
