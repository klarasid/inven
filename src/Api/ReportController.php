<?php

namespace SLiMS\Plugins\Inventory\Api;

use SLiMS\Plugins\Inventory\Sarpras;
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
     * A named period (month, quarter, year), or any ?from=&to= dates, of one library location
     * (?library=<code>) or one room (?room=<id>) when given.
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
            $filter = $context->watch()->filter(['from' => $from, 'to' => $to, 'library' => self::library($context), 'room' => $context->request->query('room', '0')]);
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
        $filter = $context->watch()->filter(['from' => $from, 'to' => $period === 'year' ? date('Y-12-31') : date('Y-m-t'), 'library' => self::library($context), 'room' => $context->request->query('room', '0')]);
        $filter['inspection_status'] = '';
        $filter['finding_status'] = '';

        return $filter;
    }

    /** The library location asked for, one of those holding rooms, or '' for all of them. */
    private static function library(Context $context): string
    {
        $library = trim($context->request->query('library'));
        $codes = array_column(Sarpras::locations($context->db)['locations'], 'code');
        if ($library !== '' && !in_array($library, $codes, true)) {
            throw ApiException::validation(['library' => ['Lokasi perpustakaan tidak ditemukan. Pilih salah satu: ' . implode(', ', $codes) . '.']]);
        }

        return $library;
    }

    /** The period as the answers echo it, with the location and room they cover, so a caller can tell the filter was applied. */
    private static function period(array $filter): array
    {
        return ['from' => $filter['from'], 'to' => $filter['to'], 'library' => $filter['library'], 'room' => $filter['room'] ?: null];
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
            'period' => ['key' => $context->request->query('from') !== '' ? 'custom' : $context->request->query('period', 'month')] + self::period($filter),
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

    /** The inspections of the period, newest first, with how many photos each holds; optionally of one location or room. */
    public function inspections(Context $context): JsonResponse
    {
        $filter = self::filter($context);
        $rows = $context->watch()->inspections($filter, 1, self::MAX_INSPECTIONS + 1);
        if (count($rows) > self::MAX_INSPECTIONS) {
            throw Failure::rejected('Periode ini memuat lebih dari ' . self::MAX_INSPECTIONS . ' pemeriksaan. Pilih periode yang lebih pendek atau satu ruangan.');
        }

        $photos = [];
        if ($rows) {
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            $photos = $context->watch()->query('SELECT inspection_id, COUNT(*) FROM inventory_watch_photos WHERE inspection_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY inspection_id', $ids)->fetchAll(\PDO::FETCH_KEY_PAIR);
        }

        return JsonResponse::ok([
            'period' => self::period($filter),
            'inspections' => array_map(static function (array $row) use ($photos): array {
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
                    // The photos of the items checked and of the follow-up work, which /inspections/{id}/archive holds.
                    'photos' => (int) ($photos[$row['id']] ?? 0),
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
            'period' => self::period($filter),
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

    /** One inspection as PDF: its berita acara, with what was examined, the findings and their photos. ?style= as in Documents::inspection. */
    public function inspection(Context $context, int $id): Sendable
    {
        RoomController::throttlePdf($context);
        $document = $context->documents()->inspection($context->watch(), $id, $context->request->query('style'));
        $context->log('Dokumen pemeriksaan #' . $id . ' diunduh.', 'Print');

        return new BytesResponse($document['bytes'], 'application/pdf', $document['filename']);
    }

    /** One inspection's photos as they were uploaded, in a ZIP: those of the items checked and of the follow-up work. */
    public function photos(Context $context, int $id): Sendable
    {
        RoomController::throttlePdf($context);
        $document = $context->documents()->inspectionPhotos($context->watch(), $id);
        $context->log('Foto pemeriksaan #' . $id . ' diunduh (' . $document['count'] . ' foto).', 'Download');

        return new BytesResponse($document['bytes'], 'application/zip', $document['filename']);
    }

    public function document(Context $context): Sendable
    {
        RoomController::throttlePdf($context);
        $filter = self::filter($context);
        $document = $context->documents()->period($context->watch(), $filter, $context->staff()->name, $context->request->query('style'));
        $context->log('Laporan pengawasan ' . $filter['from'] . ' s.d. ' . $filter['to'] . ($filter['library'] !== '' ? ' lokasi ' . $filter['library'] : '') . ' diunduh.', 'Print');

        return new BytesResponse($document['bytes'], 'application/pdf', $document['filename']);
    }
}
