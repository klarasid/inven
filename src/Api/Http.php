<?php

namespace SLiMS\Plugins\Inventory\Api;

use PDO;
use SLiMS\Plugins\Inventory\PhotoStorage;
use SlimsConnect\Http\ApiException;
use SlimsConnect\Http\JsonResponse;
use SlimsConnect\Http\Kernel;
use SlimsConnect\Http\Request;
use SlimsConnect\Http\Sendable;
use SlimsConnect\Support\Db;

/**
 * Runs one InvenSync route: the guard, the librarian's token and privileges, replay of
 * changes already applied, and the translation of the plugin's refusals into the API's codes.
 */
final class Http
{
    /** Stock opname batches and inspection drafts run larger than slims-connect's 16 KB. */
    private const MAX_BODY_BYTES = 262144;

    /** Tests hand in their own storage. */
    public static ?PhotoStorage $storage = null;

    public static function db(): PDO
    {
        $db = Db::pdo();
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $db;
    }

    /**
     * @param  callable(Context, mixed...): Sendable  $handler
     * @param  array<string, mixed>  $params  Route parameters, by name.
     * @param  array{public?: bool, write?: bool, route?: string}  $options
     */
    public static function run(callable $handler, array $params, array $options = []): void
    {
        $request = Request::capture(self::MAX_BODY_BYTES);
        Kernel::respond($request, static fn (): Sendable => self::dispatch($request, $handler, $params, $options));
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{public?: bool, write?: bool, route?: string}  $options
     */
    public static function dispatch(Request $request, callable $handler, array $params, array $options = []): Sendable
    {
        $db = self::db();
        Guard::check($request, $db);
        $staff = empty($options['public']) ? self::staff($request, $db) : null;
        $writes = $options['write'] ?? $request->method() !== 'GET';
        if ($staff !== null && !$staff->canRead) {
            throw Failure::forbidden('no_stock_take_access', 'Akun Anda tidak punya hak Stock Take. Minta administrator SLiMS menambahkannya.');
        }
        if ($staff !== null && $writes && !$staff->canWrite) {
            throw Failure::forbidden('read_only', 'Akun Anda hanya boleh melihat data. Minta administrator SLiMS memberi hak tulis Stock Take.');
        }
        $context = new Context($request, $db, $staff, self::$storage ?? new PhotoStorage());

        $claim = null;
        $key = $staff !== null && $writes ? Idempotency::key($request) : null;
        if ($key !== null) {
            $scope = ($options['route'] ?? $request->method()) . ' ' . json_encode(array_values($params));
            $claim = Idempotency::claim($db, $staff->id, $scope, $key);
            if ($claim instanceof JsonResponse) {
                return $claim;
            }
        }

        $response = null;
        try {
            $response = self::call($handler, $context, $params);
            return $response;
        } finally {
            $claim?->finish($response instanceof JsonResponse ? $response : null);
        }
    }

    /** @param array<string, mixed> $params */
    private static function call(callable $handler, Context $context, array $params): Sendable
    {
        $inTransaction = $context->db->inTransaction();
        try {
            return $handler($context, ...array_values($params));
        } catch (ApiException $error) {
            throw $error;
        } catch (\PDOException $error) {
            throw $error;
        } catch (\RuntimeException $error) {
            // slims-connect's Db wraps database errors in a RuntimeException of its own.
            if ($error->getPrevious() instanceof \PDOException) {
                throw $error;
            }
            if (!$inTransaction && $context->db->inTransaction()) {
                $context->db->rollBack();
            }
            throw Failure::fromRuntime($error);
        }
    }

    private static function staff(Request $request, PDO $db): Staff
    {
        $found = (new StaffTokens($db))->authenticate($request->bearerToken());
        if (($found['session']['kind'] ?? 'app') === AgentCodes::KIND && !AgentCodes::enabled($db)) {
            throw AgentCodes::disabled();
        }

        return Staff::fromUser($db, $found['user'], (int) $found['session']['id']);
    }
}
