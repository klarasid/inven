<?php

namespace SLiMS\Plugins\Inventory;

final class PhotoStorage
{
    private string $directory;
    private const RULES = "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? SB . 'images/inventaris-barang';
    }

    /**
     * Makes a folder of uploads private: created for the web-server user alone, with rules that
     * deny browsers. Folders under the photo folder call this too, rather than count on the photo
     * folder's rules, which exist only once a photo has been stored.
     */
    public static function protect(string $directory): void
    {
        if (is_link($directory)) {
            throw new \RuntimeException('Direktori foto tidak boleh berupa symbolic link.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Direktori foto tidak dapat dibuat.');
        }
        $rules = $directory . '/.htaccess';
        if (!file_exists($rules)) {
            $handle = @fopen($rules, 'x');
            if ($handle) {
                fwrite($handle, self::RULES);
                fclose($handle);
            }
        }
        if (is_link($rules) || !is_file($rules) || file_get_contents($rules) !== self::RULES) {
            throw new \RuntimeException('Proteksi direktori foto tidak tersedia.');
        }
    }

    private function directory(): string
    {
        self::protect($this->directory);
        return $this->directory;
    }

    private function path(string $filename): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\.jpg\z/', $filename)) {
            throw new \RuntimeException('Nama berkas foto tidak valid.');
        }
        $path = $this->directory() . '/' . $filename;
        if (is_link($path)) {
            throw new \RuntimeException('Berkas foto tidak boleh berupa symbolic link.');
        }
        return $path;
    }

    public function write(string $jpeg): string
    {
        $filename = bin2hex(random_bytes(32)) . '.jpg';
        $path = $this->path($filename);
        $handle = @fopen($path, 'x+b');
        if (!$handle) {
            throw new \RuntimeException('Foto tidak dapat disimpan ke folder gambar.');
        }
        try {
            if (!chmod($path, 0600) || fwrite($handle, $jpeg) !== strlen($jpeg) || !fflush($handle)) {
                throw new \RuntimeException('Penulisan foto gagal.');
            }
        } catch (\Throwable $error) {
            fclose($handle);
            @unlink($path);
            throw $error;
        }
        fclose($handle);
        return $filename;
    }

    public function read(string $filename): ?string
    {
        $path = $this->path($filename);
        if (!is_file($path)) return null;
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new \RuntimeException('Foto tidak dapat dibaca.');
        return $bytes;
    }

    public function delete(string $filename): void
    {
        $path = $this->path($filename);
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Berkas foto tidak dapat dihapus.');
        }
    }

    public function cleanup(array $filenames): void
    {
        foreach ($filenames as $filename) {
            if ($filename === null) continue;
            try { $this->delete($filename); }
            catch (\Throwable $error) { error_log('Inventory photo cleanup: ' . $error->getMessage()); }
        }
    }
}
