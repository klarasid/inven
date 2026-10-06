<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

require_once __DIR__ . '/PhotoStorage.php';
require_once __DIR__ . '/RoomPlans.php';

/**
 * Dokumen pendukung: the files a library location keeps as evidence of its facilities, as an
 * accreditation asks for them, each of a kind under a topic: its internet (speed test results,
 * the ISP's service, the Wi-Fi coverage map) and its security and safety (a device's certificate
 * or function test, the emergency procedure, the record of a training or drill). Uploaded
 * pictures or PDFs, kept as uploaded in a folder denied to browsers like the plugin's photos,
 * and served only through Gedung & Jaringan and the API. They are evidence: no aspect's level
 * counts them, though a speed test is what the recap's "Bukti pengukuran diunggah" looks for.
 *
 * $library is a SLiMS location code, or '' while the library is one unit (Sarpras::locations).
 */
final class SupportDocuments
{
    public const TOPICS = ['jaringan' => 'Jaringan internet', 'keamanan' => 'Keamanan dan keselamatan'];
    /** `room`: whether a document of the kind may be of one room rather than the whole location. */
    public const KINDS = [
        'speedtest' => ['label' => 'Hasil uji kecepatan', 'topic' => 'jaringan', 'room' => true],
        'isp' => ['label' => 'Layanan ISP', 'topic' => 'jaringan', 'room' => false],
        'wifi' => ['label' => 'Peta jangkauan Wi-Fi', 'topic' => 'jaringan', 'room' => true],
        'uji_fungsi' => ['label' => 'Sertifikat / uji fungsi perangkat', 'topic' => 'keamanan', 'room' => true],
        'pos' => ['label' => 'POS tanggap darurat dan sistem keamanan', 'topic' => 'keamanan', 'room' => false],
        'pelatihan' => ['label' => 'Berita acara pelatihan / simulasi keselamatan', 'topic' => 'keamanan', 'room' => false],
    ];
    public const MAX_BYTES = RoomPlans::MAX_BYTES;
    public const MAX_PER_LIBRARY = 100;
    /** "jaringan-" names the files uploaded while these were only network documents. */
    private const FILE = '/\A(dokumen|jaringan)-[0-9a-f]{32}\.(pdf|jpg|png|webp)\z/';
    private const SELECT = 'SELECT d.id, d.library_code, d.kind, d.title, d.mime, d.created_at, l.id AS room_id, l.room_name FROM inventory_support_documents d LEFT JOIN inventory_locations l ON l.id = d.location_id';

    public static function directory(): string
    {
        return rtrim(SB, '/\\') . '/images/inventaris-barang/dokumen';
    }

    /** Where network documents were kept before they became one kind of supporting document. */
    public static function formerDirectory(): string
    {
        return rtrim(SB, '/\\') . '/images/inventaris-barang/jaringan';
    }

    /** The stored file's path: in the folder, or still in the former one where it could not be moved. */
    private static function path(string $filename): ?string
    {
        if (!preg_match(self::FILE, $filename)) return null;
        foreach ([self::directory(), self::formerDirectory()] as $directory) {
            $path = $directory . '/' . $filename;
            if (!is_link($path) && is_file($path)) return $path;
        }
        return null;
    }

    /** Kind => label, of one topic or of all. @return array<string,string> */
    public static function labels(?string $topic = null): array
    {
        $labels = [];
        foreach (self::KINDS as $kind => $about) if ($topic === null || $about['topic'] === $topic) $labels[$kind] = $about['label'];
        return $labels;
    }

