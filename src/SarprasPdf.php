<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

require_once __DIR__ . '/WatchPdf.php';
require_once __DIR__ . '/Sarpras.php';

/** Rekap Sarpras as a printable document, in the same typesetting styles as the supervision reports. */
final class SarprasPdf
{
    private const ROW_LIMIT = 80;

    private static function e($value): string { return PdfLayout::e($value); }

    private static function level(?string $level): string
    {
        return $level === null ? 'Belum ada data' : self::e(Sarpras::LEVELS[$level]);
    }

    public static function render(array $recap, array $context, string $style = 'latex'): string
    {
        $t = WatchPdf::STYLES[$style] ?? PdfLatex::class;
        $date = substr($recap['generated_at'], 0, 10);
        $s = $recap['summary'];
        $h = $t::begin() . $t::titleBlock(
            'Rekap Sarana dan Prasarana Perpustakaan',
            ($context['printed_by'] ?? '') !== '' ? self::e($context['printed_by']) : '',
            'Per ' . PdfLayout::date($date),
            PdfDocuments::identity($context['documents'] ?? PdfDocuments::DEFAULTS, 'sarpras', ['date' => $date], $date)
        );
        $h .= $t::abstract(
            'Rekap ini menyajikan kondisi sarana dan prasarana perpustakaan, dihitung dari data inventaris ruangan dan barang, '
            . 'register perangkat lunak, data jaringan, serta riwayat pengawasan dan pemeliharaan pada tanggal ' . PdfLayout::date($date) . '. '
            . 'Dari ' . count($recap['aspects']) . ' aspek yang direkap, <b>' . (int) $s['a'] . '</b> dalam kondisi Sangat baik, ' . (int) $s['b'] . ' Baik, ' . (int) $s['c'] . ' Cukup, dan ' . (int) $s['d'] . ' Kurang'
            . ((int) $s['empty'] ? ', sedangkan ' . (int) $s['empty'] . ' aspek belum memiliki data' : '') . '.'
        );

        $h .= $t::section('Ikhtisar');
        $overview = [];
        foreach ($recap['aspects'] as $aspect) {
            $overview[] = [(string) $aspect['no'], self::e($aspect['title']), self::e($aspect['value']), self::level($aspect['level'])];
        }
        $h .= $t::table('Ringkasan kondisi sarana dan prasarana', [['No.', 'r'], 'Aspek', 'Capaian', 'Kondisi'], $overview);

        $section = '';
        foreach ($recap['aspects'] as $aspect) {
            if ($aspect['section'] !== $section) {
                $section = $aspect['section'];
                $h .= $t::section($section);
            }
            $h .= $t::subsection($aspect['title']);
            $h .= $t::paragraph('Capaian: <b>' . self::e($aspect['value']) . '</b>, kondisi <b>' . self::level($aspect['level']) . '</b>. ' . self::e($aspect['basis']));
            $checks = array_map(static fn($c) => [self::e($c['label']), $c['ok'] ? 'Terpenuhi' : 'Belum'], $aspect['checks']);
            $h .= $t::table('Syarat: ' . mb_strtolower($aspect['title']), ['Syarat', ['Status', 'c']], $checks);
            if ($aspect['rows']) {
                $rows = array_map(static fn($r) => array_map([self::class, 'e'], $r), array_slice($aspect['rows'], 0, self::ROW_LIMIT));
                $h .= $t::table('Rincian: ' . mb_strtolower($aspect['title']), $aspect['columns'], $rows, '', '8.8pt');
                if (count($aspect['rows']) > self::ROW_LIMIT) {
                    $h .= '<p class="small">Ditampilkan ' . self::ROW_LIMIT . ' dari ' . count($aspect['rows']) . ' baris.</p>';
                }
            }
        }

        return $h . $t::signatures([
            ['Mengetahui,', 'Kepala Perpustakaan', ''],
            ['Disusun oleh,', 'Petugas Pengelola', (string) ($context['printed_by'] ?? '')],
        ], '...................., ' . PdfLayout::date(new \DateTimeImmutable('now')));
    }
}
