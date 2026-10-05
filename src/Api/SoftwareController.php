<?php

namespace SLiMS\Plugins\Inventory\Api;

use SLiMS\Plugins\Inventory\RoomPlans;
use SLiMS\Plugins\Inventory\Sarpras;
use SLiMS\Plugins\Inventory\SoftwareFiles;
use SLiMS\Plugins\Inventory\SoftwareList;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Sendable;

/**
 * Daftar perangkat lunak: the software register with each application's use and licence, the
 * files that show the licence, and the register as a PDF. Read only; applications and their
 * licence files are recorded in the menu Perangkat Lunak.
 *
 * The licence number or proof itself is left to the PDF, which the librarian opens: it may be a
 * product key, and the JSON is read by an AI app. Here an application only says it has one.
 */
final class SoftwareController
{
    public function index(Context $context): JsonResponse
    {
        $list = SoftwareList::build($context->db, date('Y-m-d'));
        $software = [];
        foreach ($list['groups'] as $group) {
            foreach ($group['items'] as $item) {
                $software[] = [
                    'id' => (int) $item['id'],
                    'name' => (string) $item['name'],
                    'version' => (string) $item['version'],
                    'purpose' => trim((string) $item['purpose']),
                    'licence' => ['key' => (string) $item['licence'], 'label' => Sarpras::LICENCES[$item['licence']] ?? (string) $item['licence']],
                    'has_licence_reference' => trim((string) $item['licence_ref']) !== '',
                    'valid_until' => $item['valid_until'] === null ? null : (string) $item['valid_until'],
                    'installs' => (int) $item['installs'],
                    'licensed' => $item['licensed'],
                    'expired' => $item['expired'],
                    'files' => array_map(static fn (array $file): array => [
                        'id' => $file['id'],
                        'title' => $file['title'],
                        'type' => RoomPlans::TYPES[$file['mime']] ?? '',
                        'created_at' => $file['created_at'],
                    ], $item['files']),
                ];
            }
        }

        return JsonResponse::ok([
            'summary' => ['applications' => $list['applications'], 'licensed' => $list['licensed'], 'percent' => $list['percent']],
            'software' => $software,
        ]);
    }

    /** A licence file of an application, as it was uploaded. */
    public function file(Context $context, int $id): Sendable
    {
        $file = SoftwareFiles::file($context->db, $id);
        $bytes = $file === null ? false : @file_get_contents($file['path']);
        if ($file === null || $bytes === false) {
            throw Failure::notFound('Bukti lisensi tidak ditemukan.');
        }

        return new BytesResponse($bytes, $file['mime'], $file['name']);
    }

    public function document(Context $context): Sendable
    {
        RoomController::throttlePdf($context);
        $document = $context->documents()->software($context->staff()->name);
        $context->log('Daftar perangkat lunak diunduh (' . $document['count'] . ' aplikasi).', 'Print');

        return new BytesResponse($document['bytes'], 'application/pdf', $document['filename']);
    }
}
