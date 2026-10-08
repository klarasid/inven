<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

require_once __DIR__ . '/Letterheads.php';

/**
 * How a PDF is typeset when whoever asks for it names no style: the Klaras InvenSync app and AI
 * apps, which have no print menu to choose from. The admin pages always name one.
 * Stored in SLiMS's `setting` table as a serialized array, like the other print settings.
 */
final class PrintDefaults
{
    public const SETTING = 'inventory_pdf_defaults';
    /** Report styles besides the letterheads, which are named `kop:<id>`. */
    public const STYLES = ['latex' => 'Gaya LaTeX', 'iso' => 'Dokumen ISO'];
    public const KIR = ['classic' => 'Klasik', 'modern' => 'Modern'];
    public const DEFAULTS = ['style' => 'latex', 'kir' => 'classic'];

    /** @return array{style: string, kir: string} */
    public static function load(\PDO $db): array
    {
        $read = $db->prepare('SELECT setting_value FROM setting WHERE setting_name = ?');
        $read->execute([self::SETTING]);
        $value = $read->fetchColumn();
        $stored = is_string($value) ? @unserialize($value, ['allowed_classes' => false]) : [];
        $stored = is_array($stored) ? $stored : [];
        $style = (string) ($stored['style'] ?? '');
        $kir = (string) ($stored['kir'] ?? '');

        return [
            'style' => isset(self::STYLES[$style]) || self::letterheadId($style) !== null ? $style : self::DEFAULTS['style'],
            'kir' => isset(self::KIR[$kir]) ? $kir : self::DEFAULTS['kir'],
        ];
    }

    /** @return array{style: string, kir: string} */
    public static function save(\PDO $db, array $input): array
    {
        $style = (string) ($input['style'] ?? '');
        $kir = (string) ($input['kir'] ?? '');
        $letterhead = self::letterheadId($style);
        if (!isset(self::STYLES[$style]) && ($letterhead === null || !isset(Letterheads::all($db)[$letterhead]))) {
            throw new \RuntimeException('Gaya laporan tidak dikenal. Pilih salah satu dari daftar.');
        }
        if (!isset(self::KIR[$kir])) throw new \RuntimeException('Tata letak KIR tidak dikenal. Pilih Klasik atau Modern.');
        $settings = ['style' => $style, 'kir' => $kir];
        $value = serialize($settings);
        $update = $db->prepare('UPDATE setting SET setting_value = ? WHERE setting_name = ?');
        $update->execute([$value, self::SETTING]);
        if ($update->rowCount() === 0) {
            $exists = $db->prepare('SELECT COUNT(*) FROM setting WHERE setting_name = ?');
            $exists->execute([self::SETTING]);
            if ((int) $exists->fetchColumn() === 0) {
                $db->prepare('INSERT INTO setting (setting_name, setting_value) VALUES (?, ?)')->execute([self::SETTING, $value]);
            }
        }
        return $settings;
    }

    /**
     * The WatchPdf style to typeset a report with: the one asked for (`latex`, `iso`, `kop` or
     * `kop:<id>`), or with none asked the saved default. A letterhead is handed to PdfLetterhead
     * here. Plain `kop` means the default when that is a letterhead, else the first by name.
     *
     * A default whose letterhead has since been deleted falls back to LaTeX; a style someone
     * asked for by name is refused instead, so they learn it was not used.
     */
    public static function style(\PDO $db, string $requested = ''): string
    {
        $requested = trim($requested);
        $default = self::load($db)['style'];
        $style = $requested !== '' ? $requested : $default;
        if (isset(self::STYLES[$style])) return $style;
        if ($style === 'kop') {
            $names = array_map(static function (array $template): string { return (string) $template['name']; }, Letterheads::all($db));
            if ($names === []) throw new \RuntimeException('Belum ada template kop. Unggah kop di Pengaturan Cetak, atau pilih gaya latex atau iso.');
            uasort($names, 'strcasecmp');
            $saved = self::letterheadId($default);
            $style = 'kop:' . ($saved !== null && isset($names[$saved]) ? $saved : (string) array_key_first($names));
        }
        $letterhead = self::letterheadId($style);
        if ($letterhead === null) {
            if ($requested === '') return self::DEFAULTS['style'];
            throw new \RuntimeException('Gaya cetak tidak dikenal. Pilih latex, iso, atau kop.');
        }
        try {
            $template = Letterheads::find($db, $letterhead);
        } catch (\RuntimeException $error) {
            if ($requested === '') return self::DEFAULTS['style'];
            throw $error;
        }
        require_once __DIR__ . '/PdfLetterhead.php';
        PdfLetterhead::configure($template);

        return 'kop';
    }

    /** Whether a KIR is laid out the modern way: as asked (`modern` or `classic`), else the saved default. */
    public static function modern(\PDO $db, string $requested = ''): bool
    {
        return (isset(self::KIR[$requested]) ? $requested : self::load($db)['kir']) === 'modern';
    }

    private static function letterheadId(string $style): ?string
    {
        return preg_match('/\Akop:([a-f0-9]{16})\z/', $style, $match) === 1 ? $match[1] : null;
    }
}
