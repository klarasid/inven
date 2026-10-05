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

    /** @param array{printed_by?:string} $context */
    public static function html(array $list, array $context = []): string
    {
        $e = static fn($value): string => PdfLayout::e($value);
        $h = PdfLayout::css()
            . PdfLayout::header('DAFTAR PERANGKAT LUNAK', 'Perangkat lunak yang digunakan perpustakaan beserta legalitas lisensinya')
            . PdfLayout::meta([
                'Jumlah aplikasi' => (string) $list['applications'],
                'Berlisensi resmi' => $list['licensed'] . ' dari ' . $list['applications'] . ($list['percent'] === null ? '' : ' (' . number_format($list['percent'], 1, ',', '.') . '%)'),
                'Dicetak oleh' => $e($context['printed_by'] ?? ''),
                'Tanggal' => PdfLayout::date(new \DateTimeImmutable('now')),
            ])
            . '<p class="note">Lisensi open source dan gratis dihitung resmi. Lisensi yang sudah melewati masa berlakunya dihitung tidak resmi.</p>';
        if (!$list['groups']) $h .= '<p class="muted">Belum ada aplikasi di register Perangkat Lunak.</p>';
        foreach ($list['groups'] as $group) {
            $rows = [];
            foreach ($group['items'] as $n => $item) {
                $status = $item['licensed'] ? '<span class="t-good strong">Resmi</span>' : '<span class="t-bad strong">' . ($item['expired'] ? 'Kedaluwarsa' : 'Tidak resmi') . '</span>';
                $rows[] = [
                    (string) ($n + 1),
                    '<span class="strong">' . $e($item['name']) . '</span>' . (trim((string) $item['version']) !== '' ? ' ' . $e($item['version']) : ''),
                    $e(Sarpras::LICENCES[$item['licence']] ?? $item['licence']),
                    (trim((string) $item['licence_ref']) !== '' ? $e($item['licence_ref']) : '—')
                        . ($item['files'] ? '<br><span class="muted small">' . count($item['files']) . ' berkas bukti terlampir</span>' : ''),
                    $item['valid_until'] === null ? '—' : PdfLayout::date($item['valid_until']),
                    (string) (int) $item['installs'],
                    $status,
                ];
            }
            $h .= '<h2>' . $e($group['label']) . ' (' . count($rows) . ' aplikasi)</h2>'
                . PdfLayout::table([['No.', 'no'], 'Aplikasi', 'Jenis lisensi', 'Nomor / bukti lisensi', 'Berlaku sampai', ['Instalasi', 'num'], 'Status'], $rows);
        }
        return $h . PdfLayout::signatures([
            ['Mengetahui,', 'Kepala Perpustakaan', ''],
            ['Disusun oleh,', 'Petugas Pengelola', (string) ($context['printed_by'] ?? '')],
        ], '...................., ' . PdfLayout::date(new \DateTimeImmutable('now')));
    }
}
