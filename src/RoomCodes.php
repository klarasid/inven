<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

/**
 * Room codes (the "No. kode lokasi" printed on the KIR) follow {library code}-RUANG-{number}, e.g.
 * 00-RUANG-001, numbered per library, in step with item codes ({library code}-INV-{number}).
 */
final class RoomCodes
{
    public const STEM = '-RUANG-';

    public static function format(string $library, int $number): string
    {
        return $library . self::STEM . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    /** Next free code for a library: one above the highest number already used by its rooms. */
    public static function next(PDO $db, string $library): string
    {
        $library = trim($library);
        if ($library === '') throw new RuntimeException('Pilih lokasi perpustakaan sebelum membuat kode ruangan.');
        $known = $db->prepare('SELECT 1 FROM mst_location WHERE location_id = ?');
        $known->execute([$library]);
        if ($known->fetchColumn() === false) throw new RuntimeException('Lokasi perpustakaan tidak ditemukan.');
        $stem = $library . self::STEM;
        $codes = $db->prepare('SELECT location_code FROM inventory_locations WHERE LEFT(location_code, CHAR_LENGTH(?)) = ?');
        $codes->execute([$stem, $stem]);
        $highest = 0;
        while (($code = $codes->fetchColumn()) !== false) {
            if (preg_match('/\A' . preg_quote($stem, '/') . '([0-9]+)\z/', (string) $code, $m)) $highest = max($highest, (int) $m[1]);
        }
        return self::format($library, $highest + 1);
    }

    /**
     * Codes that bring every room in line with the pattern: rooms numbered 1..n per library in id order.
     * @return array<int, array{room:string,library:string,old:?string,new:string}> keyed by room id
     */
    public static function plan(PDO $db): array
    {
        $rows = $db->query('SELECT id, room_name, slims_location_id, location_code FROM inventory_locations ORDER BY slims_location_id, id')->fetchAll(PDO::FETCH_ASSOC);
        $plan = [];
        $counter = [];
        foreach ($rows as $row) {
            $library = trim((string) $row['slims_location_id']);
            if ($library === '') continue; // rooms without a library keep their code until one is chosen
            $counter[$library] = ($counter[$library] ?? 0) + 1;
            $plan[(int) $row['id']] = ['room' => $row['room_name'], 'library' => $library, 'old' => $row['location_code'], 'new' => self::format($library, $counter[$library])];
        }
        return $plan;
    }
}
