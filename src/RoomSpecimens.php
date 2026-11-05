<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

require_once __DIR__ . '/PhotoStorage.php';

/**
 * Signature specimens of a room's KIR: one picture for each of its two signers. An upload is read
 * as a picture and kept as a PNG of at most 600 px (transparency kept, metadata gone), in a folder
 * denied to browsers like the plugin's photos; it is served only to staff, and printed in the
 * signature space when the KIR is printed with specimens.
 */
final class RoomSpecimens
{
    public const ROLES = ['knowing' => 'Yang mengetahui', 'manager' => 'Pengurus barang'];
    public const MAX_BYTES = 2 * 1024 * 1024;
    private const MAX_SIDE = 600;
    private const TYPES = ['image/png', 'image/jpeg', 'image/webp'];
    private const FILE = '/\Aspesimen-[0-9a-f]{32}\.png\z/';

    public static function directory(): string
    {
        return rtrim(SB, '/\\') . '/images/inventaris-barang/spesimen';
    }

    /** @return array<string,string> the stored file name of each signer that has a specimen */
    private static function files(PDO $db, int $roomId): array
    {
        $query = $db->prepare('SELECT role, filename FROM inventory_room_specimens WHERE location_id = ?');
        $query->execute([$roomId]);
        $files = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset(self::ROLES[$row['role']]) && preg_match(self::FILE, (string) $row['filename'])) $files[(string) $row['role']] = (string) $row['filename'];
        }
        return $files;
    }

    /** Which signers of the room have a specimen. @return list<string> */
    public static function roles(PDO $db, int $roomId): array
    {
        return array_keys(self::files($db, $roomId));
    }

    /**
     * What a save of the room form asks for, checked before anything is stored: for each signer a
     * new picture (as PNG bytes) or its removal.
     *
     * @param  array<string,mixed>  $files  $_FILES
     * @param  array<string,mixed>  $post   $_POST
     * @return array<string,string|null>  role => PNG bytes to store, or null to remove
     */
    public static function requested(array $files, array $post): array
    {
        $changes = [];
        foreach (self::ROLES as $role => $label) {
            $file = is_array($files['specimen_' . $role] ?? null) ? $files['specimen_' . $role] : [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ($file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
                    throw new RuntimeException('Spesimen tanda tangan ' . mb_strtolower($label) . ' tidak dapat diunggah. Gunakan PNG, JPEG, atau WebP maksimal 2 MB.');
                }
                $changes[$role] = self::normalize($file['tmp_name'], $label);
            } elseif (($post['remove_specimen_' . $role] ?? '') === '1') {
                $changes[$role] = null;
            }
        }
        return $changes;
    }

    /** The picture at $path as PNG bytes, or a refusal that names the signer. */
    public static function normalize(string $path, string $label = ''): string
    {
        $who = $label !== '' ? ' ' . mb_strtolower($label) : '';
        $size = @filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) throw new RuntimeException('Spesimen tanda tangan' . $who . ' maksimal 2 MB.');
        $info = @getimagesize($path);
        if ($info === false || !in_array($info['mime'] ?? '', self::TYPES, true) || (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path) !== $info['mime']) {
            throw new RuntimeException('Spesimen tanda tangan' . $who . ' harus berupa gambar PNG, JPEG, atau WebP.');
        }
        if ($info[0] * $info[1] > 40_000_000) throw new RuntimeException('Gambar spesimen tanda tangan' . $who . ' terlalu besar.');
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if (!$image) throw new RuntimeException('Gambar spesimen tanda tangan' . $who . ' tidak dapat dibaca.');
        $scale = min(1, self::MAX_SIDE / max(imagesx($image), imagesy($image)));
        $out = imagecreatetruecolor(max(1, (int) round(imagesx($image) * $scale)), max(1, (int) round(imagesy($image) * $scale)));
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagecopyresampled($out, $image, 0, 0, 0, 0, imagesx($out), imagesy($out), imagesx($image), imagesy($image));
        ob_start();
        imagepng($out, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($image);
        imagedestroy($out);
        return $png;
    }

    /**
     * Stores what requested() asked for, for a room that exists now.
     *
     * @param  array<string,string|null>  $changes
     */
    public static function save(PDO $db, int $roomId, array $changes, string $now): void
    {
        if (!$changes) return;
        $old = self::files($db, $roomId);
        $stale = [];
        foreach ($changes as $role => $png) {
            if (!isset(self::ROLES[$role])) continue;
            if ($png === null) {
                $db->prepare('DELETE FROM inventory_room_specimens WHERE location_id = ? AND role = ?')->execute([$roomId, $role]);
            } else {
                try {
                    PhotoStorage::protect(self::directory());
                } catch (RuntimeException $error) {
                    throw new RuntimeException('Folder spesimen tidak dapat dibuat atau dilindungi.');
                }
                $filename = 'spesimen-' . bin2hex(random_bytes(16)) . '.png';
                $path = self::directory() . '/' . $filename;
                if (file_put_contents($path, $png) === false) throw new RuntimeException('Spesimen tanda tangan tidak dapat disimpan.');
                @chmod($path, 0600);
                try {
                    $db->prepare('REPLACE INTO inventory_room_specimens (location_id, role, filename, updated_at) VALUES (?, ?, ?, ?)')->execute([$roomId, $role, $filename, $now]);
                } catch (\Throwable $error) {
                    @unlink($path);
                    throw $error;
                }
            }
            if (isset($old[$role])) $stale[] = $old[$role];
        }
        self::cleanup($stale);
    }

    /** With the room itself. @return list<string> files to remove once the deletion is committed */
    public static function deleteRoom(PDO $db, int $roomId): array
    {
        $files = array_values(self::files($db, $roomId));
        $db->prepare('DELETE FROM inventory_room_specimens WHERE location_id = ?')->execute([$roomId]);
        return $files;
    }

    /** @param list<string> $filenames */
    public static function cleanup(array $filenames): void
    {
        foreach ($filenames as $filename) {
            if (preg_match(self::FILE, $filename)) @unlink(self::directory() . '/' . $filename);
        }
    }

    /** The PNG of a signer's specimen to send, or null. */
    public static function path(PDO $db, int $roomId, string $role): ?string
    {
        $filename = self::files($db, $roomId)[$role] ?? null;
        if ($filename === null) return null;
        $path = self::directory() . '/' . $filename;
        return is_link($path) || !is_file($path) ? null : $path;
    }

    /**
     * The specimens of a room as data URIs for the KIR, by signer; a signer without one, or
     * whose file is gone, is left out.
     *
     * @return array<string,string>
     */
    public static function dataUris(PDO $db, int $roomId): array
    {
        $uris = [];
        foreach (array_keys(self::ROLES) as $role) {
            $path = self::path($db, $roomId, $role);
            $bytes = $path === null ? false : @file_get_contents($path);
            if ($bytes !== false && $bytes !== '') $uris[$role] = 'data:image/png;base64,' . base64_encode($bytes);
        }
        return $uris;
    }

    /** What the KIR puts in a signer's signature space: the specimen, or nothing. */
    public static function image(array $specimens, string $role, string $height): string
    {
        return isset($specimens[$role]) ? '<img src="' . $specimens[$role] . '" style="height:' . $height . ';">' : '';
    }
}
