<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

require_once __DIR__ . '/PhotoStorage.php';
require_once __DIR__ . '/RoomPlans.php';

/**
 * Dokumen jaringan: the files that show a library location's internet, as an accreditation asks
 * for them: speed test results (of a room, when one is named), the ISP's service, and the Wi-Fi
 * coverage map. Uploaded pictures or PDFs, kept as uploaded in a folder denied to browsers like
 * the plugin's photos, and served only through Gedung & Jaringan and the API.
 *
 * $library is a SLiMS location code, or '' while the library is one unit (Sarpras::locations).
 */
final class NetworkDocuments
{
    public const KINDS = ['speedtest' => 'Hasil uji kecepatan', 'isp' => 'Layanan ISP', 'wifi' => 'Peta jangkauan Wi-Fi'];
    public const MAX_BYTES = RoomPlans::MAX_BYTES;
    public const MAX_PER_LIBRARY = 100;
    private const FILE = '/\Ajaringan-[0-9a-f]{32}\.(pdf|jpg|png|webp)\z/';
    private const SELECT = 'SELECT d.id, d.library_code, d.kind, d.title, d.mime, d.created_at, l.id AS room_id, l.room_name FROM inventory_network_documents d LEFT JOIN inventory_locations l ON l.id = d.location_id';

    public static function directory(): string
    {
        return rtrim(SB, '/\\') . '/images/inventaris-barang/jaringan';
    }

    /** @return array{id:int,library:string,kind:string,title:string,mime:string,created_at:string,room:?array{id:int,name:string}} */
    private static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'library' => (string) $row['library_code'], 'kind' => (string) $row['kind'], 'title' => (string) $row['title'],
            'mime' => (string) $row['mime'], 'created_at' => (string) $row['created_at'],
            // A room deleted since leaves its documents with the location.
            'room' => $row['room_id'] === null ? null : ['id' => (int) $row['room_id'], 'name' => (string) $row['room_name']],
        ];
    }

    /** One location's documents, in the order of KINDS. @return list<array<string,mixed>> */
    public static function of(PDO $db, string $library): array
    {
        $query = $db->prepare(self::SELECT . ' WHERE d.library_code = ? ORDER BY d.id');
        $query->execute([$library]);
        $rows = array_map([self::class, 'present'], $query->fetchAll(PDO::FETCH_ASSOC));
        $order = array_flip(array_keys(self::KINDS));
        usort($rows, static fn(array $a, array $b): int => [$order[$a['kind']] ?? 99, $a['id']] <=> [$order[$b['kind']] ?? 99, $b['id']]);
        return $rows;
    }

    /** Stores an uploaded document for a location. @return int the document's id */
    public static function upload(PDO $db, string $library, array $file, string $kind, string $title, int $roomId, ?int $uid, string $now): int
    {
        if (!isset(self::KINDS[$kind])) throw new RuntimeException('Pilih jenis dokumen jaringan.');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Pilih berkas dokumen (PDF, JPEG, PNG, atau WebP) maksimal 5 MB.');
        }
        if ($roomId > 0) {
            $room = $db->prepare('SELECT slims_location_id FROM inventory_locations WHERE id = ?');
            $room->execute([$roomId]);
            $code = $room->fetchColumn();
            if ($code === false || ($library !== '' && (string) $code !== $library)) throw new RuntimeException('Ruangan tidak ditemukan di lokasi ini.');
        }
        if (count(self::of($db, $library)) >= self::MAX_PER_LIBRARY) {
            throw new RuntimeException('Satu lokasi memuat paling banyak ' . self::MAX_PER_LIBRARY . ' dokumen jaringan. Hapus dokumen yang tidak dipakai.');
        }
        $mime = RoomPlans::mime($file['tmp_name'], 'dokumen jaringan');
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title === '') $title = trim((string) preg_replace('/\s+/u', ' ', pathinfo((string) ($file['name'] ?? ''), PATHINFO_FILENAME)));
        if ($title === '') $title = self::KINDS[$kind];
        if (mb_strlen($title) > 150) throw new RuntimeException('Judul dokumen maksimal 150 karakter.');
        try {
            PhotoStorage::protect(self::directory());
        } catch (RuntimeException $error) {
            throw new RuntimeException('Folder dokumen jaringan tidak dapat dibuat atau dilindungi.');
        }
        $filename = 'jaringan-' . bin2hex(random_bytes(16)) . '.' . RoomPlans::TYPES[$mime];
        $path = self::directory() . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $path)) throw new RuntimeException('Berkas dokumen tidak dapat disimpan.');
        @chmod($path, 0600);
        try {
            $db->prepare('INSERT INTO inventory_network_documents (library_code, kind, location_id, title, filename, mime, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$library, $kind, $roomId > 0 ? $roomId : null, $title, $filename, $mime, $uid, $now]);
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }
        return (int) $db->lastInsertId();
    }

    public static function delete(PDO $db, string $library, int $id): void
    {
        $query = $db->prepare('SELECT filename FROM inventory_network_documents WHERE id = ? AND library_code = ?');
        $query->execute([$id, $library]);
        $filename = $query->fetchColumn();
        if ($filename === false) throw new RuntimeException('Dokumen tidak ditemukan di lokasi ini.');
        $db->prepare('DELETE FROM inventory_network_documents WHERE id = ? AND library_code = ?')->execute([$id, $library]);
        if (preg_match(self::FILE, (string) $filename)) @unlink(self::directory() . '/' . $filename);
    }

    /** @return array{path:string,mime:string,name:string}|null the file to send, with a download name safe for a header */
    public static function file(PDO $db, int $id): ?array
    {
        $query = $db->prepare('SELECT title, filename, mime FROM inventory_network_documents WHERE id = ?');
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || !preg_match(self::FILE, (string) $row['filename']) || !isset(RoomPlans::TYPES[$row['mime']])) return null;
        $path = self::directory() . '/' . $row['filename'];
        if (is_link($path) || !is_file($path)) return null;
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $row['title']), '-')) ?: 'dokumen-jaringan';
        return ['path' => $path, 'mime' => (string) $row['mime'], 'name' => substr($slug, 0, 80) . '.' . RoomPlans::TYPES[$row['mime']]];
    }
}
