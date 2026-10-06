<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

require_once __DIR__ . '/PdfLayout.php';
require_once __DIR__ . '/Supervision.php';
require_once __DIR__ . '/WatchRecurrence.php';

/**
 * Two sheets of the supervision a library plans, as documents of their own: the schedule of
 * routine inspections, and the checklists the inspections are filled in on. The period report
 * and an inspection's document show them at work; these show what was set up.
 */
final class WatchSheets
{
    /**
     * @param  list<array<string,mixed>>  $schedules  schedule rows, their snapshot decoded
     * @param  array{printed_by?:string,rooms?:int}  $context  `rooms`: how many rooms the library has
     */
    public static function schedules(array $schedules, array $context = []): string
    {
        $e = static fn($value): string => PdfLayout::e($value);
        $rows = [];
        $scheduled = [];
        foreach ($schedules as $n => $schedule) {
            $snapshot = is_array($schedule['snapshot']) ? $schedule['snapshot'] : Supervision::decode((string) $schedule['snapshot']);
            $scheduled[(int) $schedule['location_id']] = true;
            $library = trim((string) ($snapshot['library_name'] ?? ''));
            $rows[] = [
                (string) ($n + 1),
                '<span class="strong">' . $e($snapshot['room_name'] ?? '') . '</span>' . ($library !== '' ? '<br><span class="muted small">' . $e($library) . '</span>' : ''),
                $e($snapshot['template_name'] ?? ''),
                $e(WatchRecurrence::FREQUENCIES[$schedule['frequency']] ?? $schedule['frequency']),
                PdfLayout::date($schedule['start_date']),
                $schedule['end_date'] === null ? 'Tanpa batas' : PdfLayout::date($schedule['end_date']),
                $e($schedule['assignee_name']),
            ];
        }
        $rooms = (int) ($context['rooms'] ?? 0);
        return PdfLayout::css()
            . PdfLayout::header('JADWAL PEMERIKSAAN SARANA DAN PRASARANA', 'Jadwal pemeriksaan rutin yang sedang berjalan')
            . PdfLayout::meta([
                'Jumlah jadwal' => (string) count($rows),
                'Ruangan terjadwal' => count($scheduled) . ($rooms > 0 ? ' dari ' . $rooms . ' ruangan' : ''),
                'Dicetak oleh' => $e($context['printed_by'] ?? ''),
                'Tanggal' => PdfLayout::date(new \DateTimeImmutable('now')),
            ])
            . PdfLayout::table([['No.', 'no'], 'Ruangan', 'Checklist', 'Frekuensi', 'Mulai', 'Berakhir', 'Petugas'], $rows, 'Belum ada jadwal pemeriksaan rutin yang berjalan.')
            . '<p class="small muted">Tanggal pemeriksaan yang jatuh pada hari libur digeser ke hari kerja terdekat.</p>'
            . PdfLayout::signatures([
                ['Mengetahui,', 'Kepala Perpustakaan', ''],
                ['Disusun oleh,', 'Petugas Pengelola', (string) ($context['printed_by'] ?? '')],
            ], '...................., ' . PdfLayout::date(new \DateTimeImmutable('now')));
    }

    /**
     * Each checklist as the form an examiner fills in: its items by group, with room for the
     * outcome and a note.
     *
     * @param  list<array<string,mixed>>  $templates  checklist rows, their items decoded
     * @param  array{printed_by?:string}  $context
     */
    public static function checklists(array $templates, array $context = []): string
    {
        $e = static fn($value): string => PdfLayout::e($value);
        $outcomes = implode(' / ', array_map(static fn(string $label): string => mb_strtolower($label), Supervision::OUTCOMES));
        $h = PdfLayout::css('.grid td.fill{width:34mm;}.grid td.note{width:40mm;}')
            . PdfLayout::header('CHECKLIST PEMERIKSAAN SARANA DAN PRASARANA', 'Instrumen pemeriksaan: butir yang diperiksa pada tiap pemeriksaan')
            . PdfLayout::meta([
                'Jumlah checklist' => (string) count($templates),
                'Hasil tiap butir' => $e(ucfirst($outcomes)),
                'Dicetak oleh' => $e($context['printed_by'] ?? ''),
                'Tanggal' => PdfLayout::date(new \DateTimeImmutable('now')),
            ]);
        if (!$templates) $h .= '<p class="muted">Belum ada checklist.</p>';
        foreach ($templates as $template) {
            $items = is_array($template['items']) ? $template['items'] : Supervision::decode((string) $template['items']);
            $rows = [];
            foreach ($items as $n => $item) {
                $rows[] = [
                    (string) ($n + 1),
                    $e($item['group'] ?? ''),
                    '<span class="strong">' . $e($item['object'] ?? '') . '</span>',
                    $e($item['instruction'] ?? ''),
                    '',
                    '',
                ];
            }
            $h .= '<h2>' . $e($template['name']) . ' (' . count($rows) . ' butir)</h2>'
                . PdfLayout::table([['No.', 'no'], 'Kelompok', 'Objek', 'Petunjuk pemeriksaan', ['Hasil', 'fill'], ['Catatan', 'note']], $rows, 'Checklist ini belum memiliki butir.');
        }
        return $h;
    }
}
