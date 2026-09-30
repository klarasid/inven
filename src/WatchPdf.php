<?php
namespace SLiMS\Plugins\Inventory;

require_once __DIR__ . '/PdfLayout.php';

final class WatchPdf
{
    private const KINDS = ['routine' => 'Terjadwal', 'incidental' => 'Insidental', 'historical' => 'Impor riwayat'];
    private const ACTIONS = ['repair' => 'Perbaikan', 'maintenance' => 'Pemeliharaan', 'none' => 'Tanpa pekerjaan'];
    private const EVENTS = ['report' => 'Kerusakan dilaporkan', 'progress' => 'Catatan perkembangan', 'import_history' => 'Riwayat diimpor', 'import_action' => 'Pekerjaan historis diimpor', 'import_verification' => 'Verifikasi historis', 'verify' => 'Verifikasi diterima', 'reject' => 'Dikembalikan', 'correction' => 'Catatan koreksi', 'finalize' => 'Pemeriksaan difinalisasi', 'start' => 'Pekerjaan dimulai', 'submit' => 'Diajukan untuk verifikasi', 'save_draft' => 'Draf disimpan', 'save_action' => 'Pekerjaan disimpan'];

    private static function e($value): string { return PdfLayout::e($value); }

    private static function status(string $status): string
    {
        $tone = in_array($status, ['final', 'closed'], true) ? 't-good' : (in_array($status, ['open', 'review'], true) ? 't-warn' : 't-muted');
        return '<span class="tag ' . $tone . '">' . self::e(Supervision::STATUSES[$status] ?? $status) . '</span>';
    }

    private static function outcome(string $outcome): string
    {
        $tone = ['good' => 't-good', 'action' => 't-bad', 'unchecked' => 't-warn', 'na' => 't-muted'][$outcome] ?? 't-muted';
        return '<span class="tag ' . $tone . '">' . self::e(Supervision::OUTCOMES[$outcome] ?? 'Belum diisi') . '</span>';
    }

    private static function percent(int $value, int $total): string
    {
        return $total > 0 ? round($value / $total * 100) . '%' : '—';
    }

    public static function footer(string $label): string { return PdfLayout::footer($label); }

