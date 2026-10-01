<?php

namespace SLiMS\Plugins\Inventory\Api;

use SlimsConnect\License\LicenseService;

/**
 * What the library's Klaras Panel plan allows for InvenSync, read from SLiMS Connect's
 * cached licence verdict so a request almost never waits on the panel.
 */
final class Licence
{
    public const FEATURE = 'invensync';
    public const STAFF_LIMIT = 'invensync_staff';
    private const ENTITLED = ['active', 'trial', 'grace'];

    /** Tests replace the verdict. @var (\Closure(): array<string, mixed>)|null */
    public static ?\Closure $verdict = null;

    /** @return array<string, mixed> */
    public static function verdict(): array
    {
        return self::$verdict !== null ? (self::$verdict)() : (new LicenseService())->verdict();
    }

    /** Whether SLiMS Connect has been given an API key at all. */
    public static function linked(): bool
    {
        return (self::verdict()['state'] ?? 'unconfigured') !== 'unconfigured';
    }

    public static function allows(): bool
    {
        $verdict = self::verdict();

        return in_array($verdict['state'] ?? '', self::ENTITLED, true) && ($verdict['features'][self::FEATURE] ?? false) === true;
    }

    /** Staff who may be signed in at once, or null for no limit. */
    public static function staffLimit(): ?int
    {
        $limit = self::verdict()['limits'][self::STAFF_LIMIT] ?? null;

        return $limit === null ? null : max(0, (int) $limit);
    }
}
