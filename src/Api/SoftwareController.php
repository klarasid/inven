<?php

namespace SLiMS\Plugins\Inventory\Api;

use SLiMS\Plugins\Inventory\Sarpras;
use SLiMS\Plugins\Inventory\SoftwareList;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Sendable;

/**
 * Daftar perangkat lunak: the software register with each application's use and licence, and
 * the register as a PDF. Read only; applications are recorded in the menu Perangkat Lunak.
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
                ];
            }
        }

        return JsonResponse::ok([
            'summary' => ['applications' => $list['applications'], 'licensed' => $list['licensed'], 'percent' => $list['percent']],
            'software' => $software,
        ]);
    }

    public function document(Context $context): Sendable
    {
        RoomController::throttlePdf($context);
        $document = $context->documents()->software($context->staff()->name);
        $context->log('Daftar perangkat lunak diunduh (' . $document['count'] . ' aplikasi).', 'Print');

        return new BytesResponse($document['bytes'], 'application/pdf', $document['filename']);
    }
}
