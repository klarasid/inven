<?php

namespace SLiMS\Plugins\Inventory\Api;

use PDO;
use SLiMS\Plugins\Inventory\Documents;
use SLiMS\Plugins\Inventory\PhotoStorage;
use SLiMS\Plugins\Inventory\PublicLink;
use SLiMS\Plugins\Inventory\Supervision;
use SlimsConnect\Http\Request;

/** Everything a controller needs for one request. */
final class Context
{
    private ?Supervision $watch = null;
    private ?Input $input = null;

    public function __construct(
        public readonly Request $request,
        public readonly PDO $db,
        public readonly ?Staff $staff,
        public readonly PhotoStorage $storage,
    ) {}

    public function staff(): Staff
    {
        if ($this->staff === null) {
            throw Failure::unauthenticated('Masuk dulu untuk melanjutkan.');
        }

        return $this->staff;
    }

    public function input(): Input
    {
        return $this->input ??= Input::from($this->request);
    }

    public function watch(): Supervision
    {
        return $this->watch ??= new Supervision($this->db, $this->storage);
    }

    public function documents(): Documents
    {
        return new Documents($this->db, defined('SB') && defined('FLS') ? SB . FLS . DIRECTORY_SEPARATOR . 'cache' : sys_get_temp_dir());
    }

    /** The library's name as SLiMS shows it. */
    public static function libraryName(): string
    {
        return (string) ($GLOBALS['sysconf']['library_name'] ?? 'Perpustakaan');
    }

    /** The public address of this SLiMS, for links printed on labels. */
    public function siteUrl(): string
    {
        $scheme = $this->request->isSecure() ? 'https' : 'http';

        return $scheme . '://' . PublicLink::host() . rtrim(defined('SWB') ? SWB : '/', '/');
    }

    /** Records the change in SLiMS's system log, as the admin pages do. */
    public function log(string $message, string $action): void
    {
        if (!function_exists('writeLog')) {
            return;
        }
        try {
            writeLog('staff', (string) ($this->staff?->id ?? 0), 'Klaras InvenSync', $message, 'stock_take', $action);
        } catch (\Throwable $error) {
            error_log('[invensync] audit log: ' . $error->getMessage());
        }
    }
}
