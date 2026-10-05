<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

require_once __DIR__ . '/PhotoStorage.php';
require_once __DIR__ . '/RoomPlans.php';

/**
 * Bukti lisensi: the files that show an application in the software register is legal, as an
 * accreditation asks for them: a licence certificate, an invoice, or a screenshot of its licence
 * or activation page. Uploaded pictures or PDFs, several per application, kept as uploaded in a
 * folder denied to browsers like the plugin's photos, and served only through Perangkat Lunak
 * and the API. They are evidence only: whether a licence counts is decided by its kind and expiry.
 */
final class SoftwareFiles
{
    public const MAX_BYTES = RoomPlans::MAX_BYTES;
    public const MAX_PER_SOFTWARE = 5;
    private const FILE = '/\Alisensi-[0-9a-f]{32}\.(pdf|jpg|png|webp)\z/';

    public static function directory(): string
    {
        return rtrim(SB, '/\\') . '/images/inventaris-barang/lisensi';
    }

    /**
     * Every application's files, by application.
     *
     * @return array<int, list<array{id:int,title:string,mime:string,created_at:string}>>
     */
    public static function bySoftware(PDO $db): array
    {
        $files = [];
        foreach ($db->query('SELECT id, software_id, title, mime, created_at FROM inventory_software_files ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $files[(int) $row['software_id']][] = ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'mime' => (string) $row['mime'], 'created_at' => (string) $row['created_at']];
        }
        return $files;
    }

    /** Stores an uploaded file for an application. @return int the file's id */
    public static function upload(PDO $db, int $softwareId, array $file, string $title, ?int $uid, string $now): int
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Pilih berkas bukti lisensi (PDF, JPEG, PNG, atau WebP) maksimal 5 MB.');
        }
        $software = $db->prepare('SELECT 1 FROM inventory_software WHERE id = ?');
        $software->execute([$softwareId]);
        if (!$software->fetchColumn()) throw new RuntimeException('Aplikasi tidak ditemukan.');
        $count = $db->prepare('SELECT COUNT(*) FROM inventory_software_files WHERE software_id = ?');
        $count->execute([$softwareId]);
        if ((int) $count->fetchColumn() >= self::MAX_PER_SOFTWARE) {
            throw new RuntimeException('Satu aplikasi memuat paling banyak ' . self::MAX_PER_SOFTWARE . ' berkas bukti lisensi. Hapus berkas yang tidak dipakai.');
        }
        $mime = RoomPlans::mime($file['tmp_name'], 'bukti lisensi');
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title === '') $title = trim((string) preg_replace('/\s+/u', ' ', pathinfo((string) ($file['name'] ?? ''), PATHINFO_FILENAME)));
        if ($title === '') $title = 'Bukti lisensi';
        if (mb_strlen($title) > 150) throw new RuntimeException('Judul berkas maksimal 150 karakter.');
        try {
            PhotoStorage::protect(self::directory());
        } catch (RuntimeException $error) {
            throw new RuntimeException('Folder bukti lisensi tidak dapat dibuat atau dilindungi.');
        }
        $filename = 'lisensi-' . bin2hex(random_bytes(16)) . '.' . RoomPlans::TYPES[$mime];
        $path = self::directory() . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $path)) throw new RuntimeException('Berkas bukti lisensi tidak dapat disimpan.');
        @chmod($path, 0600);
        try {
            $db->prepare('INSERT INTO inventory_software_files (software_id, title, filename, mime, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$softwareId, $title, $filename, $mime, $uid, $now]);
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }
        return (int) $db->lastInsertId();
    }

    public static function delete(PDO $db, int $softwareId, int $id): void
    {
        $query = $db->prepare('SELECT filename FROM inventory_software_files WHERE id = ? AND software_id = ?');
        $query->execute([$id, $softwareId]);
        $filename = $query->fetchColumn();
        if ($filename === false) throw new RuntimeException('Berkas tidak ditemukan pada aplikasi ini.');
        $db->prepare('DELETE FROM inventory_software_files WHERE id = ? AND software_id = ?')->execute([$id, $softwareId]);
        self::cleanup([(string) $filename]);
    }

    /** With the application itself: its files go too. */
    public static function deleteSoftware(PDO $db, int $softwareId): void
    {
        $query = $db->prepare('SELECT filename FROM inventory_software_files WHERE software_id = ?');
        $query->execute([$softwareId]);
        $files = array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
        $db->prepare('DELETE FROM inventory_software_files WHERE software_id = ?')->execute([$softwareId]);
        self::cleanup($files);
    }

    /** @param list<string> $filenames */
    private static function cleanup(array $filenames): void
    {
        foreach ($filenames as $filename) {
            if (preg_match(self::FILE, $filename)) @unlink(self::directory() . '/' . $filename);
        }
    }

    /** @return array{path:string,mime:string,name:string}|null the file to send, with a download name safe for a header */
    public static function file(PDO $db, int $id): ?array
    {
        $query = $db->prepare('SELECT title, filename, mime FROM inventory_software_files WHERE id = ?');
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || !preg_match(self::FILE, (string) $row['filename']) || !isset(RoomPlans::TYPES[$row['mime']])) return null;
        $path = self::directory() . '/' . $row['filename'];
        if (is_link($path) || !is_file($path)) return null;
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $row['title']), '-')) ?: 'bukti-lisensi';
        return ['path' => $path, 'mime' => (string) $row['mime'], 'name' => substr($slug, 0, 80) . '.' . RoomPlans::TYPES[$row['mime']]];
    }
}
