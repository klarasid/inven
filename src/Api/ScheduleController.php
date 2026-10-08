<?php

namespace SLiMS\Plugins\Inventory\Api;

use SLiMS\Plugins\Inventory\Supervision;
use SLiMS\Plugins\Inventory\WatchRecurrence;
use SLiMS\Plugins\Inventory\Workspace;
use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Sendable;

/**
 * Checklists and routine inspection schedules, as the Jadwal and Checklist pages keep them. Every
 * change goes through Supervision::mutate, so its rules and audit trail apply unchanged.
 */
final class ScheduleController
{
    /** @return array<string, mixed> */
    private static function template(array $row): array
    {
        $items = is_array($row['items']) ? $row['items'] : Supervision::decode((string) $row['items']);

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'replaces' => $row['source_id'] === null ? null : (int) $row['source_id'],
            'items' => array_map(static fn (array $item): array => ['group' => (string) $item['group'], 'object' => (string) $item['object'], 'instruction' => (string) ($item['instruction'] ?? '')], $items),
        ];
    }

    /** @return array<string, mixed> */
    private static function schedule(array $row): array
    {
        $snapshot = is_array($row['snapshot']) ? $row['snapshot'] : Supervision::decode((string) $row['snapshot']);

        return [
            'id' => (int) $row['id'],
            'version' => (int) $row['version'],
            'room' => ['id' => $row['location_id'] === null ? null : (int) $row['location_id'], 'name' => (string) ($snapshot['room_name'] ?? ''), 'library' => (string) ($snapshot['library_name'] ?? '')],
            'checklist' => (string) ($snapshot['template_name'] ?? ''),
            'frequency' => ['key' => (string) $row['frequency'], 'label' => WatchRecurrence::FREQUENCIES[$row['frequency']] ?? (string) $row['frequency']],
            'start_date' => (string) $row['start_date'],
            'end_date' => $row['end_date'] === null ? null : (string) $row['end_date'],
            'assignee' => ['id' => (int) $row['assignee_id'], 'name' => (string) $row['assignee_name']],
            'active' => (bool) $row['active'] && $row['location_id'] !== null && ($row['end_date'] === null || (string) $row['end_date'] >= date('Y-m-d')),
        ];
    }

    public function templates(Context $context): JsonResponse
    {
        $page = Workspace::read($context->watch(), ['resource' => 'templates', 'q' => $context->request->query('q'), 'page' => $context->request->queryInt('page', 1, 1, 1000)], $context->staff()->id);

        return JsonResponse::ok(array_map([self::class, 'template'], $page['rows']), 200, ['pagination' => ['page' => $page['page'], 'pages' => $page['pages'], 'total' => $page['total']]]);
    }

    /** Running schedules, or with ?history=1 those that ended too. */
    public function index(Context $context): JsonResponse
    {
        $page = Workspace::read($context->watch(), [
            'resource' => 'schedules',
            'room' => $context->request->queryInt('room_id', 0, 0, PHP_INT_MAX),
            'history' => $context->request->query('history') === '1' ? '1' : '',
            'page' => $context->request->queryInt('page', 1, 1, 1000),
        ], $context->staff()->id);

        return JsonResponse::ok(array_map([self::class, 'schedule'], $page['rows']), 200, [
            'frequencies' => WatchRecurrence::FREQUENCIES,
            'pagination' => ['page' => $page['page'], 'pages' => $page['pages'], 'total' => $page['total']],
        ]);
    }

    /** The running schedules as one sheet, as PDF. */
    public function document(Context $context): Sendable
    {
        RoomController::throttlePdf($context);
        $document = $context->documents()->schedules($context->watch(), $context->staff()->name, $context->request->query('style'));
        $context->log('Jadwal pemeriksaan diunduh (' . $document['count'] . ' jadwal).', 'Print');

        return new BytesResponse($document['bytes'], 'application/pdf', $document['filename']);
    }

    /** One checklist (?id=), or those the running schedules use, as the form an examiner fills in, as PDF. */
    public function templatesDocument(Context $context): Sendable
    {
        RoomController::throttlePdf($context);
        $document = $context->documents()->checklists($context->watch(), $context->request->queryInt('id', 0, 0, PHP_INT_MAX), $context->staff()->name, $context->request->query('style'));
        $context->log('Checklist pemeriksaan diunduh (' . $document['count'] . ' checklist).', 'Print');

        return new BytesResponse($document['bytes'], 'application/pdf', $document['filename']);
    }

    /** The dates a schedule would have, before it is made. Changes nothing. */
    public function preview(Context $context): JsonResponse
    {
        $input = $context->input();
        $frequency = $input->required('frequency', 20);
        if (!isset(WatchRecurrence::FREQUENCIES[$frequency])) {
            throw ApiException::validation(['frequency' => ['Pilih salah satu: ' . implode(', ', array_keys(WatchRecurrence::FREQUENCIES)) . '.']]);
        }
        $preview = $context->watch()->preview($input->required('start_date', 10), $frequency, $input->string('end_date', 10));

        return JsonResponse::ok(['dates' => $preview['dates'], 'moved_for_holidays' => (object) $preview['moved']]);
    }

    /** A routine schedule for a room; its due inspections are formed right away. */
    public function store(Context $context): JsonResponse
    {
        $input = $context->input();
        $mapping = $input->get('mapping', []);
        if (!is_array($mapping)) {
            throw ApiException::validation(['mapping' => ['Pasangan butir dan barang tidak valid.']]);
        }
        $watch = $context->watch();
        $result = $watch->mutate('schedule', [
            'location_id' => $input->int('room_id'),
            'template_id' => $input->int('checklist_id'),
            'mapping' => $mapping,
            'frequency' => $input->required('frequency', 20),
            'start_date' => $input->required('start_date', 10),
            'end_date' => $input->string('end_date', 10),
            'assignee_id' => $input->int('assignee_id'),
        ], [], $context->staff()->id);
        $formed = $watch->mutate('sync', [], [], $context->staff()->id);
        $context->log('Jadwal pemeriksaan #' . $result['schedule_id'] . ' dibuat.', 'Create');

        return JsonResponse::ok(['schedule' => self::schedule($watch->row('schedules', (int) $result['schedule_id'])), 'inspections_formed' => (int) $formed['generated']], 201);
    }

    /** A new checklist. */
    public function storeTemplate(Context $context): JsonResponse
    {
        $input = $context->input();
        $items = $input->get('items', []);
        if (!is_array($items) || !array_is_list($items)) {
            throw ApiException::validation(['items' => ['Kirim daftar butir checklist.']]);
        }
        $watch = $context->watch();
        $result = $watch->mutate('template', ['name' => $input->required('name', 255), 'items' => $items], [], $context->staff()->id);
        $context->log('Checklist #' . $result['template_id'] . ' dibuat.', 'Create');

        return JsonResponse::ok(self::template($watch->row('templates', (int) $result['template_id'])), 201);
    }
}
