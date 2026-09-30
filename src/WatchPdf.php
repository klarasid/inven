<?php
namespace SLiMS\Plugins\Inventory;

require_once __DIR__ . '/PdfLatex.php';
require_once __DIR__ . '/PdfIso.php';
require_once __DIR__ . '/PdfDocuments.php';

/**
 * Period report and inspection/damage-report documents. The content is written once and typeset by a
 * style class with a shared API: PdfLatex (LaTeX article) or PdfIso (ISO controlled document).
 */
final class WatchPdf
{
    private const KINDS = ['routine' => 'Terjadwal', 'incidental' => 'Insidental', 'historical' => 'Impor riwayat'];
    private const ACTIONS = ['repair' => 'Perbaikan', 'maintenance' => 'Pemeliharaan', 'none' => 'Tanpa pekerjaan'];
    private const EVENTS = ['report' => 'Kerusakan dilaporkan', 'progress' => 'Catatan perkembangan', 'import_history' => 'Riwayat diimpor', 'import_action' => 'Pekerjaan historis diimpor', 'import_verification' => 'Verifikasi historis', 'verify' => 'Verifikasi diterima', 'reject' => 'Dikembalikan', 'correction' => 'Catatan koreksi', 'finalize' => 'Pemeriksaan difinalisasi', 'start' => 'Pekerjaan dimulai', 'submit' => 'Diajukan untuk verifikasi', 'save_draft' => 'Draf disimpan', 'save_action' => 'Pekerjaan disimpan'];

    public const STYLES = ['latex' => PdfLatex::class, 'iso' => PdfIso::class];

    /** @return class-string<PdfLatex>|class-string<PdfIso> */
    private static function style(string $style): string { return self::STYLES[$style] ?? PdfLatex::class; }

    /** Configured mPDF for the chosen style. */
    public static function mpdf(string $tempDir, string $title, string $style = 'latex'): \Mpdf\Mpdf { return self::style($style)::mpdf($tempDir, $title); }

    private static function e($value): string { return PdfLayout::e($value); }

    private static function status(string $status): string { return self::e(Supervision::STATUSES[$status] ?? $status); }

    private static function outcome(string $outcome): string
    {
        $label = self::e(Supervision::OUTCOMES[$outcome] ?? 'Belum diisi');
        return $outcome === 'action' ? '<b>' . $label . '</b>' : ($outcome === 'good' ? $label : '<i>' . $label . '</i>');
    }

    private static function percent(int $value, int $total): string
    {
        return $total > 0 ? round($value / $total * 100) . '%' : '—';
    }

    private static function short($value): string { return PdfLayout::shortDate($value); }

    private static function sub(string $main, string $detail): string
    {
        return self::e($main) . ($detail !== '' ? '<br><span style="font-size:8.6pt;font-style:italic;">' . self::e($detail) . '</span>' : '');
    }

    /** Kept for callers that set the footer themselves; the LaTeX style uses a plain page number. */
    public static function footer(string $label = ''): string { return PdfLatex::footer(); }

