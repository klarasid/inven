<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

require_once __DIR__ . '/PdfLayout.php';
require_once __DIR__ . '/WatchPdf.php';
require_once __DIR__ . '/Supervision.php';
require_once __DIR__ . '/WatchRecurrence.php';

/**
 * Two sheets of the supervision a library plans, as documents of their own: the schedule of
 * routine inspections, and the checklists the inspections are filled in on. The period report
 * and an inspection's document show them at work; these show what was set up. Both are typeset
 * in the print styles those documents use (WatchPdf::STYLES).
 */
final class WatchSheets
{
    private const MUTED = 'font-size:8pt;color:#555555;';

    /**
     * @param  list<array<string,mixed>>  $schedules  schedule rows, their snapshot decoded
     * @param  array{printed_by?:string,rooms?:int,documents?:array}  $context  `rooms`: how many rooms the library has
     */
    public static function schedules(array $schedules, array $context = [], string $style = 'latex'): string
    {
        $t = WatchPdf::STYLES[$style] ?? PdfLatex::class;
        $e = static fn($value): string => PdfLayout::e($value);
        $today = date('Y-m-d');
        $rows = [];
        $scheduled = [];
        foreach ($schedules as $n => $schedule) {
            $snapshot = is_array($schedule['snapshot']) ? $schedule['snapshot'] : Supervision::decode((string) $schedule['snapshot']);
            $scheduled[(int) $schedule['location_id']] = true;
            $library = trim((string) ($snapshot['library_name'] ?? ''));
            $rows[] = [
                (string) ($n + 1),
                '<b>' . $e($snapshot['room_name'] ?? '') . '</b>' . ($library !== '' ? '<br><span style="' . self::MUTED . '">' . $e($library) . '</span>' : ''),
                $e($snapshot['template_name'] ?? ''),
                $e(WatchRecurrence::FREQUENCIES[$schedule['frequency']] ?? $schedule['frequency']),
                PdfLayout::date($schedule['start_date']),
                $schedule['end_date'] === null ? 'Tanpa batas' : PdfLayout::date($schedule['end_date']),
                $e($schedule['assignee_name']),
            ];
        }
        $rooms = (int) ($context['rooms'] ?? 0);
        return $t::begin()
            . $t::titleBlock(
                'Jadwal Pemeriksaan Sarana dan Prasarana',
                ($context['printed_by'] ?? '') !== '' ? $e($context['printed_by']) : '',
                'Jadwal pemeriksaan rutin yang sedang berjalan · Per ' . PdfLayout::date($today),
                PdfDocuments::identity($context['documents'] ?? PdfDocuments::DEFAULTS, 'schedules', ['date' => $today], $today)
            )
            . $t::facts([
                'Jumlah jadwal' => (string) count($rows),
                'Ruangan terjadwal' => count($scheduled) . ($rooms > 0 ? ' dari ' . $rooms . ' ruangan' : ''),
                'Dicetak oleh' => $e($context['printed_by'] ?? ''),
                'Tanggal' => PdfLayout::date($today),
            ])
            . $t::table('Jadwal pemeriksaan rutin', [['No.', 'r'], 'Ruangan', 'Checklist', 'Frekuensi', 'Mulai', 'Berakhir', 'Petugas'], $rows, 'Belum ada jadwal pemeriksaan rutin yang berjalan.')
            . $t::paragraph('Tanggal pemeriksaan yang jatuh pada hari libur digeser ke hari kerja terdekat.')
            . $t::signatures([
                ['Mengetahui,', 'Kepala Perpustakaan', ''],
                ['Disusun oleh,', 'Petugas Pengelola', (string) ($context['printed_by'] ?? '')],
            ], '...................., ' . PdfLayout::date($today));
    }

    /**
     * Each checklist as the form an examiner fills in: its items by group, with room for the
     * outcome and a note.
     *
     * @param  list<array<string,mixed>>  $templates  checklist rows, their items decoded
     * @param  array{printed_by?:string,documents?:array}  $context
     */
    public static function checklists(array $templates, array $context = [], string $style = 'latex'): string
    {
        $t = WatchPdf::STYLES[$style] ?? PdfLatex::class;
        $e = static fn($value): string => PdfLayout::e($value);
        $today = date('Y-m-d');
        $outcomes = implode(' / ', array_map(static fn(string $label): string => mb_strtolower($label), Supervision::OUTCOMES));
        // The styles size columns to their content; unbreakable spaces keep the two columns that are filled in by hand wide enough to write in.
        $blank = static fn(int $width): string => str_repeat('&nbsp;', $width);
        $h = $t::begin()
            . $t::titleBlock(
                'Checklist Pemeriksaan Sarana dan Prasarana',
                ($context['printed_by'] ?? '') !== '' ? $e($context['printed_by']) : '',
                'Instrumen pemeriksaan: butir yang diperiksa pada tiap pemeriksaan',
                PdfDocuments::identity($context['documents'] ?? PdfDocuments::DEFAULTS, 'checklists', ['date' => $today], $today)
            )
            . $t::facts([
                'Jumlah checklist' => (string) count($templates),
                'Hasil tiap butir' => $e(ucfirst($outcomes)),
                'Dicetak oleh' => $e($context['printed_by'] ?? ''),
                'Tanggal' => PdfLayout::date($today),
            ]);
        if (!$templates) $h .= $t::paragraph('Belum ada checklist.');
        foreach ($templates as $template) {
            $items = is_array($template['items']) ? $template['items'] : Supervision::decode((string) $template['items']);
            $rows = [];
            foreach ($items as $n => $item) {
                $rows[] = [
                    (string) ($n + 1),
                    $e($item['group'] ?? ''),
                    '<b>' . $e($item['object'] ?? '') . '</b>',
                    $e($item['instruction'] ?? ''),
                    $blank(20),
                    $blank(26),
                ];
            }
            $h .= $t::section($template['name'] . ' (' . count($rows) . ' butir)')
                . $t::table('Butir ' . mb_strtolower((string) $template['name']), [['No.', 'r'], 'Kelompok', 'Objek', 'Petunjuk pemeriksaan', 'Hasil', 'Catatan'], $rows, 'Checklist ini belum memiliki butir.');
        }
        return $h;
    }
}
