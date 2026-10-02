<?php

namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

require_once __DIR__ . '/Sarpras.php';

/**
 * Saving an inventory item, shared by the admin page and the Klaras InvenSync API
 * so both apply the same rules: validation, the item code allocator, and photos.
 */
final class Inventory
{
    public const CONDITIONS = ['B' => 'Baik', 'KB' => 'Kurang baik', 'RB' => 'Rusak berat'];

    private static function text(array $input, string $key, string $default = ''): string
    {
        $value = $input[$key] ?? $default;
        if (!is_scalar($value) && $value !== null) {
            throw new RuntimeException('Data barang tidak valid.');
        }
        return trim((string) ($value ?? $default));
    }

    /**
     * Validates the item fields and returns the row values, without id or timestamps of creation.
     *
     * @return array<string, mixed>
     */
    public static function values(array $input): array
    {
        $locationId = (int) ($input['location_id'] ?? 0);
        $itemName = self::text($input, 'item_name');
        if ($locationId < 1 || $itemName === '') {
            throw new RuntimeException('Lokasi dan nama barang wajib diisi.');
        }
        foreach (['item_name' => ['Nama barang', 255], 'brand_model' => ['Merk/model', 255], 'serial_number' => ['No. seri pabrik', 255], 'item_size' => ['Ukuran', 150], 'material' => ['Bahan', 150], 'quantity_register' => ['Jumlah/register', 150]] as $key => [$label, $max]) {
            if (mb_strlen(self::text($input, $key)) > $max) {
                throw new RuntimeException("$label maksimal $max karakter.");
            }
        }
        $condition = self::text($input, 'item_condition', 'B');
        if (!isset(self::CONDITIONS[$condition])) {
            throw new RuntimeException('Kondisi barang tidak valid.');
        }
        $yearText = self::text($input, 'acquisition_year');
        $year = $yearText === '' ? null : (int) $yearText;
        if ($year !== null && ($year < 1000 || $year > ((int) date('Y') + 1))) {
            throw new RuntimeException('Tahun pembuatan/pembelian tidak valid.');
        }
        $priceText = self::text($input, 'acquisition_price', '0');
        $priceText = $priceText === '' ? '0' : $priceText;
        if (!is_numeric($priceText) || (float) $priceText < 0 || (float) $priceText > 9999999999999999.99) {
            throw new RuntimeException('Harga perolehan tidak valid.');
        }
        if (strlen(self::text($input, 'notes')) > 5000) {
            throw new RuntimeException('Keterangan maksimal 5.000 karakter.');
        }

        // Categories and type feed Rekap Sarpras; callers that do not send them pass the stored row (see ItemController::fields).
        return [
            'location_id' => $locationId,
            'item_name' => $itemName,
            'brand_model' => self::text($input, 'brand_model'),
            'serial_number' => self::text($input, 'serial_number'),
            'item_size' => self::text($input, 'item_size'),
            'material' => self::text($input, 'material'),
            'acquisition_year' => $year,
            'item_code' => self::text($input, 'item_code'),
            'quantity_register' => self::text($input, 'quantity_register'),
            'acquisition_price' => (float) $priceText,
            'item_condition' => $condition,
            'notes' => self::text($input, 'notes'),
            'category' => Sarpras::categories($input['category'] ?? null),
            'item_type' => Sarpras::type($input['item_type'] ?? ''),
        ];
    }

    /**
     * Creates ($id = 0) or updates an item, with any new photos, in one transaction.
     *
     * $codeOwner identifies the form that reserved the item code (see ItemCodes::owner), or '' when none did.
     * Photo files written before a failure are removed; files of photos replaced are removed after commit.
     *
     * @param  list<string>  $photos  Normalised JPEG bytes (ItemPhotos::uploads).
     * @return int The item id.
     */
    public static function saveItem(PDO $db, PhotoStorage $storage, array $input, int $id, ?int $uid, string $codeOwner, array $photos = []): int
    {
        $values = self::values($input);
        $room = $db->prepare('SELECT 1 FROM inventory_locations WHERE id = ?');
        $room->execute([$values['location_id']]);
        if (!$room->fetchColumn()) {
            throw new RuntimeException('Ruangan tidak ditemukan.');
        }
        $now = date('Y-m-d H:i:s');
        $values['updated_at'] = $now;
        $created = [];
        $removed = [];
        $db->beginTransaction();
        try {
            ItemCodes::lock($db);
            $oldCode = null;
            if ($id > 0) {
                $lock = $db->prepare('SELECT item_code FROM inventory_items WHERE id = ? FOR UPDATE');
                $lock->execute([$id]);
                if (($oldCode = $lock->fetchColumn()) === false) {
                    throw new RuntimeException('Barang tidak ditemukan.');
                }
            }
            ItemCodes::validateAndConsume($db, $values['item_code'], $oldCode, $codeOwner);
            if ($id > 0) {
                $values['id'] = $id;
                $db->prepare(
                    'UPDATE inventory_items SET location_id=:location_id, item_name=:item_name, brand_model=:brand_model,
                     serial_number=:serial_number, item_size=:item_size, material=:material,
                     acquisition_year=:acquisition_year, item_code=:item_code, quantity_register=:quantity_register,
                     acquisition_price=:acquisition_price, item_condition=:item_condition, notes=:notes,
                     category=:category, item_type=:item_type, updated_at=:updated_at WHERE id=:id'
                )->execute($values);
            } else {
                $values['created_by'] = $uid;
                $values['created_at'] = $now;
                $db->prepare(
                    'INSERT INTO inventory_items
                     (location_id, item_name, brand_model, serial_number, item_size, material, acquisition_year,
                      item_code, quantity_register, acquisition_price, item_condition, notes, category, item_type, created_by, created_at, updated_at)
                     VALUES (:location_id, :item_name, :brand_model, :serial_number, :item_size, :material, :acquisition_year,
                      :item_code, :quantity_register, :acquisition_price, :item_condition, :notes, :category, :item_type, :created_by, :created_at, :updated_at)'
                )->execute($values);
                $id = (int) $db->lastInsertId();
            }
            ItemPhotos::apply($db, $id, $photos, [], $storage, $created, $removed);
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $storage->cleanup($created);
            throw $error;
        }
        $storage->cleanup($removed);
        return $id;
    }
}
