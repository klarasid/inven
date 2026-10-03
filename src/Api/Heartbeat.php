<?php

namespace SLiMS\Plugins\Inventory\Api;

/**
 * Tells Klaras Panel right away that a feature was switched on or off, so the library does not
 * have to wait for SLiMS Connect's next scheduled heartbeat. Best effort: it never throws, and
 * the heartbeat still catches up on its own schedule if this cannot reach SLiMS Connect.
 */
final class Heartbeat
{
    /** Tests replace the call: fn(): bool */
    public static $transport = null;

    private const CANDIDATES = [
        ['SlimsConnect\\Heartbeat', 'sendNow'],
        ['SlimsConnect\\Heartbeat', 'send'],
        ['SlimsConnect\\Panel\\Heartbeat', 'sendNow'],
        ['SlimsConnect\\Panel\\Heartbeat', 'send'],
        ['SlimsConnect\\Support\\Heartbeat', 'sendNow'],
        ['SlimsConnect\\Support\\Heartbeat', 'send'],
    ];

    public static function now(): bool
    {
        try {
            if (self::$transport !== null) {
                return (bool) (self::$transport)();
            }
            foreach (self::CANDIDATES as [$class, $method]) {
                if (class_exists($class) && is_callable([$class, $method])) {
                    $result = $class::$method();
                    return $result !== false;
                }
            }
        } catch (\Throwable $error) {
            error_log('[invensync] heartbeat: ' . $error->getMessage());
        }
        return false;
    }
}