    /** @param array{library?:string,room?:string,printed_by?:string} $context display names for the filter and the preparer */
    public static function summary(array $filter, array $summary, array $rows, array $context = []): string
    {
        if (count($rows) > 500) throw new \RuntimeException('Laporan melebihi 500 pemeriksaan. Persempit periode.');
        $c = $summary['counts']; $f = $summary['findings'];
        $late = (int) $c['late'] + (int) $summary['unformed_late'];
        $h = PdfLayout::css() . PdfLayout::header(
            'LAPORAN PENGAWASAN DAN PEMELIHARAAN',
            'Sarana, prasarana, dan lingkungan fisik perpustakaan · Periode ' . PdfLayout::date($filter['from']) . ' – ' . PdfLayout::date($filter['to']),
            'Laporan periode',
            date('Ymd', strtotime((string) $filter['from'])) . '–' . date('Ymd', strtotime((string) $filter['to']))
        );
        $h .= PdfLayout::meta([
            'Perpustakaan' => self::e(($context['library'] ?? '') ?: 'Semua perpustakaan'),
            'Ruangan' => self::e(($context['room'] ?? '') ?: 'Semua ruangan'),
            'Periode' => PdfLayout::date($filter['from']) . ' s.d. ' . PdfLayout::date($filter['to']),
            'Disusun oleh' => self::e(($context['printed_by'] ?? '') ?: '—'),
        ]);
        $h .= PdfLayout::kpis([
            ['Pemeriksaan selesai', (int) $c['finalized'], $c['incidental'] . ' insidental · ' . ($c['historical'] ?? 0) . ' impor'],
            ['Pemeriksaan terlambat', $late, $summary['unformed'] . ' jatuh tempo belum dibentuk'],
            ['Temuan belum selesai', (int) $f['open'], $f['late'] . ' lewat tenggat'],
            ['Temuan terverifikasi', (int) $f['closed'], 'selesai ditindaklanjuti'],
        ]);

        $h .= '<h2>I. Capaian dan cakupan</h2>' . PdfLayout::table(
            ['Indikator', ['Realisasi', 'num'], ['Target / dasar', 'num'], ['Capaian', 'num']],
            [
                ['Pemeriksaan rutin difinalisasi', (string) (int) $c['routine_final'], (string) (int) $summary['planned'], self::percent((int) $c['routine_final'], (int) $summary['planned'])],
                ['Ruangan diperiksa', (string) (int) $summary['room_examined'], (string) (int) $summary['room_total'], self::percent((int) $summary['room_examined'], (int) $summary['room_total'])],
                ['Butir pemeriksaan diperiksa', (string) (int) $summary['item_examined'], (string) (int) $summary['item_applicable'], self::percent((int) $summary['item_examined'], (int) $summary['item_applicable'])],
                ['Temuan selesai terverifikasi', (string) (int) $f['closed'], (string) ((int) $f['closed'] + (int) $f['open']), self::percent((int) $f['closed'], (int) $f['closed'] + (int) $f['open'])],
                ['Butir tidak berlaku (dikeluarkan)', (string) (int) $summary['item_na'], '—', '—'],
                ['Pemeriksaan insidental', (string) (int) $c['incidental'], '—', '—'],
            ]
        );
        $h .= '<div class="note">Cakupan butir menghitung pemeriksaan rutin yang terbentuk dan jadwal yang jatuh tempo. Hanya hasil final <b>Baik</b> atau <b>Perlu tindakan</b> dihitung diperiksa; <b>Tidak diperiksa</b> tetap dalam penyebut, <b>Tidak berlaku</b> dikeluarkan. Status temuan adalah status pada saat laporan dicetak. Laporan ini menyajikan bukti pengawasan dan tidak memberikan nilai akreditasi otomatis.</div>';

        $h .= '<h2>II. Ruangan tanpa jadwal pemeriksaan</h2>';
        $missing = [];
        foreach ($summary['missing_rooms'] as $n => $room) $missing[] = [(string) ($n + 1), self::e($room['room_name']), self::e($room['location_name'] ?? 'Tidak ditentukan')];
        $h .= PdfLayout::table([['No', 'no'], 'Ruangan', 'Perpustakaan'], $missing, 'Semua ruangan memiliki jadwal pemeriksaan dalam periode ini.');

        $h .= '<h2>III. Rencana jadwal</h2>';
        $schedules = [];
        foreach ($summary['schedules'] ?? [] as $n => $row) $schedules[] = [(string) ($n + 1), '<b>' . self::e($row['room']) . '</b><br><span class="muted small">' . self::e($row['template']) . '</span>', self::e($row['frequency']), (string) (int) $row['planned'], (string) (int) $row['formed'], (string) (int) $row['final']];
        $h .= PdfLayout::table([['No', 'no'], 'Ruangan / checklist', 'Frekuensi', ['Rencana', 'num'], ['Terbentuk', 'num'], ['Final', 'num']], $schedules, 'Tidak ada jadwal rutin dalam periode ini.');

        $h .= '<h2>IV. Realisasi pemeriksaan</h2>';
        $list = [];
        foreach ($rows as $n => $row) {
            $s = Supervision::decode($row['snapshot']);
            $list[] = [(string) ($n + 1), '<b>' . self::e($s['room_name']) . '</b><br><span class="muted small">' . self::e($s['library_name']) . '</span>', self::e(self::KINDS[$row['kind']] ?? 'Pemeriksaan'), PdfLayout::shortDate($row['due_date']), $row['performed_date'] ? PdfLayout::shortDate($row['performed_date']) : '<span class="muted">Belum</span>', self::e($row['examiner_name'] ?? '—'), self::status((string) $row['status'])];
        }
        $h .= PdfLayout::table([['No', 'no'], 'Ruangan', 'Jenis', 'Jadwal', 'Pelaksanaan', 'Pemeriksa', 'Status'], $list, 'Tidak ada pemeriksaan dalam periode ini.');

        $h .= '<h2>V. Temuan dan tindak lanjut</h2>';
        $findings = [];
        foreach ($summary['finding_rows'] ?? [] as $n => $row) {
            $overdue = $row['status'] !== 'closed' && $row['deadline'] < date('Y-m-d');
            $findings[] = [(string) ($n + 1), '<b>' . self::e($row['object']) . '</b><br><span class="muted small">' . self::e($row['room']) . '</span>', self::e($row['assignee_name']), self::e(Supervision::PRIORITIES[$row['priority']] ?? $row['priority']), PdfLayout::shortDate($row['deadline']) . ($overdue ? '<br><span class="tag t-bad">Lewat tenggat</span>' : ''), self::status((string) $row['status'])];
        }
        $h .= PdfLayout::table([['No', 'no'], 'Temuan / ruangan', 'Penanggung jawab', 'Prioritas', 'Tenggat', 'Status'], $findings, 'Tidak ada temuan dalam periode ini.');

        $h .= PdfLayout::signatures([
            ['Mengetahui,', 'Kepala Perpustakaan', '', ''],
            ['Disusun oleh,', 'Petugas Pengelola', (string) ($context['printed_by'] ?? ''), ''],
        ], '...................., ' . PdfLayout::date(new \DateTimeImmutable('now')));
        return $h;
    }

