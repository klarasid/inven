<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

require_once __DIR__ . '/PdfLayout.php';

/**
 * Configurable document identity for printed reports: number format, revision and issue date
 * per document type. Stored in SLiMS's `setting` table as a serialized array, the format SLiMS
 * itself unserializes into $sysconf on every request.
 */
final class PdfDocuments
{
    public const SETTING = 'inventory_pdf_documents';
    public const TYPES = ['period' => 'Laporan periode', 'inspection' => 'Dokumen pemeriksaan', 'report' => 'Laporan kerusakan', 'sarpras' => 'Rekap sarpras'];
    public const DEFAULTS = [
        'period' => ['number' => 'LAP-SARPRAS/{dari}-{sampai}', 'revision' => '00', 'issued' => ''],
        'inspection' => ['number' => 'PMR-{id}', 'revision' => '00', 'issued' => ''],
        'report' => ['number' => 'LK-{id}', 'revision' => '00', 'issued' => ''],
        'sarpras' => ['number' => 'REKAP-SARPRAS/{romawi}/{tahun}', 'revision' => '00', 'issued' => ''],
    ];
    /** Placeholders accepted in number formats, with their meaning for the settings form. */
    public const PLACEHOLDERS = [
        '{id}' => 'nomor data, 5 digit (00171)',
        '{tahun}' => 'tahun dokumen (2026)',
        '{bulan}' => 'bulan dokumen, 2 digit (09)',
        '{romawi}' => 'bulan dokumen, angka Romawi (IX)',
        '{dari}' => 'awal periode laporan (20251001)',
        '{sampai}' => 'akhir periode laporan (20260930)',
    ];
    private const ROMAN = [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    public static function load(\PDO $db): array
    {
        $raw = $db->prepare('SELECT setting_value FROM setting WHERE setting_name=?');
        $raw->execute([self::SETTING]);
        $value = $raw->fetchColumn();
        $stored = is_string($value) ? @unserialize($value, ['allowed_classes' => false]) : [];
        $settings = [];
        foreach (self::DEFAULTS as $type => $defaults) {
            $settings[$type] = array_merge($defaults, array_intersect_key(is_array($stored[$type] ?? null) ? $stored[$type] : [], $defaults));
        }
        return $settings;
    }

    public static function save(\PDO $db, array $input): array
    {
        $settings = [];
        foreach (self::DEFAULTS as $type => $defaults) {
            $row = is_array($input[$type] ?? null) ? $input[$type] : [];
            $label = self::TYPES[$type];
            $number = trim((string) ($row['number'] ?? ''));
            $revision = trim((string) ($row['revision'] ?? ''));
            $issued = trim((string) ($row['issued'] ?? ''));
            if ($number === '' || mb_strlen($number) > 80) throw new \RuntimeException("Format nomor $label wajib diisi, maksimal 80 karakter.");
            if (preg_match_all('/\{[^}]*\}/', $number, $found)) {
                foreach ($found[0] as $token) if (!isset(self::PLACEHOLDERS[$token])) throw new \RuntimeException("Penanda $token pada nomor $label tidak dikenal.");
            }
            if ($revision === '' || mb_strlen($revision) > 10) throw new \RuntimeException("Revisi $label wajib diisi, maksimal 10 karakter.");
            if ($issued !== '') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $issued);
                if (!$date || $date->format('Y-m-d') !== $issued) throw new \RuntimeException("Tanggal terbit $label tidak valid.");
            }
            $settings[$type] = ['number' => $number, 'revision' => $revision, 'issued' => $issued];
        }
        $db->prepare('INSERT INTO setting (setting_name,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
            ->execute([self::SETTING, serialize($settings)]);
        return $settings;
    }

    /**
     * Document identity for the control header.
     * @param array{id?:int|string,date?:string,from?:string,to?:string} $vars date = the document's own date
     * @return array{number:string,revision:string,date:string} date already formatted for display
     */
    public static function identity(array $settings, string $type, array $vars, string $fallbackDate): array
    {
        $config = $settings[$type] ?? self::DEFAULTS[$type];
        $date = new \DateTimeImmutable((string) (($vars['date'] ?? '') ?: 'now'));
        $compact = fn($value) => $value ? (new \DateTimeImmutable((string) $value))->format('Ymd') : '';
        $number = strtr($config['number'], [
            '{id}' => str_pad((string) (int) ($vars['id'] ?? 0), 5, '0', STR_PAD_LEFT),
            '{tahun}' => $date->format('Y'),
            '{bulan}' => $date->format('m'),
            '{romawi}' => self::ROMAN[(int) $date->format('n')],
            '{dari}' => $compact($vars['from'] ?? ''),
            '{sampai}' => $compact($vars['to'] ?? ''),
        ]);
        return [
            'number' => $number,
            'revision' => $config['revision'],
            'date' => PdfLayout::date($config['issued'] !== '' ? $config['issued'] : $fallbackDate),
        ];
    }
}
