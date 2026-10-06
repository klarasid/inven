<?php

namespace SLiMS\Plugins\Inventory\Api;

use SLiMS\Plugins\Inventory\Supervision;
use SLiMS\Plugins\Inventory\WatchPdf;
use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Sendable;

/**
 * Laporan: the supervision summary for this month, the last three months, or this year, the
 * inspections of the period, and the documents: the period report and one inspection's own.
 */
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

    private const KINDS = ['routine' => 'Terjadwal', 'incidental' => 'Insidental', 'historical' => 'Impor riwayat'];
    private const MAX_INSPECTIONS = 500;

    /** The inspections of the period, newest first, optionally of one room (?room=<id>). */
    public function inspections(Context $context): JsonResponse
    {
        $filter = self::filter($context);
        $filter['room'] = max(0, (int) $context->request->query('room', '0'));
        $rows = $context->watch()->inspections($filter, 1, self::MAX_INSPECTIONS + 1);
        if (count($rows) > self::MAX_INSPECTIONS) {
            throw Failure::rejected('Periode ini memuat lebih dari ' . self::MAX_INSPECTIONS . ' pemeriksaan. Pilih periode yang lebih pendek atau satu ruangan.');
        }

        return JsonResponse::ok([
            'period' => ['from' => $filter['from'], 'to' => $filter['to']],
            'inspections' => array_map(static function (array $row): array {
                $snapshot = Supervision::decode((string) $row['snapshot']);

                return [
                    'id' => (int) $row['id'],
                    'kind' => ['key' => (string) $row['kind'], 'label' => self::KINDS[$row['kind']] ?? 'Pemeriksaan'],
                    'checklist' => (string) ($snapshot['template_name'] ?? ''),
                    'room' => ['id' => (int) ($snapshot['room_id'] ?? 0), 'name' => (string) ($snapshot['room_name'] ?? '')],
                    'due_date' => (string) $row['due_date'],
                    'performed_date' => $row['performed_date'] === null ? null : (string) $row['performed_date'],
                    'examiner' => (string) ($row['examiner_name'] ?? ''),
                    'status' => ['key' => (string) $row['status'], 'label' => Supervision::STATUSES[$row['status']] ?? (string) $row['status']],
                ];
            }, $rows),
        ]);
    }

    /**
     * The findings of the period's inspections, by deadline, each with the work recorded on it:
     * what was found where, who handles it, and what was done. Optionally of one room (?room=<id>).
     */
    public function findings(Context $context): JsonResponse
    {
        require_once dirname(__DIR__) . '/WatchPdf.php';
        $filter = self::filter($context);
        $filter['room'] = max(0, (int) $context->request->query('room', '0'));
        $watch = $context->watch();
        $rows = $watch->summary($filter, true)['finding_rows'];
        if (count($rows) > self::MAX_INSPECTIONS) {
            throw Failure::rejected('Periode ini memuat lebih dari ' . self::MAX_INSPECTIONS . ' temuan. Pilih periode yang lebih pendek atau satu ruangan.');
        }
        $actions = [];
        if ($rows) {
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            foreach ($watch->query('SELECT * FROM inventory_watch_actions WHERE finding_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id', $ids)->fetchAll(\PDO::FETCH_ASSOC) as $action) {
                $actions[(int) $action['finding_id']][] = [
                    'kind' => ['key' => (string) $action['kind'], 'label' => WatchPdf::ACTIONS[$action['kind']] ?? (string) $action['kind']],
                    'description' => (string) $action['description'],
                    'performed_date' => (string) $action['performed_date'],
                    'actor' => (string) $action['actor_name'],
                    'cost' => $action['cost'] === null ? null : (float) $action['cost'],
                    // Work still being written is a draft until it is handed in for verification.
                    'submitted' => $action['submitted_at'] !== null,
                ];
            }
        }
        $today = date('Y-m-d');

        return JsonResponse::ok([
            'period' => ['from' => $filter['from'], 'to' => $filter['to']],
            'findings' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'inspection_id' => (int) $row['inspection_id'],
                'object' => (string) $row['object'],
                'room' => (string) $row['room'],
                'status' => ['key' => (string) $row['status'], 'label' => Supervision::STATUSES[$row['status']] ?? (string) $row['status']],
                'priority' => ['key' => (string) $row['priority'], 'label' => Supervision::PRIORITIES[$row['priority']] ?? (string) $row['priority']],
                'deadline' => (string) $row['deadline'],
                'overdue' => $row['status'] !== 'closed' && (string) $row['deadline'] < $today,
                'assignee' => (string) $row['assignee_name'],
                'found_at' => (string) $row['created_at'],
                'closed_at' => $row['closed_at'] === null ? null : (string) $row['closed_at'],
                'actions' => $actions[(int) $row['id']] ?? [],
            ], $rows),
        ]);
    }

    /** One inspection as PDF: its berita acara, with what was examined, the findings and their photos. */
    public function inspection(Context $context, int $id): Sendable
    {
        RoomController::throttlePdf($context);
        $document = $context->documents()->inspection($context->watch(), $id);
        $context->log('Dokumen pemeriksaan #' . $id . ' diunduh.', 'Print');

        return new BytesResponse($document['bytes'], 'application/pdf', $document['filename']);
    }

    public function document(Context $context): Sendable
    {
        RoomController::throttlePdf($context);
        $document = $context->documents()->period($context->watch(), self::filter($context), $context->staff()->name);
        $context->log('Laporan pengawasan ' . self::filter($context)['from'] . ' s.d. ' . self::filter($context)['to'] . ' diunduh.', 'Print');

        return new BytesResponse($document['bytes'], 'application/pdf', $document['filename']);
    }
}
