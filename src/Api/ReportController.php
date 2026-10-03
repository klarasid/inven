<?php

namespace SLiMS\Plugins\Inventory\Api;

use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Sendable;

/** Laporan: the supervision summary for this month, the last three months, or this year. */
final class ReportController
{
    /**
     * A named period (month, quarter, year), or any ?from=&to= dates.
     *
     * @return array{from: string, to: string, library: string, room: int, inspection_status: string, finding_status: string}
     */
    private static function filter(Context $context): array
    {
        $from = trim($context->request->query('from'));
        $to = trim($context->request->query('to'));
        if ($from !== '' || $to !== '') {
            if ($from === '' || $to === '') {
                throw ApiException::validation(['from' => ['Isi from dan to bersama, format YYYY-MM-DD.']]);
            }
            $filter = $context->watch()->filter(['from' => $from, 'to' => $to]);
            $filter['inspection_status'] = '';
            $filter['finding_status'] = '';

            return $filter;
        }
        $period = $context->request->query('period', 'month');
        $from = match ($period) {
            'month' => date('Y-m-01'),
            'quarter' => date('Y-m-01', strtotime('first day of -2 months')),
            'year' => date('Y-01-01'),
            default => throw ApiException::validation(['period' => ['Pilih month, quarter, atau year.']]),
        };
        $filter = $context->watch()->filter(['from' => $from, 'to' => $period === 'year' ? date('Y-12-31') : date('Y-m-t')]);
        $filter['inspection_status'] = '';
        $filter['finding_status'] = '';

        return $filter;
    }

    public function summary(Context $context): JsonResponse
    {
        $filter = self::filter($context);
        $watch = $context->watch();
        $summary = $watch->summary($filter);
        [$where, $args] = $watch->where($filter);
        $review = (int) $watch->query("SELECT COUNT(*) FROM inventory_watch_findings f JOIN inventory_watch_inspections i ON i.id = f.inspection_id WHERE $where AND f.status = 'review'", $args)->fetchColumn();
        $counts = $summary['counts'];
        $findings = $summary['findings'];

        return JsonResponse::ok([
            'period' => ['key' => $context->request->query('from') !== '' ? 'custom' : $context->request->query('period', 'month'), 'from' => $filter['from'], 'to' => $filter['to']],
            'inspections' => [
                'routine_final' => (int) $counts['routine_final'],
                'late' => (int) $counts['late'] + (int) $summary['unformed_late'],
                'incidental' => (int) $counts['incidental'],
                'unformed' => (int) $summary['unformed'],
            ],
            'findings' => [
                'open' => (int) $findings['open'],
                'closed' => (int) $findings['closed'],
                'late' => (int) $findings['late'],
                'review' => $review,
            ],
            'coverage' => [
                'rooms_examined' => (int) $summary['room_examined'],
                'rooms_total' => (int) $summary['room_total'],
                'items_examined' => (int) $summary['item_examined'],
                'items_applicable' => (int) $summary['item_applicable'],
                'items_na' => (int) $summary['item_na'],
                'missing_rooms' => array_values(array_map(static fn (array $room): string => (string) $room['room_name'], $summary['missing_rooms'])),
            ],
        ]);
    }

    public function document(Context $context): Sendable
    {
        RoomController::throttlePdf($context);
        $document = $context->documents()->period($context->watch(), self::filter($context), $context->staff()->name);
        $context->log('Laporan pengawasan ' . self::filter($context)['from'] . ' s.d. ' . self::filter($context)['to'] . ' diunduh.', 'Print');

        return new BytesResponse($document['bytes'], 'application/pdf', $document['filename']);
    }
}