    /** @return array{id:int,library:string,kind:string,topic:string,title:string,mime:string,created_at:string,room:?array{id:int,name:string}} */
    private static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'library' => (string) $row['library_code'], 'kind' => (string) $row['kind'],
            'topic' => self::KINDS[$row['kind']]['topic'] ?? '', 'title' => (string) $row['title'],
            'mime' => (string) $row['mime'], 'created_at' => (string) $row['created_at'],
            // A room deleted since leaves its documents with the location.
            'room' => $row['room_id'] === null ? null : ['id' => (int) $row['room_id'], 'name' => (string) $row['room_name']],
        ];
    }

    /** One location's documents in the order of KINDS, of one topic when given. @return list<array<string,mixed>> */
    public static function of(PDO $db, string $library, ?string $topic = null): array
    {
        $query = $db->prepare(self::SELECT . ' WHERE d.library_code = ? ORDER BY d.id');
        $query->execute([$library]);
        $rows = array_map([self::class, 'present'], $query->fetchAll(PDO::FETCH_ASSOC));
        if ($topic !== null) $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['topic'] === $topic));
        $order = array_flip(array_keys(self::KINDS));
        usort($rows, static fn(array $a, array $b): int => [$order[$a['kind']] ?? 99, $a['id']] <=> [$order[$b['kind']] ?? 99, $b['id']]);
        return $rows;
    }

    /**
     * Whether a location has a document of a kind. A SLiMS that has not run migration 17 has none.
     */
    public static function has(PDO $db, string $library, string $kind): bool
    {
        try {
            $query = $db->prepare('SELECT 1 FROM inventory_support_documents WHERE library_code = ? AND kind = ? LIMIT 1');
            $query->execute([$library, $kind]);
            return (bool) $query->fetchColumn();
        } catch (\PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            return false;
        }
    }

    /**
     * Takes in a file the plugin already keeps elsewhere as a document of a location: the file is
     * moved into the folder under a new name. False when it is not a kind of file kept here or
     * cannot be moved; nothing is recorded then.
     */
    public static function adopt(PDO $db, string $library, string $kind, string $path, string $title, string $mime, string $createdAt): bool
    {
        if (!isset(self::KINDS[$kind], RoomPlans::TYPES[$mime])) return false;
        try {
            PhotoStorage::protect(self::directory());
        } catch (RuntimeException $error) {
            return false;
        }
        $filename = 'dokumen-' . bin2hex(random_bytes(16)) . '.' . RoomPlans::TYPES[$mime];
        $target = self::directory() . '/' . $filename;
        if (!@rename($path, $target)) return false;
        @chmod($target, 0600);
        try {
            $db->prepare('INSERT INTO inventory_support_documents (library_code, kind, location_id, title, filename, mime, created_by, created_at) VALUES (?, ?, NULL, ?, ?, ?, NULL, ?)')
                ->execute([$library, $kind, $title, $filename, $mime, $createdAt]);
        } catch (\Throwable $error) {
            @rename($target, $path);
            throw $error;
        }
        return true;
    }

    /** Stores an uploaded document for a location. @return int the document's id */
    public static function upload(PDO $db, string $library, array $file, string $kind, string $title, int $roomId, ?int $uid, string $now): int
    {
        if (!isset(self::KINDS[$kind])) throw new RuntimeException('Pilih jenis dokumen pendukung.');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Pilih berkas dokumen (PDF, JPEG, PNG, atau WebP) maksimal 5 MB.');
        }
        // The ISP's service or an emergency procedure is the location's, whatever room was sent.
        if (!self::KINDS[$kind]['room']) $roomId = 0;
        if ($roomId > 0) {
            $room = $db->prepare('SELECT slims_location_id FROM inventory_locations WHERE id = ?');
            $room->execute([$roomId]);
            $code = $room->fetchColumn();
            if ($code === false || ($library !== '' && (string) $code !== $library)) throw new RuntimeException('Ruangan tidak ditemukan di lokasi ini.');
        }
        if (count(self::of($db, $library)) >= self::MAX_PER_LIBRARY) {
            throw new RuntimeException('Satu lokasi memuat paling banyak ' . self::MAX_PER_LIBRARY . ' dokumen pendukung. Hapus dokumen yang tidak dipakai.');
        }
        $mime = RoomPlans::mime($file['tmp_name'], 'dokumen pendukung');
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title === '') $title = trim((string) preg_replace('/\s+/u', ' ', pathinfo((string) ($file['name'] ?? ''), PATHINFO_FILENAME)));
        if ($title === '') $title = self::KINDS[$kind]['label'];
        if (mb_strlen($title) > 150) throw new RuntimeException('Judul dokumen maksimal 150 karakter.');
        try {
            PhotoStorage::protect(self::directory());
        } catch (RuntimeException $error) {
            throw new RuntimeException('Folder dokumen pendukung tidak dapat dibuat atau dilindungi.');
        }
        $filename = 'dokumen-' . bin2hex(random_bytes(16)) . '.' . RoomPlans::TYPES[$mime];
        $path = self::directory() . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $path)) throw new RuntimeException('Berkas dokumen tidak dapat disimpan.');
        @chmod($path, 0600);
        try {
            $db->prepare('INSERT INTO inventory_support_documents (library_code, kind, location_id, title, filename, mime, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$library, $kind, $roomId > 0 ? $roomId : null, $title, $filename, $mime, $uid, $now]);
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }
        return (int) $db->lastInsertId();
    }

    public static function delete(PDO $db, string $library, int $id): void
    {
        $query = $db->prepare('SELECT filename FROM inventory_support_documents WHERE id = ? AND library_code = ?');
        $query->execute([$id, $library]);
        $filename = $query->fetchColumn();
        if ($filename === false) throw new RuntimeException('Dokumen tidak ditemukan di lokasi ini.');
        $db->prepare('DELETE FROM inventory_support_documents WHERE id = ? AND library_code = ?')->execute([$id, $library]);
        $path = self::path((string) $filename);
        if ($path !== null) @unlink($path);
    }

    /** @return array{path:string,mime:string,name:string}|null the file to send, with a download name safe for a header */
    public static function file(PDO $db, int $id): ?array
    {
        $query = $db->prepare('SELECT title, filename, mime FROM inventory_support_documents WHERE id = ?');
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        $path = $row && isset(RoomPlans::TYPES[$row['mime']]) ? self::path((string) $row['filename']) : null;
        if ($path === null) return null;
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $row['title']), '-')) ?: 'dokumen-pendukung';
        return ['path' => $path, 'mime' => (string) $row['mime'], 'name' => substr($slug, 0, 80) . '.' . RoomPlans::TYPES[$row['mime']]];
    }
}
