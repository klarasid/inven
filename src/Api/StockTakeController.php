<?php

namespace SLiMS\Plugins\Inventory\Api;

use SLiMS\Plugins\Inventory\StockTake;
use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Sendable;

final class StockTakeController
{
    private const MAX_SCANS = 200;

    public function index(Context $context): JsonResponse
    {
        return JsonResponse::ok((new StockTake($context->db))->sessions());
    }

    public function show(Context $context, int $id): JsonResponse
    {
        $stockTake = new StockTake($context->db);
        $session = $stockTake->session($id);
        $session['recent'] = $session['active'] ? $stockTake->items($id, 'found', '', 1, 10)['rows'] : [];

        return JsonResponse::ok($session);
    }

    /**
     * Marks a batch of scanned copies found. Scans are applied one by one and each gets its own
     * outcome, so a batch sent again after a lost answer reports "already" instead of failing.
     */
    public function scan(Context $context, int $id): JsonResponse
    {
        $scans = $context->input()->get('scans');
        if (!is_array($scans) || !$scans || count($scans) > self::MAX_SCANS || !array_is_list($scans)) {
            throw ApiException::validation(['scans' => ['Kirim 1–' . self::MAX_SCANS . ' pindaian.']]);
        }
        $stockTake = new StockTake($context->db);
        $name = $context->staff()->name;
        $results = [];
        $found = 0;
        foreach ($scans as $scan) {
            $code = is_array($scan) ? (string) ($scan['code'] ?? '') : (string) $scan;
            $outcome = $stockTake->scan($id, $code, $name);
            $found += $outcome['outcome'] === StockTake::FOUND ? 1 : 0;
            $results[] = [
                'code' => trim($code),
                'client_id' => is_array($scan) && isset($scan['client_id']) ? mb_substr((string) $scan['client_id'], 0, 64) : null,
                ...$outcome,
            ];
        }
        if ($found > 0) {
            $context->log('Stock opname #' . $id . ': ' . $found . ' eksemplar ditandai ditemukan.', 'Update');
        }

        return JsonResponse::ok(['results' => $results, 'session' => $stockTake->session($id)]);
    }

    public function items(Context $context, int $id): JsonResponse
    {
        $stockTake = new StockTake($context->db);
        $stockTake->session($id);
        $page = $stockTake->items(
            $id,
            $context->request->query('status', 'missing'),
            mb_substr(trim($context->request->query('q')), 0, 100),
            $context->request->queryInt('page', 1, 1, 10000),
        );

        return JsonResponse::ok($page['rows'], 200, ['pagination' => ['page' => $page['page'], 'pages' => $page['pages'], 'total' => $page['total']]]);
    }

    /** Copy codes and their state, in pages after ?after=, for checking scans while offline. */
    public function codes(Context $context, int $id): JsonResponse
    {
        $stockTake = new StockTake($context->db);
        $stockTake->session($id);
        $rows = $stockTake->codes($id, $context->request->query('after'));
        $states = array_flip(StockTake::STATUSES);

        return JsonResponse::ok(
            array_map(static fn (array $row): array => [$row[0], $states[$row[1]] ?? 'missing'], $rows),
            200,
            ['next' => count($rows) === 5000 ? end($rows)[0] : null],
        );
    }

    public function document(Context $context, int $id): Sendable
    {
        RoomController::throttlePdf($context);
        $document = $context->documents()->stockTake(new StockTake($context->db), $id, Context::libraryName());

        return new BytesResponse($document['bytes'], 'application/pdf', $document['filename']);
    }
}
