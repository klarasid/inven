<?php

namespace SLiMS\Plugins\Inventory\Api;

use SLiMS\Plugins\Inventory\Supervision;

/**
 * The JSON shapes the app reads. Field names here are a contract with Klaras InvenSync:
 * add freely, rename none.
 */
final class Present
{
    /** @return array<string, mixed> */
    public static function room(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['room_name'],
            'code' => (string) ($row['location_code'] ?? ''),
            'library' => [
                'code' => (string) ($row['slims_location_id'] ?? ''),
                'name' => (string) ($row['library_name'] ?? ''),
            ],
            'item_count' => (int) ($row['item_count'] ?? 0),
            'conditions' => [
                'B' => (int) ($row['count_b'] ?? 0),
                'KB' => (int) ($row['count_kb'] ?? 0),
                'RB' => (int) ($row['count_rb'] ?? 0),
            ],
        ];
    }

    /**
     * @param  list<int>  $photoIds
     * @return array<string, mixed>
     */
    public static function item(array $row, array $photoIds = []): array
    {
        $price = (float) $row['acquisition_price'];

        return [
            'id' => (int) $row['id'],
            'room_id' => (int) $row['location_id'],
            'name' => (string) $row['item_name'],
            'brand' => (string) $row['brand_model'],
            'serial' => (string) $row['serial_number'],
            'size' => (string) $row['item_size'],
            'material' => (string) $row['material'],
            'year' => $row['acquisition_year'] === null ? null : (int) $row['acquisition_year'],
            'code' => (string) $row['item_code'],
            'quantity' => (string) $row['quantity_register'],
            'price' => floor($price) === $price ? (int) $price : $price,
            'condition' => (string) $row['item_condition'],
            'notes' => (string) ($row['notes'] ?? ''),
            'photo_ids' => array_map('intval', $photoIds),
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @return array<string, mixed> */
    public static function inspectionCard(array $row): array
    {
        $snapshot = is_array($row['snapshot']) ? $row['snapshot'] : Supervision::decode((string) $row['snapshot']);

        return [
            'id' => (int) $row['id'],
            'kind' => (string) $row['kind'],
            'title' => self::inspectionTitle((string) $row['kind'], $snapshot),
            'room' => ['id' => (int) ($snapshot['room_id'] ?? 0), 'name' => (string) ($snapshot['room_name'] ?? '')],
            'due_date' => (string) $row['due_date'],
            'status' => (string) $row['status'],
            'late' => $row['status'] !== 'final' && $row['due_date'] < date('Y-m-d'),
            'version' => (int) $row['version'],
        ];
    }

    private static function inspectionTitle(string $kind, array $snapshot): string
    {
        $template = (string) ($snapshot['template_name'] ?? '');

        return match ($kind) {
            'routine' => $template !== '' ? $template : 'Pemeriksaan rutin',
            'historical' => 'Riwayat impor',
            default => $template !== '' ? $template : 'Pemeriksaan insidental',
        };
    }

    /** @return array<string, mixed> */
    public static function findingCard(array $row): array
    {
        $result = is_array($row['result_snapshot']) ? $row['result_snapshot'] : Supervision::decode((string) $row['result_snapshot']);
        $inspection = is_array($row['snapshot']) ? $row['snapshot'] : Supervision::decode((string) $row['snapshot']);

        return [
            'id' => (int) $row['id'],
            'inspection_id' => (int) $row['inspection_id'],
            'object' => (string) ($result['object'] ?? ''),
            'item' => empty($result['item_id']) ? null : ['id' => (int) $result['item_id'], 'name' => (string) $result['item_name'], 'code' => (string) $result['item_code']],
            'room' => ['id' => (int) ($inspection['room_id'] ?? 0), 'name' => (string) ($inspection['room_name'] ?? '')],
            'notes' => (string) ($row['notes'] ?? ''),
            'status' => (string) $row['status'],
            'priority' => (string) $row['priority'],
            'deadline' => (string) $row['deadline'],
            'late' => $row['status'] !== 'closed' && $row['deadline'] < date('Y-m-d'),
            'assignee' => ['id' => (int) $row['assignee_id'], 'name' => (string) $row['assignee_name']],
            'reporter' => ['id' => (int) ($row['reporter_id'] ?? 0), 'name' => (string) ($row['reporter_name'] ?? '')],
            'version' => (int) $row['version'],
        ];
    }

    /**
     * An inspection as the checklist screen shows it.
     *
     * @param  array<string, mixed>  $document  Supervision::document()
     * @return array<string, mixed>
     */
    public static function inspection(array $document): array
    {
        $inspection = $document['inspection'];
        $snapshot = $document['snapshot'];
        $photos = [];
        foreach ($document['photos'] as $photo) {
            if ($photo['result_id'] !== null) {
                $photos[(int) $photo['result_id']][] = (int) $photo['id'];
            }
        }
        $actionPhotos = [];
        foreach ($document['photos'] as $photo) {
            if ($photo['action_id'] !== null) {
                $actionPhotos[(int) $photo['action_id']][] = (int) $photo['id'];
            }
        }
        // The work recorded on each finding, latest first: a draft still being
        // written, or the last one sent for verification.
        $work = [];
        foreach (array_reverse($document['actions']) as $action) {
            $work[(int) $action['finding_id']] ??= [
                'id' => (int) $action['id'],
                'kind' => (string) $action['kind'],
                'description' => (string) $action['description'],
                'performed_date' => $action['performed_date'],
                'by' => (string) $action['actor_name'],
                'submitted' => $action['submitted_at'] !== null,
                'photo_ids' => $actionPhotos[(int) $action['id']] ?? [],
            ];
        }
        $findings = [];
        foreach ($document['findings'] as $finding) {
            $findings[(int) $finding['result_id']] = [
                'id' => (int) $finding['id'],
                'status' => (string) $finding['status'],
                'version' => (int) $finding['version'],
                'work' => $work[(int) $finding['id']] ?? null,
            ];
        }
        $results = array_map(static function (array $result) use ($photos, $findings): array {
            $item = is_array($result['snapshot']) ? $result['snapshot'] : Supervision::decode((string) $result['snapshot']);

            return [
                'id' => (int) $result['id'],
                'position' => (int) $result['position'],
                'group' => (string) ($item['group'] ?? ''),
                'object' => (string) ($item['object'] ?? ''),
                'instruction' => (string) ($item['instruction'] ?? ''),
                'item' => empty($item['item_id']) ? null : ['id' => (int) $item['item_id'], 'name' => (string) $item['item_name'], 'code' => (string) $item['item_code']],
                'outcome' => (string) $result['outcome'],
                'notes' => (string) $result['notes'],
                'assignee' => $result['assignee_id'] === null ? null : ['id' => (int) $result['assignee_id'], 'name' => (string) $result['assignee_name']],
                'priority' => $result['priority'],
                'deadline' => $result['deadline'],
                'photo_ids' => $photos[(int) $result['id']] ?? [],
                'finding' => $findings[(int) $result['id']] ?? null,
            ];
        }, $document['results']);

        return [
            ...self::inspectionCard($inspection),
            'template_name' => (string) ($snapshot['template_name'] ?? ''),
            'assignee' => isset($snapshot['assignee']['id']) ? ['id' => (int) $snapshot['assignee']['id'], 'name' => (string) $snapshot['assignee']['name']] : null,
            'reason' => (string) $inspection['reason'],
            'notes' => (string) $inspection['notes'],
            'performed_date' => $inspection['performed_date'],
            'examiner' => $inspection['examiner_id'] === null ? null : ['id' => (int) $inspection['examiner_id'], 'name' => (string) $inspection['examiner_name']],
            'results' => $results,
        ];
    }
}
