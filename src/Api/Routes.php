<?php

namespace SLiMS\Plugins\Inventory\Api;

use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\Kernel;

/**
 * The Klaras InvenSync API on SLiMS's API router, reached as index.php?p=api/invensync/v1/….
 * Paths match the app; add routes freely, change none.
 */
final class Routes
{
    public const PREFIX = '/invensync/v1';

    /** @param object $router SLiMS's AltoRouter. */
    public static function register(object $router): void
    {
        $map = static function (string $method, string $path, array $handler, array $options = []) use ($router): void {
            $options['route'] = $method . ' ' . $path;
            $router->map($method, self::PREFIX . $path, static function (...$params) use ($handler, $options): void {
                [$class, $action] = $handler;
                Http::run([new $class(), $action], $params, $options);
            });
        };

        $map('GET', '', [AuthController::class, 'discovery'], ['public' => true]);
        $map('POST', '/auth/token', [AuthController::class, 'issue'], ['public' => true]);
        $map('DELETE', '/auth/token', [AuthController::class, 'revoke'], ['write' => false]);
        $map('GET', '/me', [AuthController::class, 'me']);
        $map('GET', '/staff', [AuthController::class, 'staffList']);
        $map('GET', '/home', [HomeController::class, 'show']);

        $map('GET', '/rooms', [RoomController::class, 'index']);
        $map('GET', '/rooms/[i:id]/items', [RoomController::class, 'items']);
        $map('GET', '/rooms/[i:id]/documents/kir', [RoomController::class, 'kir']);
        $map('GET', '/rooms/[i:id]/documents/labels', [RoomController::class, 'labels']);

        $map('GET', '/items/lookup', [ItemController::class, 'lookup']);
        $map('POST', '/items/code', [ItemController::class, 'reserveCode']);
        $map('POST', '/items', [ItemController::class, 'store']);
        $map('GET', '/items/[i:id]', [ItemController::class, 'show']);
        $map('PATCH', '/items/[i:id]', [ItemController::class, 'update']);
        $map('POST', '/items/[i:id]/photos', [ItemController::class, 'addPhotos']);
        $map('GET', '/items/[i:id]/photos/[i:photo]', [ItemController::class, 'photo']);
        $map('DELETE', '/items/[i:id]/photos/[i:photo]', [ItemController::class, 'deletePhoto']);
        $map('GET', '/items/[i:id]/documents/label', [ItemController::class, 'label']);

        $map('GET', '/tasks', [TaskController::class, 'index']);
        $map('GET', '/inspections/[i:id]', [TaskController::class, 'inspection']);
        $map('PUT', '/inspections/[i:id]', [TaskController::class, 'saveInspection']);
        $map('POST', '/inspections/[i:id]/results/[i:result]/photos', [TaskController::class, 'resultPhotos']);
        $map('GET', '/inspections/[i:id]/photos/[i:photo]', [TaskController::class, 'inspectionPhoto']);
        $map('POST', '/findings/[i:id]', [TaskController::class, 'finding']);
        $map('POST', '/reports', [TaskController::class, 'report']);

        $map('GET', '/stocktake/sessions', [StockTakeController::class, 'index']);
        $map('GET', '/stocktake/sessions/[i:id]', [StockTakeController::class, 'show']);
        $map('POST', '/stocktake/sessions/[i:id]/scans', [StockTakeController::class, 'scan']);
        $map('GET', '/stocktake/sessions/[i:id]/items', [StockTakeController::class, 'items']);
        $map('GET', '/stocktake/sessions/[i:id]/codes', [StockTakeController::class, 'codes']);
        $map('GET', '/stocktake/sessions/[i:id]/document', [StockTakeController::class, 'document']);

        $map('GET', '/reports/summary', [ReportController::class, 'summary']);
        $map('GET', '/reports/document', [ReportController::class, 'document']);

        // Anything else under the prefix is a JSON 404, not SLiMS's HTML page.
        $router->map('GET|POST|PUT|PATCH|DELETE', self::PREFIX . '/[**:rest]', static fn () => Kernel::handle(
            static fn () => throw new ApiException('not_found', 'Tidak ditemukan.', 404)
        ));
    }
}
