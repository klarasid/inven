<?php

namespace SLiMS\Plugins\Inventory\Api;

use SlimsConnect\Http\ApiException;

/**
 * The refusals the InvenSync API answers with. The codes are a contract with the app:
 * add new ones freely, never rename one. Messages are shown to librarians as they are.
 */
final class Failure
{
    public static function unauthenticated(string $message = 'Sesi Anda berakhir. Masuk lagi untuk melanjutkan.'): ApiException
    {
        return new ApiException('unauthenticated', $message, 401);
    }

    public static function forbidden(string $code, string $message): ApiException
    {
        return new ApiException($code, $message, 403);
    }

    public static function notFound(string $message = 'Data tidak ditemukan.'): ApiException
    {
        return new ApiException('not_found', $message, 404);
    }

    public static function rejected(string $message): ApiException
    {
        return new ApiException('rejected', $message, 422);
    }

    /**
     * The plugin's services refuse with RuntimeException and a message written for librarians.
     * Keep that message, and give the app a code it can branch on.
     */
    public static function fromRuntime(\RuntimeException $error): ApiException
    {
        $message = $error->getMessage();
        if (str_contains($message, 'berubah pada sesi lain')) {
            return new ApiException('stale_version', 'Data ini sudah diubah di perangkat lain. Muat ulang, lalu simpan lagi.', 409);
        }
        if (str_contains($message, 'sudah ditutup')) {
            return new ApiException('session_closed', $message, 409);
        }
        if (str_contains($message, 'tidak ditemukan') || str_contains($message, 'Tidak ditemukan')) {
            return new ApiException('not_found', $message, 404);
        }

        return self::rejected($message);
    }
}
