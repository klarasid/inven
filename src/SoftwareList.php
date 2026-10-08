<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

use PDO;

require_once __DIR__ . '/PdfLayout.php';
require_once __DIR__ . '/Sarpras.php';
require_once __DIR__ . '/SoftwareFiles.php';

/**
 * Daftar perangkat lunak: the software register (menu Perangkat Lunak) as an accreditation asks
 * for it, grouped by what each application is used for (operating system, office suite, library
 * automation, ...) with the licence that makes it legal and the files that show it (SoftwareFiles).
 * The register is one for the institution.
 */
final class SoftwareList
{
    private const NO_PURPOSE = 'Kegunaan belum diisi';

    /**
     * @return array{applications:int,licensed:int,percent:?float,groups:list<array{label:string,items:list<array<string,mixed>>}>}
     */
    public static function build(PDO $db, string $today): array
    {
        $groups = [];
        $licensed = 0;
        $rows = Sarpras::software($db);
        // Before migration 16 no application has licence files.
        try {
            $files = SoftwareFiles::bySoftware($db);
        } catch (\PDOException $error) {
            if (!in_array((int) ($error->errorInfo[1] ?? 0), [1054, 1146], true)) throw $error;
            $files = [];
        }
        foreach ($rows as $row) {
            $row['files'] = $files[(int) $row['id']] ?? [];
            $row['licensed'] = Sarpras::licensed($row, $today);
            // A licence that ran out, as against software that never had one.
            $row['expired'] = !$row['licensed'] && $row['licence'] !== 'tidak';
            if ($row['licensed']) $licensed++;
            // "Sistem operasi" and "sistem operasi" are one use.
            $purpose = trim((string) preg_replace('/\s+/u', ' ', (string) $row['purpose']));
            $key = $purpose === '' ? "\u{10FFFF}" : mb_strtolower($purpose);
            $groups[$key] ??= ['label' => self::NO_PURPOSE, 'items' => [], 'spellings' => []];
            $groups[$key]['items'][] = $row;
            if ($purpose !== '') $groups[$key]['spellings'][$purpose] = ($groups[$key]['spellings'][$purpose] ?? 0) + 1;
        }
        ksort($groups, SORT_STRING);
        foreach ($groups as &$group) {
            // Named as most applications spell it; between equals, the capitalised one.
            ksort($group['spellings'], SORT_STRING);
            arsort($group['spellings'], SORT_NUMERIC);
            if ($group['spellings']) $group['label'] = (string) array_key_first($group['spellings']);
            unset($group['spellings']);
        }
        unset($group);
        return [
            'applications' => count($rows),
            'licensed' => $licensed,
            'percent' => $rows ? round($licensed * 100 / count($rows), 1) : null,
            'groups' => array_values($groups),
        ];
    }

    /**
     * The register as a document in one of the print styles the reports use (WatchPdf::STYLES).
     *
     * @param array{printed_by?:string,documents?:array} $context
     */
    public static function html(array $list, array $context = [], string $style = 'latex'): string
    {
        require_once __DIR__ . '/WatchPdf.php';
        $t = WatchPdf::STYLES[$style] ?? PdfLatex::class;
        $e = static fn($value): string => PdfLayout::e($value);
        $today = date('Y-m-d');
        $h = $t::begin()
            . $t::titleBlock(
                'Daftar Perangkat Lunak',
                ($context['printed_by'] ?? '') !== '' ? $e($context['printed_by']) : '',
                'Perangkat lunak yang digunakan perpustakaan beserta legalitas lisensinya · Per ' . PdfLayout::date($today),
                PdfDocuments::identity($context['documents'] ?? PdfDocuments::DEFAULTS, 'software', ['date' => $today], $today)
            )
            . $t::facts([
                'Jumlah aplikasi' => (string) $list['applications'],
                'Berlisensi resmi' => $list['licensed'] . ' dari ' . $list['applications'] . ($list['percent'] === null ? '' : ' (' . number_format($list['percent'], 1, ',', '.') . '%)'),
                'Dicetak oleh' => $e($context['printed_by'] ?? ''),
                'Tanggal' => PdfLayout::date($today),
            ])
            . $t::paragraph('Lisensi open source dan gratis dihitung resmi. Lisensi yang sudah melewati masa berlakunya dihitung tidak resmi.');
        if (!$list['groups']) $h .= $t::paragraph('Belum ada aplikasi di register Perangkat Lunak.');
        foreach ($list['groups'] as $group) {
            $rows = [];
            foreach ($group['items'] as $n => $item) {
                $rows[] = [
                    (string) ($n + 1),
                    '<b>' . $e($item['name']) . '</b>' . (trim((string) $item['version']) !== '' ? ' ' . $e($item['version']) : ''),
                    $e(Sarpras::LICENCES[$item['licence']] ?? $item['licence']),
                    (trim((string) $item['licence_ref']) !== '' ? $e($item['licence_ref']) : '—')
                        . ($item['files'] ? '<br><span style="font-size:8pt;color:#555555;">' . count($item['files']) . ' berkas bukti terlampir</span>' : ''),
                    $item['valid_until'] === null ? '—' : PdfLayout::date($item['valid_until']),
                    (string) (int) $item['installs'],
                    '<b>' . ($item['licensed'] ? 'Resmi' : ($item['expired'] ? 'Kedaluwarsa' : 'Tidak resmi')) . '</b>',
                ];
            }
            $h .= $t::section($group['label'] . ' (' . count($rows) . ' aplikasi)')
                . $t::table('Perangkat lunak ' . mb_strtolower((string) $group['label']), [['No.', 'r'], 'Aplikasi', 'Jenis lisensi', 'Nomor / bukti lisensi', 'Berlaku sampai', ['Instalasi', 'r'], 'Status'], $rows, '', '8.8pt');
        }
        return $h . $t::signatures([
            ['Mengetahui,', 'Kepala Perpustakaan', ''],
            ['Disusun oleh,', 'Petugas Pengelola', (string) ($context['printed_by'] ?? '')],
        ], '...................., ' . PdfLayout::date($today));
    }
}