    /** Photos as a captioned grid, three per row, downscaled so large documents stay light. */
    private static function photoGrid(array $document, callable $readPhoto, ?int $result, ?int $action, string $caption): string
    {
        $cells = [];
        foreach ($document['photos'] as $photo) {
            if (($result !== null && (int) $photo['result_id'] !== $result) || ($action !== null && (int) $photo['action_id'] !== $action)) continue;
            $bytes = $readPhoto($photo);
            $image = $bytes === null ? false : @imagecreatefromstring($bytes);
            if (!$image) { $cells[] = '<span class="muted">Foto tidak tersedia.</span>'; continue; }
            $scale = min(1, 480 / max(imagesx($image), imagesy($image)));
            $thumb = imagecreatetruecolor(max(1, (int) (imagesx($image) * $scale)), max(1, (int) (imagesy($image) * $scale)));
            imagecopyresampled($thumb, $image, 0, 0, 0, 0, imagesx($thumb), imagesy($thumb), imagesx($image), imagesy($image));
            ob_start(); imagejpeg($thumb, null, 78); $jpeg = ob_get_clean(); imagedestroy($thumb); imagedestroy($image);
            $cells[] = '<img src="data:image/jpeg;base64,' . base64_encode($jpeg) . '" style="width:52mm;border:0.2mm solid #d1d5db;"><br>'
                . '<span style="font-size:6.8pt;color:#6b7280;">Foto ' . (count($cells) + 1) . ($caption !== '' ? ' · ' . $caption : '') . '</span>';
        }
        if (!$cells) return '';
        $html = '<table class="photos">';
        foreach (array_chunk($cells, 3) as $row) $html .= '<tr><td style="padding:0 3mm 3mm 0;vertical-align:top;">' . implode('</td><td style="padding:0 3mm 3mm 0;vertical-align:top;">', $row) . '</td></tr>';
        return $html . '</table>';
    }

