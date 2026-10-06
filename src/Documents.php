<?php

namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

/**
 * The PDFs the Klaras InvenSync app shares: a room's KIR, item labels, the period report and
 * a stock take's missing list. Built from the same templates as the admin pages, returned as
 * bytes so the API can send them with its own headers.
 */
final class Documents
{
    public const MAX_ROWS = 500;

    public function __construct(private PDO $db, private string $tempDir) {}

    public static function ensureRuntime(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
        if (!class_exists(\Mpdf\Mpdf::class)) {
            throw new RuntimeException('Pembuat PDF belum terpasang di SLiMS. Minta administrator memasang ulang plugin Inventaris Barang.');
        }
    }

    private static function slug(string $text, string $fallback): string
    {
        return strtolower(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $text), '-')) ?: $fallback;
    }

    /** @return array<string, mixed> */
    private function room(int $locationId): array
    {
        $statement = $this->db->prepare('SELECT l.*, ml.location_name AS slims_location_name FROM inventory_locations l LEFT JOIN mst_location ml ON ml.location_id = l.slims_location_id WHERE l.id = ?');
        $statement->execute([$locationId]);
        $room = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$room) {
            throw new RuntimeException('Ruangan tidak ditemukan.');
        }
        return $room;
    }

    /**
     * Kartu Inventaris Ruangan, classic or modern layout.
     *
     * @return array{filename: string, bytes: string, count: int}
     */
    public function kir(int $locationId, bool $modern, string $author): array
    {
        self::ensureRuntime();
        $location = $this->room($locationId);
        $statement = $this->db->prepare('SELECT * FROM inventory_items WHERE location_id = ? ORDER BY item_name, item_code, id LIMIT ' . (self::MAX_ROWS + 1));
        $statement->execute([$locationId]);
        $items = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($items) > self::MAX_ROWS) {
            throw new RuntimeException('Ruangan memuat lebih dari 500 barang. Cetak KIR dari SLiMS.');
        }
        $title = 'Kartu Inventaris Ruangan - ' . $location['room_name'];
        if ($modern) {
            require_once __DIR__ . '/PdfTemplateModern.php';
            $html = PdfTemplateModern::render($location, $items);
            $pdf = PdfLayout::mpdf($this->tempDir, $title, PdfTemplateModern::footer($location), ['format' => [330, 216], 'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 9, 'margin_bottom' => 13, 'margin_footer' => 6, 'exposeVersion' => false]);
        } else {
            require_once __DIR__ . '/PdfTemplate.php';
            $html = PdfTemplate::render($location, $items);
            $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => [330, 216], 'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 4, 'margin_bottom' => 7, 'tempDir' => $this->tempDir, 'exposeVersion' => false]);
            $pdf->SetTitle($title);
            $pdf->SetAuthor($author);
        }
        $pdf->WriteHTML($html);
        Telemetry::count('kir_pdf');

        return ['filename' => 'kartu-inventaris-' . self::slug((string) $location['room_name'], 'ruangan') . '.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => count($items)];
    }

    /**
     * Labels with the signed public QR link, for a whole room or the given items in it.
     *
     * @param  list<int>  $ids
     * @return array{filename: string, bytes: string, count: int}
     */
    public function labels(int $locationId, array $ids, string $preset, string $siteUrl): array
    {
        self::ensureRuntime();
        if (!class_exists(\Mpdf\QrCode\QrCode::class)) {
            throw new RuntimeException('Pembuat kode QR belum terpasang di SLiMS. Minta administrator memasang ulang plugin Inventaris Barang.');
        }
        require_once __DIR__ . '/LabelSheet.php';
        require_once __DIR__ . '/PublicLink.php';
        $preset = isset(LabelSheet::PRESETS[$preset]) ? $preset : 'a4-3x8';
        $room = $this->room($locationId);
        $sql = 'SELECT id, item_name, item_code FROM inventory_items WHERE location_id = ?';
        $args = [$locationId];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids) {
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $args = array_merge($args, $ids);
        }
        $statement = $this->db->prepare($sql . ' ORDER BY item_name, item_code, id LIMIT ' . (self::MAX_ROWS + 1));
        $statement->execute($args);
        $items = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) {
            throw new RuntimeException('Barang untuk label tidak ditemukan.');
        }
        if (count($items) > self::MAX_ROWS) {
            throw new RuntimeException('Maksimal 500 label sekali cetak. Pilih barang yang akan dicetak.');
        }
        $labels = array_map(fn (array $item): array => [
            'item' => $item,
            'room' => (string) $room['room_name'],
            'library' => (string) ($room['slims_location_name'] ?? ''),
            'url' => rtrim($siteUrl, '/') . '/index.php?' . PublicLink::query($this->db, (int) $item['id']),
        ], $items);
        $pdf = LabelSheet::mpdf($this->tempDir, $preset, 'Label inventaris - ' . $room['room_name']);
        $pdf->WriteHTML(LabelSheet::render($labels, $preset, 1));
        Telemetry::count('labels_pdf');
        $name = count($items) === 1 ? ((string) $items[0]['item_code'] ?: (string) $items[0]['item_name']) : (string) $room['room_name'];

        return ['filename' => 'label-' . self::slug($name, 'barang') . '.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => count($items)];
    }

    /**
     * Daftar inventaris berfoto: the items with their first photo or all of them, grouped by category or by area.
     *
     * @param  list<string>  $categories
     * @return array{filename: string, bytes: string, count: int}
     */
    public function catalog(PhotoStorage $storage, string $group, array $categories, string $library, string $photos, string $libraryName, string $printedBy): array
    {
        self::ensureRuntime();
        require_once __DIR__ . '/InventoryCatalog.php';
        $catalog = InventoryCatalog::build($this->db, $group, $categories, $library, $photos);
        $html = InventoryCatalog::html($catalog, static fn (string $filename): ?string => $storage->read($filename), ['library_name' => $libraryName, 'printed_by' => $printedBy]);
        $pdf = PdfLayout::mpdf($this->tempDir, 'Daftar Inventaris Berfoto', PdfLayout::footer('Daftar inventaris berfoto'));
        $pdf->WriteHTML($html);

        return ['filename' => 'daftar-inventaris-berfoto-' . self::slug($group . '-' . $library, $group) . '.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => $catalog['items']];
    }

    /**
     * Daftar area dan fasilitas: the areas inside the rooms with their rooms and photos, by group.
     *
     * @return array{filename: string, bytes: string, count: int}
     */
    public function areas(PhotoStorage $itemPhotos, string $group, string $library, string $libraryName, string $printedBy): array
    {
        self::ensureRuntime();
        require_once __DIR__ . '/AreaCatalog.php';
        $catalog = AreaCatalog::build($this->db, $group, $library);
        $areaPhotos = AreaPhotos::storage();
        $html = AreaCatalog::html($catalog, static fn (string $filename): ?string => $areaPhotos->read($filename), static fn (string $filename): ?string => $itemPhotos->read($filename), ['library_name' => $libraryName, 'printed_by' => $printedBy]);
        $pdf = PdfLayout::mpdf($this->tempDir, 'Daftar Area dan Fasilitas', PdfLayout::footer('Daftar area dan fasilitas'));
        $pdf->WriteHTML($html);

        return ['filename' => 'daftar-area-dan-fasilitas' . ($group !== '' ? '-' . $group : '') . '.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => $catalog['areas']];
    }

    /**
     * Daftar perangkat lunak: the software register by use, with each application's licence.
     *
     * @return array{filename: string, bytes: string, count: int}
     */
    public function software(string $printedBy): array
    {
        self::ensureRuntime();
        require_once __DIR__ . '/SoftwareList.php';
        $list = SoftwareList::build($this->db, date('Y-m-d'));
        $pdf = PdfLayout::mpdf($this->tempDir, 'Daftar Perangkat Lunak', PdfLayout::footer('Daftar perangkat lunak'));
        $pdf->WriteHTML(SoftwareList::html($list, ['printed_by' => $printedBy]));

        return ['filename' => 'daftar-perangkat-lunak.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => $list['applications']];
    }

    /**
     * One inspection as a document, as the admin Reports page prints it: what was examined, the
     * findings with their photos, and the examiner's signature block. The berita acara of the check.
     *
     * @return array{filename: string, bytes: string, count: int}
     */
    public function inspection(Supervision $watch, int $id): array
    {
        self::ensureRuntime();
        require_once __DIR__ . '/WatchPdf.php';
        require_once __DIR__ . '/PdfDocuments.php';
        $document = $watch->document($id);
        $html = WatchPdf::detail($document, static fn (array $photo): ?string => $watch->photo($id, (int) $photo['id']), 'latex', PdfDocuments::load($this->db));
        $pdf = WatchPdf::mpdf($this->tempDir, 'Dokumen Pemeriksaan #' . $id, 'latex');
        $pdf->WriteHTML($html);
        Telemetry::count('inspection_pdf');

        return ['filename' => 'pemeriksaan-' . $id . '.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => count($document['results'])];
    }

    /**
     * Jadwal pemeriksaan: the routine inspection schedules that are running, as one sheet.
     *
     * @return array{filename: string, bytes: string, count: int}
     */
    public function schedules(Supervision $watch, string $printedBy): array
    {
        self::ensureRuntime();
        require_once __DIR__ . '/WatchSheets.php';
        $rows = $watch->query("SELECT * FROM inventory_watch_schedules WHERE active=1 AND location_id IS NOT NULL AND (end_date IS NULL OR end_date>=CURRENT_DATE) ORDER BY start_date, id LIMIT " . (self::MAX_ROWS + 1))->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new RuntimeException('Jadwal melebihi 500 baris. Cetak dari halaman Jadwal di SLiMS.');
        }
        usort($rows, static fn (array $a, array $b): int => [Supervision::decode((string) $a['snapshot'])['room_name'] ?? '', $a['id']] <=> [Supervision::decode((string) $b['snapshot'])['room_name'] ?? '', $b['id']]);
        $rooms = (int) $watch->query('SELECT COUNT(*) FROM inventory_locations')->fetchColumn();
        $pdf = PdfLayout::mpdf($this->tempDir, 'Jadwal Pemeriksaan', PdfLayout::footer('Jadwal pemeriksaan'));
        $pdf->WriteHTML(WatchSheets::schedules($rows, ['printed_by' => $printedBy, 'rooms' => $rooms]));

        return ['filename' => 'jadwal-pemeriksaan.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => count($rows)];
    }

    /**
     * Checklist pemeriksaan: one checklist, or with no id those the running schedules use, each as
     * the form an examiner fills in.
     *
     * @return array{filename: string, bytes: string, count: int}
     */
    public function checklists(Supervision $watch, int $id, string $printedBy): array
    {
        self::ensureRuntime();
        require_once __DIR__ . '/WatchSheets.php';
        $templates = $id > 0
            ? [$watch->row('templates', $id)]
            : $watch->query('SELECT t.* FROM inventory_watch_templates t WHERE t.id IN (SELECT s.template_id FROM inventory_watch_schedules s WHERE s.active=1 AND s.location_id IS NOT NULL AND (s.end_date IS NULL OR s.end_date>=CURRENT_DATE)) ORDER BY t.name, t.id')->fetchAll(PDO::FETCH_ASSOC);
        $pdf = PdfLayout::mpdf($this->tempDir, 'Checklist Pemeriksaan', PdfLayout::footer('Checklist pemeriksaan'));
        $pdf->WriteHTML(WatchSheets::checklists($templates, ['printed_by' => $printedBy]));

        return ['filename' => 'checklist-pemeriksaan' . ($id > 0 ? '-' . $id : '') . '.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => count($templates)];
    }

    /**
     * The supervision report for a period, as the admin Reports page prints it.
     *
     * @param  array{from: string, to: string, library: string, room: int, inspection_status: string, finding_status: string}  $filter
     * @return array{filename: string, bytes: string, count: int}
     */
    public function period(Supervision $watch, array $filter, string $printedBy): array
    {
        self::ensureRuntime();
        require_once __DIR__ . '/WatchPdf.php';
        require_once __DIR__ . '/PdfDocuments.php';
        $rows = $watch->inspections($filter, 1, self::MAX_ROWS + 1);
        if (count($rows) > self::MAX_ROWS) {
            throw new RuntimeException('Laporan melebihi 500 pemeriksaan. Pilih periode yang lebih pendek.');
        }
        $context = ['library' => '', 'room' => '', 'printed_by' => $printedBy, 'documents' => PdfDocuments::load($this->db)];
        $html = WatchPdf::summary($filter, $watch->summary($filter, true), $rows, $context, 'latex');
        $pdf = WatchPdf::mpdf($this->tempDir, 'Laporan Pengawasan dan Pemeliharaan', 'latex');
        $pdf->WriteHTML($html);
        Telemetry::count('report_pdf');

        return ['filename' => 'laporan-pengawasan-' . $filter['from'] . '-' . $filter['to'] . '.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => count($rows)];
    }

    /**
     * The copies of a stock take session not found yet, as a plain table.
     *
     * @return array{filename: string, bytes: string, count: int}
     */
    public function stockTake(StockTake $stockTake, int $sessionId, string $library): array
    {
        self::ensureRuntime();
        $session = $stockTake->session($sessionId);
        $rows = [];
        for ($page = 1; $page <= 100; ++$page) {
            $chunk = $stockTake->items($sessionId, 'missing', '', $page, 200);
            $rows = array_merge($rows, $chunk['rows']);
            if ($page >= $chunk['pages']) {
                break;
            }
        }
        $e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $html = '<style>body{font-family:dejavusanscondensed;font-size:9pt}h1{font-size:13pt;margin:0}p{margin:2pt 0 8pt}table{width:100%;border-collapse:collapse}th,td{border:0.2mm solid #999;padding:3pt;text-align:left;vertical-align:top}th{background:#eee}</style>'
            . '<h1>Eksemplar belum ditemukan</h1><p>' . $e($library) . ' · ' . $e($session['name']) . '<br>'
            . 'Ditemukan ' . $session['found'] . ' dari ' . $session['total'] . ' eksemplar · belum ditemukan ' . $session['missing'] . ' · dipinjam ' . $session['on_loan'] . ' · dicetak ' . date('d-m-Y H:i') . '</p>'
            . '<table><thead><tr><th>No.</th><th>Kode eksemplar</th><th>Judul</th><th>No. panggil</th><th>Lokasi</th></tr></thead><tbody>';
        foreach ($rows as $index => $row) {
            $html .= '<tr><td>' . ($index + 1) . '</td><td>' . $e($row['code']) . '</td><td>' . $e($row['title']) . '</td><td>' . $e($row['call_number']) . '</td><td>' . $e($row['location']) . '</td></tr>';
        }
        $html .= '</tbody></table>';
        $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $this->tempDir, 'exposeVersion' => false, 'default_font' => 'dejavusanscondensed']);
        $pdf->SetTitle('Stock opname - ' . $session['name']);
        $pdf->WriteHTML($html);

        return ['filename' => 'stock-opname-' . self::slug($session['name'], (string) $sessionId) . '.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => count($rows)];
    }
}
