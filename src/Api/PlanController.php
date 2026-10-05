<?php

namespace SLiMS\Plugins\Inventory\Api;

use SLiMS\Plugins\Inventory\RoomPlans;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Sendable;

/**
 * Floor plans of the rooms (the Denah tab of a room's page): which rooms have one, and the
 * uploaded file itself. Read only; plans are uploaded on the room's page.
 */
final class PlanController
{
    /** Every plan with its room, so one call shows which rooms have a plan and which have none. */
    public function index(Context $context): JsonResponse
    {
        return JsonResponse::ok(array_map(static fn (array $plan): array => [
            'id' => $plan['id'],
            'title' => $plan['title'],
            'type' => RoomPlans::TYPES[$plan['mime']] ?? '',
            'created_at' => $plan['created_at'],
            'room' => ['id' => $plan['room_id'], 'name' => $plan['room_name']],
        ], RoomPlans::all($context->db)));
    }

    /** The plan as it was uploaded: a PDF or a picture. */
    public function show(Context $context, int $id): Sendable
    {
        $file = RoomPlans::file($context->db, $id);
        $bytes = $file === null ? false : @file_get_contents($file['path']);
        if ($file === null || $bytes === false) {
            throw Failure::notFound('Denah tidak ditemukan.');
        }

        return new BytesResponse($bytes, $file['mime'], $file['name']);
    }
}
