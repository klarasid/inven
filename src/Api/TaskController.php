<?php

namespace SLiMS\Plugins\Inventory\Api;

use SLiMS\Plugins\Inventory\Workspace;
use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Sendable;

/**
 * Tugas: inspections to carry out, findings to follow up, and work waiting for this
 * librarian's verification. Every change goes through Supervision::mutate, the same
 * workflow the admin pages use, so its rules and audit trail apply unchanged.
 */
final class TaskController
{
    /** App tab → Workspace task kind. */
    private const KINDS = ['inspections' => 'inspections', 'findings' => 'findings', 'reviews' => 'review'];

    /** @return array{inspections: int, findings: int, reviews: int} */
    public static function counts(Context $context): array
    {
        $counts = Workspace::read($context->watch(), ['resource' => 'counts'], $context->staff()->id);

        return ['inspections' => $counts['inspections']['mine'], 'findings' => $counts['findings']['mine'], 'reviews' => $counts['review']['mine']];
    }

    public function index(Context $context): JsonResponse
    {
        $tab = $context->request->query('tab', 'inspections');
        if (!isset(self::KINDS[$tab])) {
            throw ApiException::validation(['tab' => ['Pilih inspections, findings, atau reviews.']]);
        }
        $page = $context->request->queryInt('page', 1, 1, 1000);
        $result = Workspace::read($context->watch(), ['resource' => 'tasks', 'kind' => self::KINDS[$tab], 'owner' => 'mine', 'page' => $page], $context->staff()->id);
        $present = $tab === 'inspections' ? [Present::class, 'inspectionCard'] : [Present::class, 'findingCard'];

        return JsonResponse::ok(array_map($present, $result['rows']), 200, [
            'counts' => self::counts($context),
            'pagination' => ['page' => $result['page'], 'pages' => $result['pages'], 'total' => $result['total']],
        ]);
    }

    public function inspection(Context $context, int $id): JsonResponse
    {
        return JsonResponse::ok(Present::inspection($context->watch()->document($id)));
    }

    /**
     * Saves the checklist as a draft, or finalizes it (submit_mode "final"), which turns every
     * "Perlu tindakan" result into a finding for its assignee.
     */
    public function saveInspection(Context $context, int $id): JsonResponse
    {
        $input = $context->input();
        $mode = $input->string('submit_mode', 10, 'draft');
        if (!in_array($mode, ['draft', 'final'], true)) {
            throw ApiException::validation(['submit_mode' => ['Pilih draft atau final.']]);
        }
        $results = $input->get('results', []);
        if (!is_array($results)) {
            throw ApiException::validation(['results' => ['Hasil pemeriksaan tidak valid.']]);
        }
        $performed = $input->string('performed_date', 10);
        $context->watch()->mutate('inspection', [
            'id' => $id,
            'version' => $input->int('version'),
            'submit_mode' => $mode,
            'performed_date' => $performed !== '' ? $performed : ($mode === 'final' ? date('Y-m-d') : ''),
            'notes' => $input->string('notes', 5000),
            'results' => $results,
        ], [], $context->staff()->id);
        $context->log('Pemeriksaan #' . $id . ($mode === 'final' ? ' difinalisasi.' : ' disimpan sebagai draf.'), $mode === 'final' ? 'Finalize' : 'Update');

        return JsonResponse::ok(Present::inspection($context->watch()->document($id)));
    }

    /** Adds (and optionally removes) evidence photos of one checklist row. */
    public function resultPhotos(Context $context, int $id, int $resultId): JsonResponse
    {
        $input = $context->input();
        $context->watch()->mutate('result_photos', [
            'inspection_id' => $id,
            'result_id' => $resultId,
            'version' => $input->int('version'),
            'remove' => (array) $input->get('remove', []),
        ], $input->files('photos'), $context->staff()->id);
        $context->log('Foto bukti pemeriksaan #' . $id . ' diperbarui.', 'Update');

        return JsonResponse::ok(Present::inspection($context->watch()->document($id)));
    }

    public function inspectionPhoto(Context $context, int $id, int $photoId): Sendable
    {
        $bytes = $context->watch()->photo($id, $photoId);
        if ($bytes === null) {
            throw Failure::notFound('Foto tidak ditemukan.');
        }

        return new BytesResponse($bytes, 'image/jpeg', 'foto-pemeriksaan-' . $photoId . '.jpg');
    }

    /**
     * Moves a finding on: start, note, draft or submit the work, or, for the reporter,
     * verify or reject it.
     */
    public function finding(Context $context, int $id): JsonResponse
    {
        $input = $context->input();
        $mode = $input->required('mode', 10);
        if (!in_array($mode, ['start', 'note', 'draft', 'submit', 'verify', 'reject'], true)) {
            throw ApiException::validation(['mode' => ['Tindakan tidak dikenal.']]);
        }
        $context->watch()->mutate('finding', [
            'id' => $id,
            'version' => $input->int('version'),
            'mode' => $mode,
            'notes' => $input->string('notes', 5000),
            'kind' => $input->string('kind', 20),
            'description' => $input->string('description', 5000),
            'performed_date' => $input->string('performed_date', 10, date('Y-m-d')),
            'cost' => $input->string('cost', 20),
            'remove' => (array) $input->get('remove', []),
        ], $input->files('photos'), $context->staff()->id);
        $context->log('Temuan #' . $id . ': ' . $mode . '.', 'Update');
        $finding = $context->watch()->row('findings', $id);

        return JsonResponse::ok([
            'id' => $id,
            'status' => (string) $finding['status'],
            'version' => (int) $finding['version'],
            'inspection' => Present::inspection($context->watch()->document((int) $finding['inspection_id'])),
        ]);
    }

    /**
     * Lapor kerusakan: a finding assigned to the chosen handler at once. With "fixed", the
     * reporter handled it personally and the work is recorded and closed in the same step.
     */
    public function report(Context $context): JsonResponse
    {
        $input = $context->input();
        $fixed = $input->bool('fixed');
        $problem = $input->required('problem', 5000);
        $findingId = $context->watch()->mutate('report', [
            'location_id' => $input->int('room_id'),
            'item_id' => $input->int('item_id'),
            'object' => $input->string('object', 255),
            'group' => $input->string('group', 30),
            'problem' => $problem,
            'handler_id' => $fixed ? $context->staff()->id : $input->int('handler_id'),
            'priority' => $input->string('priority', 10, 'medium'),
            'deadline' => $input->string('deadline', 10, date('Y-m-d', strtotime('+7 days'))),
            'fixed' => $fixed ? '1' : '0',
            'kind' => $input->string('kind', 20, 'repair'),
            'description' => $input->string('description', 5000) ?: $problem,
            'performed_date' => $input->string('performed_date', 10, date('Y-m-d')),
            'cost' => $input->string('cost', 20),
        ], $input->files('photos'), $context->staff()->id, $input->files('fix_photos'))['record'];
        $context->log('Laporan kerusakan dibuat, temuan #' . $findingId . ($fixed ? ', langsung ditangani.' : '.'), 'Create');
        $finding = $context->watch()->row('findings', (int) $findingId);

        return JsonResponse::ok(['id' => (int) $findingId, 'status' => (string) $finding['status'], 'handler' => ['id' => (int) $finding['assignee_id'], 'name' => (string) $finding['assignee_name']]], 201);
    }
}
