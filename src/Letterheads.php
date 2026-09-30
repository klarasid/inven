<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

require_once __DIR__ . '/PdfFonts.php';

/**
 * Institution letterhead templates: an uploaded PDF (letterhead and footer) whose pages become the
 * background of printed reports, plus the content area to typeset into. Page 1 of the template backs
 * the first report page; page 2, when present, backs the following pages, unless `first_only` is set,
 * in which case the following pages are plain paper (the usual practice for multi-page letters).
 *
 * Files live under images/inventaris-barang/kop, which inherits the web-server denial of the plugin's
 * photo folder; metadata is kept serialized in SLiMS's `setting` table.
 */
final class Letterheads
{
    public const SETTING = 'inventory_pdf_letterheads';
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_TEMPLATES = 20;
    public const BODIES = ['latex' => 'Gaya LaTeX', 'iso' => 'Dokumen ISO'];

    public static function directory(): string
    {
        return rtrim(SB, '/\\') . '/images/inventaris-barang/kop';
    }

    public static function path(array $template): string
    {
        if (!preg_match('/\A[a-f0-9]{16}\z/', (string) ($template['id'] ?? ''))) throw new \RuntimeException('Template tidak valid.');
        return self::directory() . '/' . $template['id'] . '.pdf';
    }

    /**
     * The file reports print on. With `first_only`, a cached copy holding just page 1: mPDF's document
     * template walks the file's pages in order, so page 2 of the original would still back page 2.
     */
    public static function printFile(array $template, string $tempDir): string
    {
        $source = self::path($template);
        if (empty($template['first_only']) || (int) $template['pages'] < 2) return $source;
        $copy = self::directory() . '/' . $template['id'] . '-p1.pdf';
        if (!is_file($copy) || filemtime($copy) < filemtime($source)) {
            [$width, $height] = $template['sizes'][0];
            $pdf = new \Mpdf\Mpdf(['tempDir' => $tempDir, 'exposeVersion' => false, 'format' => [$width, $height], 'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0]);
            $pdf->setSourceFile($source);
            $pdf->useTemplate($pdf->importPage(1), 0, 0, $width, $height);
            $pdf->Output($copy, 'F');
        }
        return $copy;
    }

    /** @return array<string, array> keyed by id */
    public static function all(\PDO $db): array
    {
        $read = $db->prepare('SELECT setting_value FROM setting WHERE setting_name = ?');
        $read->execute([self::SETTING]);
        $value = $read->fetchColumn();
        $list = is_string($value) ? @unserialize($value, ['allowed_classes' => false]) : [];
        return is_array($list) ? $list : [];
    }

    public static function find(\PDO $db, string $id): array
    {
        $template = self::all($db)[$id] ?? null;
        if (!$template || !is_file(self::path($template))) throw new \RuntimeException('Template kop tidak ditemukan. Unggah ulang di Pengaturan template kop.');
        return $template;
    }

    private static function store(\PDO $db, array $list): void
    {
        $db->prepare('INSERT INTO setting (setting_name, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([self::SETTING, serialize($list)]);
    }

    /**
     * Reads page count and page sizes (mm) through mPDF's FPDI import, which is also how reports use
     * the file, so an upload is accepted only if printing with it will work.
     * @return array{pages:int,sizes:array<array{0:float,1:float}>}
     */
    public static function inspect(string $file, string $tempDir): array
    {
        $pdf = new \Mpdf\Mpdf(['tempDir' => $tempDir, 'exposeVersion' => false]);
        try {
            $pages = $pdf->setSourceFile($file);
            $sizes = [];
            for ($n = 1; $n <= min(2, $pages); $n++) {
                $size = $pdf->getTemplateSize($pdf->importPage($n));
                $sizes[] = [round((float) $size['width'], 1), round((float) $size['height'], 1)];
            }
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'compression technique') !== false || stripos($e->getMessage(), 'cross-reference') !== false) {
                throw new \RuntimeException('PDF memakai kompresi yang belum didukung (PDF 1.5+ dengan object stream). Simpan ulang sebagai PDF/A atau PDF 1.4, misalnya di Word pilih "Simpan sebagai PDF" dengan opsi "Sesuai ISO 19005-1 (PDF/A)", atau cetak ulang lewat "Microsoft Print to PDF", lalu unggah kembali.');
            }
            if (stripos($e->getMessage(), 'encrypt') !== false) throw new \RuntimeException('PDF terkunci atau terenkripsi. Unggah PDF tanpa kata sandi.');
            throw new \RuntimeException('PDF tidak dapat dibaca sebagai template.');
        }
        if ($pages < 1) throw new \RuntimeException('PDF tidak memiliki halaman.');
        return ['pages' => min(2, $pages), 'sizes' => $sizes];
    }

    public static function upload(\PDO $db, array $file, string $name, string $tempDir): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('Pilih berkas PDF template, maksimal 5 MB.');
        }
        if ((int) $file['size'] > self::MAX_BYTES) throw new \RuntimeException('Berkas template maksimal 5 MB.');
        $head = (string) file_get_contents($file['tmp_name'], false, null, 0, 5);
        if ($head !== '%PDF-') throw new \RuntimeException('Berkas harus berformat PDF.');
        $list = self::all($db);
        if (count($list) >= self::MAX_TEMPLATES) throw new \RuntimeException('Maksimal ' . self::MAX_TEMPLATES . ' template. Hapus template yang tidak dipakai.');
        $info = self::inspect($file['tmp_name'], $tempDir);