    public static function detail(array $document, callable $readPhoto): string
    {
        $i = $document['inspection']; $s = $document['snapshot'];
        if (count($document['photos']) > 500) throw new \RuntimeException('Detail memiliki lebih dari 500 foto. Hubungi administrator untuk ekspor arsip.');
        $report = empty($s['template_id']) && ($s['template_name'] ?? '') === 'Laporan kerusakan';
        $number = ($report ? 'LK-' : 'PMR-') . str_pad((string) (int) $i['id'], 5, '0', STR_PAD_LEFT);
        $h = PdfLayout::css() . PdfLayout::header(
            $report ? 'LAPORAN KERUSAKAN DAN TINDAK LANJUT' : 'DOKUMEN PEMERIKSAAN RUANGAN',
            self::e($s['room_name']) . ' · ' . self::e($s['library_name']),
            $report ? 'Nomor laporan' : 'Nomor dokumen',
            $number
        );
        $pairs = [
            'Ruangan' => self::e($s['room_name']),
            'Perpustakaan' => self::e($s['library_name']),
            $report ? 'Jenis' : 'Checklist' => self::e($report ? 'Laporan kerusakan' : $s['template_name']),
            'Jenis kegiatan' => self::e(self::KINDS[$i['kind']] ?? 'Pemeriksaan'),
            $report ? 'Tanggal laporan' : 'Jadwal' => PdfLayout::date($i['due_date']),
            'Pelaksanaan' => PdfLayout::date($i['performed_date']),
            $report ? 'Pelapor' : 'Pemeriksa' => self::e($i['examiner_name'] ?? '—'),
            'Status' => self::status((string) $i['status']),
        ];
        if ($i['parent_id']) $pairs['Pemeriksaan asal'] = 'PMR-' . str_pad((string) (int) $i['parent_id'], 5, '0', STR_PAD_LEFT);
        $h .= PdfLayout::meta($pairs);
        if (trim((string) $i['reason']) !== '' && !$report) $h .= '<div class="note"><b>Alasan pemeriksaan:</b> ' . nl2br(self::e($i['reason'])) . '</div>';
        if (trim((string) $i['notes']) !== '') $h .= '<div class="note"><b>Catatan pemeriksaan:</b> ' . nl2br(self::e($i['notes'])) . '</div>';

        $tally = array_fill_keys(array_keys(Supervision::OUTCOMES), 0);
        foreach ($document['results'] as $r) if (isset($tally[$r['outcome']])) $tally[$r['outcome']]++;
        if (!$report) {
            $kpis = [];
            foreach (Supervision::OUTCOMES as $key => $label) $kpis[] = [$label, $tally[$key]];
            $h .= PdfLayout::kpis($kpis);
        }

        $h .= '<h2>' . ($report ? 'I. Kerusakan yang dilaporkan' : 'I. Hasil pemeriksaan') . '</h2>';
        $rows = []; $photoBlocks = '';
        foreach ($document['results'] as $n => $r) {
            $item = Supervision::decode($r['snapshot']);
            $object = '<b>' . self::e($item['object']) . '</b>'
                . ($item['item_id'] ? '<br><span class="muted small">' . self::e($item['item_name']) . ($item['item_code'] ? ' · ' . self::e($item['item_code']) : '') . '</span>' : '<br><span class="muted small">Aspek ruangan</span>');
            $rows[] = [(string) ($n + 1), self::e($item['group']), $object, self::outcome((string) $r['outcome']), nl2br(self::e($r['notes'])) ?: '<span class="muted">—</span>'];
            $grid = self::photoGrid($document, $readPhoto, (int) $r['id'], null, '');
            if ($grid !== '') $photoBlocks .= '<h3>Butir ' . ($n + 1) . ' · ' . self::e($item['object']) . '</h3>' . $grid;
        }
        $h .= PdfLayout::table([['No', 'no'], 'Kelompok', 'Objek', 'Hasil', 'Catatan'], $rows);
        if ($photoBlocks !== '') $h .= '<h2>Lampiran foto ' . ($report ? 'kerusakan' : 'pemeriksaan') . '</h2>' . $photoBlocks;

        $h .= '<h2>II. Temuan dan tindak lanjut</h2>';
        if (!$document['findings']) $h .= '<p class="muted">Tidak ada temuan yang memerlukan tindakan.</p>';
        foreach ($document['findings'] as $n => $f) {
            $result = null;
            foreach ($document['results'] as $r) if ((int) $r['id'] === (int) $f['result_id']) $result = Supervision::decode($r['snapshot']);
            $h .= '<div class="card"><h3 style="margin-top:0">Temuan ' . ($n + 1) . ' · ' . self::e($result['object'] ?? '') . '</h3>'
                . PdfLayout::meta([
                    'Penanggung jawab' => self::e($f['assignee_name']),
                    'Prioritas' => self::e(Supervision::PRIORITIES[$f['priority']] ?? $f['priority']),
                    'Tenggat' => PdfLayout::date($f['deadline']),
                    'Status' => self::status((string) $f['status']) . ($f['closed_at'] ? ' <span class="muted small">(' . PdfLayout::date($f['closed_at']) . ')</span>' : ''),
                ]);
            $actions = [];
            foreach ($document['actions'] as $a) {
                if ((int) $a['finding_id'] !== (int) $f['id']) continue;
                $actions[] = $a;
            }
            $h .= PdfLayout::table(['Tanggal', 'Tindakan', 'Uraian', 'Pelaksana', ['Biaya', 'num']], array_map(fn($a) => [
                PdfLayout::shortDate($a['performed_date']),
                self::e(self::ACTIONS[$a['kind']] ?? $a['kind']) . ($a['submitted_at'] ? '' : '<br><span class="muted small">Draf</span>'),
                nl2br(self::e($a['description'])),
                self::e($a['actor_name']),
                PdfLayout::money($a['cost']),
            ], $actions), 'Belum ada pekerjaan yang dicatat.');
            foreach ($actions as $a) $h .= self::photoGrid($document, $readPhoto, null, (int) $a['id'], 'hasil ' . strtolower(self::ACTIONS[$a['kind']] ?? '') . ', ' . str_replace('&nbsp;', ' ', PdfLayout::shortDate($a['performed_date'])));
            $h .= '</div>';
        }

        $h .= '<h2>III. Riwayat kegiatan dan verifikasi</h2>';
        $h .= PdfLayout::table(['Waktu', 'Kegiatan', 'Oleh', 'Catatan'], array_map(fn($e) => [
            PdfLayout::shortDate($e['created_at']) . '<br><span class="muted small">' . self::e(substr((string) $e['created_at'], 11, 5)) . '</span>',
            '<b>' . self::e(self::EVENTS[$e['event']] ?? $e['event']) . '</b>',
            self::e($e['actor_name']),
            nl2br(self::e($e['notes'])),
        ], $document['events']), 'Belum ada kegiatan tercatat.');

        $verifier = '';
        foreach (array_reverse($document['events']) as $e) if (in_array($e['event'], ['verify', 'import_verification'], true)) { $verifier = $e['actor_name']; break; }
        $h .= PdfLayout::signatures([
            [$report ? 'Pelapor,' : 'Pemeriksa,', '', (string) ($i['examiner_name'] ?? ''), ''],
            [$verifier !== '' ? 'Diverifikasi oleh,' : 'Mengetahui,', $verifier !== '' ? '' : 'Kepala Perpustakaan', $verifier, ''],
        ], '...................., ' . PdfLayout::date($i['performed_date'] ?: $i['due_date']));
        return $h;
    }
}