    /**
     * @param array{library?:string,room?:string,printed_by?:string,documents?:array} $context display names for the filter and the preparer;
     *        documents = PdfDocuments settings (defaults when absent)
     */
    public static function summary(array $filter, array $summary, array $rows, array $context = [], string $style = 'latex'): string
    {
        $t = self::style($style);
        if (count($rows) > 500) throw new \RuntimeException('Laporan melebihi 500 pemeriksaan. Persempit periode.');
        $c = $summary['counts']; $f = $summary['findings'];
        $late = (int) $c['late'] + (int) $summary['unformed_late'];
        $scope = trim(implode(', ', array_filter([($context['library'] ?? '') ?: '', ($context['room'] ?? '') ?: ''])));
        $period = PdfLayout::date($filter['from']) . ' – ' . PdfLayout::date($filter['to']);
        $totalFindings = (int) $f['open'] + (int) $f['closed'];

        $h = $t::begin() . $t::titleBlock(
            'Laporan Pengawasan dan Pemeliharaan Sarana, Prasarana, dan Lingkungan Fisik Perpustakaan',
            ($context['printed_by'] ?? '') !== '' ? self::e($context['printed_by']) : '',
            'Periode ' . $period,
            PdfDocuments::identity($context['documents'] ?? PdfDocuments::DEFAULTS, 'period', ['date' => $filter['to'], 'from' => $filter['from'], 'to' => $filter['to']], date('Y-m-d'))
        );
        $h .= $t::abstract(
            'Laporan ini merangkum kegiatan pengawasan dan pemeliharaan ' . ($scope !== '' ? 'pada ' . self::e($scope) : 'di seluruh perpustakaan dan ruangan')
            . ' selama periode ' . $period . '. Tercatat <b>' . (int) $c['finalized'] . ' pemeriksaan</b> yang telah difinalisasi, terdiri atas '
            . (int) $c['routine_final'] . ' pemeriksaan terjadwal, ' . (int) $c['incidental'] . ' pemeriksaan insidental, dan ' . (int) ($c['historical'] ?? 0) . ' riwayat yang diimpor. '
            . ($late ? 'Sebanyak <b>' . $late . ' pemeriksaan terlambat</b> dari jadwal. ' : 'Tidak ada pemeriksaan yang terlambat dari jadwal. ')
            . 'Dari pemeriksaan tersebut muncul ' . $totalFindings . ' temuan yang memerlukan tindakan; '
            . (int) $f['closed'] . ' di antaranya telah selesai dan diverifikasi' . ((int) $f['open'] ? ', sedangkan ' . (int) $f['open'] . ' masih dalam proses' . ((int) $f['late'] ? ' (' . (int) $f['late'] . ' melewati tenggat)' : '') : '') . '.'
        );

        $h .= $t::section('Capaian dan Cakupan');
        $h .= $t::paragraph('Tabel 1 menyajikan realisasi pemeriksaan terhadap rencana. Cakupan butir menghitung pemeriksaan rutin yang terbentuk dan jadwal yang jatuh tempo; hanya hasil final <i>Baik</i> atau <i>Perlu tindakan</i> dihitung diperiksa, <i>Tidak diperiksa</i> tetap dalam penyebut, dan <i>Tidak berlaku</i> dikeluarkan. Status temuan adalah status pada saat laporan dicetak.');
        $h .= $t::table('Capaian pemeriksaan dan tindak lanjut', ['Indikator', ['Realisasi', 'r'], ['Dasar', 'r'], ['Capaian', 'r']], [
            ['Pemeriksaan rutin difinalisasi', (string) (int) $c['routine_final'], (string) (int) $summary['planned'], self::percent((int) $c['routine_final'], (int) $summary['planned'])],
            ['Ruangan diperiksa', (string) (int) $summary['room_examined'], (string) (int) $summary['room_total'], self::percent((int) $summary['room_examined'], (int) $summary['room_total'])],
            ['Butir pemeriksaan diperiksa', (string) (int) $summary['item_examined'], (string) (int) $summary['item_applicable'], self::percent((int) $summary['item_examined'], (int) $summary['item_applicable'])],
            ['Temuan selesai terverifikasi', (string) (int) $f['closed'], (string) $totalFindings, self::percent((int) $f['closed'], $totalFindings)],
            ['Pemeriksaan terlambat', (string) $late, '—', '—'],
            ['Jatuh tempo belum dibentuk', (string) (int) $summary['unformed'], '—', '—'],
            ['Butir tidak berlaku', (string) (int) $summary['item_na'], '—', '—'],
        ]);

        $h .= $t::section('Ruangan tanpa Jadwal Pemeriksaan');
        $missing = [];
        foreach ($summary['missing_rooms'] as $n => $room) $missing[] = [(string) ($n + 1), self::e($room['room_name']), self::e($room['location_name'] ?? 'Tidak ditentukan')];
        $h .= $missing
            ? $t::paragraph('Sebanyak ' . count($missing) . ' ruangan belum memiliki jadwal pemeriksaan rutin dalam periode ini, sebagaimana tercantum pada Tabel 2.')
                . $t::table('Ruangan tanpa jadwal pemeriksaan rutin', [['No.', 'r'], 'Ruangan', 'Perpustakaan'], $missing)
            : $t::paragraph('Semua ruangan memiliki jadwal pemeriksaan rutin dalam periode ini.');

        $h .= $t::section('Jadwal versus Realisasi');
        $schedules = [];
        foreach ($summary['schedules'] ?? [] as $n => $row) $schedules[] = [(string) ($n + 1), self::sub($row['room'], (string) $row['template']), self::e($row['frequency']), (string) (int) $row['planned'], (string) (int) $row['formed'], (string) (int) $row['final']];
        $h .= $t::table('Rencana jadwal dan pembentukan pemeriksaan', [['No.', 'r'], 'Ruangan / checklist', 'Frekuensi', ['Rencana', 'r'], ['Terbentuk', 'r'], ['Final', 'r']], $schedules, 'Tidak ada jadwal pemeriksaan rutin dalam periode ini.');
        $list = [];
        foreach ($rows as $n => $row) {
            $s = Supervision::decode($row['snapshot']);
            // Show the performed date only when it differs from the schedule, keeping the table to one line per row.
            $performed = $row['performed_date'] ? ($row['performed_date'] === $row['due_date'] ? '' : 'dilaksanakan ' . str_replace('&nbsp;', ' ', self::short($row['performed_date']))) : 'belum dilaksanakan';
            $list[] = [(string) ($n + 1), self::sub($s['room_name'], (string) $s['library_name']), self::e(self::KINDS[$row['kind']] ?? 'Pemeriksaan'), self::short($row['due_date']) . ($performed !== '' ? '<br><span style="font-size:8.2pt;font-style:italic;">' . $performed . '</span>' : ''), self::e($row['examiner_name'] ?? '—'), self::status((string) $row['status'])];
        }
        $h .= $t::table('Realisasi pemeriksaan', [['No.', 'r'], 'Ruangan', 'Jenis', 'Tanggal', 'Pemeriksa', 'Status'], $list, 'Tidak ada pemeriksaan dalam periode ini.', '8.8pt');

        $h .= $t::section('Temuan dan Tindak Lanjut');
        $findings = [];
        $overdueAny = false;
        foreach ($summary['finding_rows'] ?? [] as $n => $row) {
            $overdue = $row['status'] !== 'closed' && $row['deadline'] < date('Y-m-d');
            $overdueAny = $overdueAny || $overdue;
            $findings[] = [(string) ($n + 1), self::sub($row['object'], (string) $row['room']), self::e($row['assignee_name']), self::e(Supervision::PRIORITIES[$row['priority']] ?? $row['priority']), self::short($row['deadline']) . ($overdue ? '*' : ''), self::status((string) $row['status'])];
        }
        $h .= $t::table('Temuan dan status tindak lanjut', [['No.', 'r'], 'Temuan / ruangan', 'Penanggung jawab', 'Prioritas', 'Tenggat', 'Status'], $findings, 'Tidak ada temuan dalam periode ini.', '8.8pt');
        if ($overdueAny) $h .= '<p class="small">* Melewati tenggat pada saat laporan dicetak.</p>';

        return $h . $t::signatures([
            ['Mengetahui,', 'Kepala Perpustakaan', ''],
            ['Disusun oleh,', 'Petugas Pengelola', (string) ($context['printed_by'] ?? '')],
        ], '...................., ' . PdfLayout::date(new \DateTimeImmutable('now')));
    }

