<?php

namespace SLiMS\Plugins\Inventory\Api;

use SLiMS\Plugins\Inventory\StockTake;
use SLiMS\Plugins\Inventory\Workspace;
use SlimsConnect\Http\JsonResponse;

final class HomeController
{
    /** Beranda: the running stock take and what is waiting for this librarian. */
    public function show(Context $context): JsonResponse
    {
        $staff = $context->staff();
        $watch = $context->watch();
        $inspections = Workspace::read($watch, ['resource' => 'tasks', 'kind' => 'inspections', 'owner' => 'mine'], $staff->id);
        $findings = Workspace::read($watch, ['resource' => 'tasks', 'kind' => 'findings', 'owner' => 'mine'], $staff->id);

        return JsonResponse::ok([
            'staff' => $staff->toArray(),
            'library_name' => Context::libraryName(),
            'stock_take' => (new StockTake($context->db))->active(),
            'counts' => TaskController::counts($context),
            'inspections' => array_map([Present::class, 'inspectionCard'], array_slice($inspections['rows'], 0, 3)),
            'findings' => array_map([Present::class, 'findingCard'], array_slice($findings['rows'], 0, 3)),
        ]);
    }
}