        $dir = self::directory();
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) throw new \RuntimeException('Folder template tidak dapat dibuat.');
        $id = bin2hex(random_bytes(8));
        $template = [
            'id' => $id,
            'name' => self::name($name !== '' ? $name : pathinfo((string) $file['name'], PATHINFO_FILENAME)),
            'pages' => $info['pages'],
            'sizes' => $info['sizes'],
            // Defaults leave room for a typical letterhead and footer; the user adjusts them on the preview.
            'first' => ['top' => 45, 'right' => 20, 'bottom' => 30, 'left' => 20],
            // Single-page letterheads usually head only the first page; a second page was uploaded on purpose.
            'first_only' => $info['pages'] < 2,
            'next' => ['top' => $info['pages'] > 1 ? 30 : 25, 'bottom' => $info['pages'] > 1 ? 30 : 25],
            'body' => 'latex',
            'font' => '',
            'created_at' => date('Y-m-d H:i:s'),
        ];
        if (!move_uploaded_file($file['tmp_name'], self::path($template))) throw new \RuntimeException('Berkas template tidak dapat disimpan.');
        $list[$id] = $template;
        self::store($db, $list);
        return $template;
    }

    private static function name(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 80) throw new \RuntimeException('Nama template wajib diisi, maksimal 80 karakter.');
        return $name;
    }

    /** Updates name, body style and content area; the area must leave at least 40 × 40 mm for content. */
    public static function update(\PDO $db, string $id, array $input): array
    {
        $list = self::all($db);
        $template = $list[$id] ?? null;
        if (!$template) throw new \RuntimeException('Template kop tidak ditemukan.');
        [$width, $height] = $template['sizes'][0];
        $mm = static function ($value, string $label) : float {
            if (!is_numeric($value)) throw new \RuntimeException("Margin $label harus berupa angka (mm).");
            return round(max(0.0, (float) $value), 1);
        };
        $first = [];
        foreach (['top' => 'atas', 'right' => 'kanan', 'bottom' => 'bawah', 'left' => 'kiri'] as $side => $label) $first[$side] = $mm($input['first'][$side] ?? null, "$label halaman pertama");
        $next = [];
        foreach (['top' => 'atas', 'bottom' => 'bawah'] as $side => $label) $next[$side] = $mm($input['next'][$side] ?? null, "$label halaman berikutnya");
        if ($width - $first['left'] - $first['right'] < 40) throw new \RuntimeException('Area konten terlalu sempit. Kurangi margin kiri atau kanan.');
        if ($height - $first['top'] - $first['bottom'] < 40 || $height - $next['top'] - $next['bottom'] < 40) throw new \RuntimeException('Area konten terlalu pendek. Kurangi margin atas atau bawah.');
        $body = (string) ($input['body'] ?? 'latex');
        if (!isset(self::BODIES[$body])) throw new \RuntimeException('Gaya isi tidak dikenal.');
        $firstOnly = in_array((string) ($input['first_only'] ?? ''), ['1', 'true'], true);
        // Empty font keeps the body style's own typeface (Computer Modern for LaTeX, FreeSans for ISO).
        $font = (string) ($input['font'] ?? '');
        if (!PdfFonts::valid($font)) throw new \RuntimeException('Font tidak didukung.');
        $template = array_merge($template, ['name' => self::name((string) ($input['name'] ?? '')), 'first' => $first, 'next' => $next, 'body' => $body, 'first_only' => $firstOnly, 'font' => $font]);
        $list[$id] = $template;
        self::store($db, $list);
        return $template;
    }

    public static function delete(\PDO $db, string $id): void
    {
        $list = self::all($db);
        if (!isset($list[$id])) return;
        $path = self::path($list[$id]);
        unset($list[$id]);
        self::store($db, $list);
        foreach ([$path, substr($path, 0, -4) . '-p1.pdf'] as $file) if (is_file($file)) @unlink($file);
    }
}