    /** Downscaled JPEG data URIs for the photos of one result or one action. */
    private static function photos(array $document, callable $readPhoto, ?int $result, ?int $action): array
    {
        $images = [];
        foreach ($document['photos'] as $photo) {
            if (($result !== null && (int) $photo['result_id'] !== $result) || ($action !== null && (int) $photo['action_id'] !== $action)) continue;
            $bytes = $readPhoto($photo);
            $image = $bytes === null ? false : @imagecreatefromstring($bytes);
            if (!$image) continue;
            $scale = min(1, 640 / max(imagesx($image), imagesy($image)));
            $thumb = imagecreatetruecolor(max(1, (int) (imagesx($image) * $scale)), max(1, (int) (imagesy($image) * $scale)));
            imagecopyresampled($thumb, $image, 0, 0, 0, 0, imagesx($thumb), imagesy($thumb), imagesx($image), imagesy($image));
            ob_start(); imagejpeg($thumb, null, 80); $jpeg = ob_get_clean(); imagedestroy($thumb); imagedestroy($image);
            $images[] = 'data:image/jpeg;base64,' . base64_encode($jpeg);
        }
        return $images;
    }

    /** @param array|null $documents PdfDocuments settings (defaults when null) */
    public static function detail(array $document, callable $readPhoto, string $style = 'latex', ?array $documents = null): string
    {
        $t = self::style($style);
        $i = $document['inspection']; $s = $document['snapshot'];
        if (count($document['photos']) > 500) throw new \RuntimeException('Detail memiliki lebih dari 500 foto. Hubungi administrator untuk ekspor arsip.');
        $report = empty($s['template_id']) && ($s['template_name'] ?? '') === 'Laporan kerusakan';
        $identity = PdfDocuments::identity($documents ?? PdfDocuments::DEFAULTS, $report ? 'report' : 'inspection', ['id' => $i['id'], 'date' => $i['performed_date'] ?: $i['due_date']], (string) ($i['finalized_at'] ?? $i['performed_date'] ?? $i['due_date']));
        $number = $identity['number'];
        $h = $t::begin() . $t::titleBlock(
            $report ? 'Laporan Kerusakan dan Tindak Lanjut' : 'Dokumen Pemeriksaan Ruangan',
            'Nomor ' . self::e($number),
            self::e($s['room_name']) . ', ' . self::e($s['library_name']),
            $identity
        );
        $facts = [
            'Ruangan' => self::e($s['room_name']),
            'Perpustakaan' => self::e($s['library_name']),
            $report ? 'Jenis' : 'Checklist' => self::e($report ? 'Laporan kerusakan' : $s['template_name']),
            'Kegiatan' => self::e(self::KINDS[$i['kind']] ?? 'Pemeriksaan'),
            $report ? 'Tanggal lapor' : 'Jadwal' => PdfLayout::date($i['due_date']),
            'Pelaksanaan' => PdfLayout::date($i['performed_date']),
            $report ? 'Pelapor' : 'Pemeriksa' => self::e($i['examiner_name'] ?? '—'),
            'Status' => self::status((string) $i['status']),
        ];
        if ($i['parent_id']) $facts['Pemeriksaan asal'] = 'PMR-' . str_pad((string) (int) $i['parent_id'], 5, '0', STR_PAD_LEFT);
        $h .= $t::facts($facts);

        $tally = array_fill_keys(array_keys(Supervision::OUTCOMES), 0);
        foreach ($document['results'] as $r) if (isset($tally[$r['outcome']])) $tally[$r['outcome']]++;
        $total = count($document['results']);
        $findingCount = count($document['findings']);
        $closed = count(array_filter($document['findings'], fn($f) => $f['status'] === 'closed'));
        $h .= $t::abstract($report
            ? 'Kerusakan dilaporkan oleh ' . self::e($i['examiner_name'] ?? '—') . ' pada ' . PdfLayout::date($i['due_date']) . ' di ' . self::e($s['room_name']) . '. '
                . ($findingCount && $closed === $findingCount ? 'Kerusakan telah ditangani dan hasilnya diverifikasi.' : 'Tindak lanjut masih dalam proses.')
            : 'Pemeriksaan ' . strtolower(self::KINDS[$i['kind']] ?? '') . ' ruangan ' . self::e($s['room_name']) . ' dilaksanakan pada ' . PdfLayout::date($i['performed_date'] ?: $i['due_date'])
                . ' oleh ' . self::e($i['examiner_name'] ?? '—') . ' terhadap ' . $total . ' butir: ' . $tally['good'] . ' baik, ' . $tally['action'] . ' perlu tindakan, '
                . $tally['unchecked'] . ' tidak diperiksa, dan ' . $tally['na'] . ' tidak berlaku. '
                . ($findingCount ? $findingCount . ' temuan ditindaklanjuti, ' . $closed . ' di antaranya telah selesai dan diverifikasi.' : 'Tidak ada temuan yang memerlukan tindakan.')
        );
        if (trim((string) $i['reason']) !== '' && !$report) $h .= $t::paragraph('<i>Alasan pemeriksaan.</i> ' . nl2br(self::e($i['reason'])));
        if (trim((string) $i['notes']) !== '') $h .= $t::paragraph('<i>Catatan pemeriksaan.</i> ' . nl2br(self::e($i['notes'])));

        $h .= $t::section($report ? 'Kerusakan yang Dilaporkan' : 'Hasil Pemeriksaan');
        $rows = []; $figures = [];
        foreach ($document['results'] as $n => $r) {
            $item = Supervision::decode($r['snapshot']);
            $rows[] = [(string) ($n + 1), self::e($item['group']), self::sub($item['object'], $item['item_id'] ? $item['item_name'] . ($item['item_code'] ? ' · ' . $item['item_code'] : '') : 'Aspek ruangan'), self::outcome((string) $r['outcome']), nl2br(self::e($r['notes'])) ?: '—'];
            foreach (self::photos($document, $readPhoto, (int) $r['id'], null) as $src) $figures[] = [$src, 'Butir ' . ($n + 1) . ', ' . $item['object']];
        }
        $h .= $t::table($report ? 'Uraian kerusakan' : 'Hasil pemeriksaan per butir', [['No.', 'r'], 'Kelompok', 'Objek', 'Hasil', 'Catatan'], $rows);
        $h .= $t::figures($figures);

        $h .= $t::section('Temuan dan Tindak Lanjut');
        if (!$document['findings']) $h .= $t::paragraph('Tidak ada temuan yang memerlukan tindakan.');
        foreach ($document['findings'] as $f) {
            $result = null;
            foreach ($document['results'] as $r) if ((int) $r['id'] === (int) $f['result_id']) $result = Supervision::decode($r['snapshot']);
            $h .= $t::subsection((string) ($result['object'] ?? 'Temuan'));
            $h .= $t::paragraph('Penanggung jawab ' . self::e($f['assignee_name']) . ', prioritas ' . strtolower(self::e(Supervision::PRIORITIES[$f['priority']] ?? $f['priority']))
                . ', tenggat ' . PdfLayout::date($f['deadline']) . '. Status: <i>' . strtolower(self::status((string) $f['status'])) . '</i>' . ($f['closed_at'] ? ' pada ' . PdfLayout::date($f['closed_at']) : '') . '.');
            $actions = array_values(array_filter($document['actions'], fn($a) => (int) $a['finding_id'] === (int) $f['id']));
            $h .= $t::table('Tindakan atas temuan ' . ($result['object'] ?? ''), ['Tanggal', 'Tindakan', 'Uraian', 'Pelaksana', ['Biaya', 'r']], array_map(fn($a) => [
                self::short($a['performed_date']),
                self::e(self::ACTIONS[$a['kind']] ?? $a['kind']) . ($a['submitted_at'] ? '' : ' <i>(draf)</i>'),
                nl2br(self::e($a['description'])),
                self::e($a['actor_name']),
                PdfLayout::money($a['cost']),
            ], $actions), 'Belum ada pekerjaan yang dicatat.');
            $figures = [];
            foreach ($actions as $a) foreach (self::photos($document, $readPhoto, null, (int) $a['id']) as $src) $figures[] = [$src, 'Hasil ' . strtolower(self::ACTIONS[$a['kind']] ?? '') . ', ' . PdfLayout::date($a['performed_date'])];
            $h .= $t::figures($figures);
        }

        $h .= $t::section('Riwayat Kegiatan dan Verifikasi');
        $h .= $t::table('Riwayat kegiatan', ['Waktu', 'Kegiatan', 'Oleh', 'Catatan'], array_map(fn($e) => [
            self::short($e['created_at']) . '<br><span style="font-size:8.4pt;">' . self::e(substr((string) $e['created_at'], 11, 5)) . '</span>',
            self::e(self::EVENTS[$e['event']] ?? $e['event']),
            self::e($e['actor_name']),
            nl2br(self::e($e['notes'])),
        ], $document['events']), 'Belum ada kegiatan tercatat.', '8.8pt');

        $verifier = '';
        foreach (array_reverse($document['events']) as $e) if (in_array($e['event'], ['verify', 'import_verification'], true)) { $verifier = $e['actor_name']; break; }
        return $h . $t::signatures([
            [$report ? 'Pelapor,' : 'Pemeriksa,', '', (string) ($i['examiner_name'] ?? '')],
            [$verifier !== '' ? 'Diverifikasi oleh,' : 'Mengetahui,', $verifier !== '' ? '' : 'Kepala Perpustakaan', $verifier],
        ], '...................., ' . PdfLayout::date($i['performed_date'] ?: $i['due_date']));
    }
}
