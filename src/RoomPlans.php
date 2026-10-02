<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

require_once __DIR__ . '/PhotoStorage.php';

/**
 * Floor plans of a room: uploaded pictures or PDFs, several per room (one per floor, or a plan and
 * an evacuation map). They are kept as uploaded, since a plan must stay legible, in a folder
 * denied to browsers like the plugin's photos, and are served only through the room's page.
 */
final class RoomPlans
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_PER_ROOM = 10;
    public const TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const FILE = '/\Adenah-[0-9a-f]{32}\.(pdf|jpg|png|webp)\z/';

    public static function directory(): string
    {
        return rtrim(SB, '/\\') . '/images/inventaris-barang/denah';
    }

    /** @return list<array{id:int,title:string,mime:string,created_at:string}> */
    public static function of(PDO $db, int $roomId): array
    {
        $query = $db->prepare('SELECT id, title, mime, created_at FROM inventory_room_plans WHERE location_id = ? ORDER BY id');
        $query->execute([$roomId]);
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'mime' => (string) $row['mime'], 'created_at' => (string) $row['created_at']], $query->fetchAll(PDO::FETCH_ASSOC));
    }

    /** The kind of file at $path, read from its content: one of TYPES, or refused. */
    public static function mime(string $path): string
    {
        $size = @filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) throw new RuntimeException('Berkas denah maksimal 5 MB.');
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!isset(self::TYPES[$mime])) throw new RuntimeException('Denah harus berupa PDF, JPEG, PNG, atau WebP.');
        // A picture must really be one: its type is sent as stored when the plan is opened.
        if ($mime !== 'application/pdf' && (($info = @getimagesize($path)) === false || ($info['mime'] ?? '') !== $mime)) {
            throw new RuntimeException('Gambar denah tidak dapat dibaca.');
        }
        return $mime;
    }

    public static function title(string $title, string $originalName): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title === '') $title = trim((string) preg_replace('/\s+/u', ' ', pathinfo($originalName, PATHINFO_FILENAME)));
        if ($title === '') $title = 'Denah';
        if (mb_strlen($title) > 150) throw new RuntimeException('Judul denah maksimal 150 karakter.');
        return $title;
    }

    /** Stores an uploaded plan for a room. @return int the plan's id */
    public static function upload(PDO $db, int $roomId, array $file, string $title, ?int $uid, string $now): int
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Pilih berkas denah (PDF, JPEG, PNG, atau WebP) maksimal 5 MB.');
        }
        $room = $db->prepare('SELECT 1 FROM inventory_locations WHERE id = ?');
        $room->execute([$roomId]);
        if (!$room->fetchColumn()) throw new RuntimeException('Ruangan tidak ditemukan.');
        if (count(self::of($db, $roomId)) >= self::MAX_PER_ROOM) throw new RuntimeException('Satu ruangan memuat paling banyak ' . self::MAX_PER_ROOM . ' denah. Hapus denah yang tidak dipakai.');
        $mime = self::mime($file['tmp_name']);
        $title = self::title($title, (string) ($file['name'] ?? ''));
        try {
            PhotoStorage::protect(self::directory());
        } catch (RuntimeException $error) {
            throw new RuntimeException('Folder denah tidak dapat dibuat atau dilindungi.');
        }
        $filename = 'denah-' . bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        $path = self::directory() . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $path)) throw new RuntimeException('Berkas denah tidak dapat disimpan.');
        @chmod($path, 0600);
        try {
            $db->prepare('INSERT INTO inventory_room_plans (location_id, title, filename, mime, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([$roomId, $title, $filename, $mime, $uid, $now]);
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }
        return (int) $db->lastInsertId();
    }

    public static function delete(PDO $db, int $roomId, int $id): void
    {
        $query = $db->prepare('SELECT filename FROM inventory_room_plans WHERE id = ? AND location_id = ?');
        $query->execute([$id, $roomId]);
        $filename = $query->fetchColumn();
        if ($filename === false) throw new RuntimeException('Denah tidak ditemukan di ruangan ini.');
        $db->prepare('DELETE FROM inventory_room_plans WHERE id = ? AND location_id = ?')->execute([$id, $roomId]);
        self::cleanup([(string) $filename]);
    }

    /** With the room itself. @return list<string> files to remove once the deletion is committed */
    public static function deleteRoom(PDO $db, int $roomId): array
    {
        $query = $db->prepare('SELECT filename FROM inventory_room_plans WHERE location_id = ?');
        $query->execute([$roomId]);
        $files = array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN));
        $db->prepare('DELETE FROM inventory_room_plans WHERE location_id = ?')->execute([$roomId]);
        return $files;
    }

    /** @param list<string> $filenames */
    public static function cleanup(array $filenames): void
    {
        foreach ($filenames as $filename) {
            if (preg_match(self::FILE, $filename)) @unlink(self::directory() . '/' . $filename);
        }
    }

    /** @return array{path:string,mime:string,name:string}|null the file to send, with a download name safe for a header */
    public static function file(PDO $db, int $id): ?array
    {
        $query = $db->prepare('SELECT title, filename, mime FROM inventory_room_plans WHERE id = ?');
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || !preg_match(self::FILE, (string) $row['filename']) || !isset(self::TYPES[$row['mime']])) return null;
        $path = self::directory() . '/' . $row['filename'];
        if (is_link($path) || !is_file($path)) return null;
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $row['title']), '-')) ?: 'denah';
        return ['path' => $path, 'mime' => (string) $row['mime'], 'name' => substr($slug, 0, 80) . '.' . self::TYPES[$row['mime']]];
    }
}
