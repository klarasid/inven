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

    /**
     * The WatchPdf style a document is typeset in: the one asked for (`latex`, `iso`, `kop` or
     * `kop:<id>`), or with none the default saved in Pengaturan Cetak.
     */
    /** Whether the documents carry an electronic signature with a QR code (DocumentSignature). */
    public bool $sign = false;
    /** The SLiMS user id of the signer, when known. */
    public ?int $signerId = null;

    /**
     * Signs the document and adds its block to the PDF, when signing is on. The signer is the one
     * printing unless another is named with their user id (an inspection's examiner; 0 when unknown).
     */
    private function seal(\Mpdf\Mpdf $pdf, string $type, string $title, string $scope, string $signer, string $html, int $count, ?int $signerId = null): void
    {
        if (!$this->sign) return;
        require_once __DIR__ . '/DocumentSignature.php';
        DocumentSignature::stamp($pdf, $this->db, $type, $title, $scope, $signer, $signerId ?? $this->signerId, $html, $count);
    }

    private function style(string $requested): string
    {
        require_once __DIR__ . '/WatchPdf.php';
        require_once __DIR__ . '/PdfDocuments.php';

        return PrintDefaults::style($this->db, $requested);
    }

    /**
     * Keeps a document's photos out of its HTML: each is handed to mPDF by name and the HTML only
     * names it. As data URIs, some eighty thumbnails already pass the 1 MB of HTML mPDF accepts.
     *
     * @return callable(string): string  the `src` for a thumbnail's JPEG bytes
     */
    public static function photos(\Mpdf\Mpdf $pdf): callable
    {
        return static function (string $jpeg) use ($pdf): string {
            $name = 'foto' . count($pdf->imageVars);
            $pdf->imageVars[$name] = $jpeg;

            return 'var:' . $name;
        };
    }

    /** Hands a document to mPDF piece by piece, so a long one stays under what mPDF accepts at once. */
    public static function write(\Mpdf\Mpdf $pdf, string $html): void
    {
        foreach (explode(PdfLayout::CHUNK, $html) as $piece) {
            if ($piece !== '') $pdf->WriteHTML($piece);
        }
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
    public function kir(int $locationId, bool $modern, string $author, bool $withSpecimens = false): array
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
        $specimens = [];
        if ($withSpecimens) {
            require_once __DIR__ . '/RoomSpecimens.php';
            try {
                $specimens = RoomSpecimens::dataUris($this->db, $locationId);
            } catch (\PDOException $exception) {
                // Before migration 26 no room has a specimen.
                if ((int) ($exception->errorInfo[1] ?? 0) !== 1146) throw $exception;
            }
        }
        if ($modern) {
            require_once __DIR__ . '/PdfTemplateModern.php';
            $html = PdfTemplateModern::render($location, $items, null, $specimens);
            $pdf = PdfLayout::mpdf($this->tempDir, $title, PdfTemplateModern::footer($location), ['format' => [330, 216], 'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 9, 'margin_bottom' => 13, 'margin_footer' => 6, 'exposeVersion' => false]);
        } else {
            require_once __DIR__ . '/PdfTemplate.php';
            $html = PdfTemplate::render($location, $items, null, $specimens);
            $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => [330, 216], 'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 4, 'margin_bottom' => 7, 'tempDir' => $this->tempDir, 'exposeVersion' => false]);
            $pdf->SetTitle($title);
            $pdf->SetAuthor($author);
        }
        $pdf->WriteHTML($html);
        $this->seal($pdf, 'kir', $title, trim((string) ($location['slims_location_name'] ?? '')), $author, $html, count($items));
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
     * Daftar inventaris berfoto: the items with their first photo or all of them, grouped by category or by area,
     * in the style asked for or with none the default saved in Pengaturan Cetak (see inspection()).
     *
     * @param  list<string>  $categories
     * @return array{filename: string, bytes: string, count: int}
     */
    public function catalog(PhotoStorage $storage, string $group, array $categories, string $library, string $photos, string $libraryName, string $printedBy, string $style = ''): array
    {
        self::ensureRuntime();
        require_once __DIR__ . '/InventoryCatalog.php';
        require_once __DIR__ . '/WatchPdf.php';
        require_once __DIR__ . '/PdfDocuments.php';
        $catalog = InventoryCatalog::build($this->db, $group, $categories, $library, $photos);
        $style = $this->style($style);
        $context = ['library_name' => $libraryName, 'printed_by' => $printedBy, 'documents' => PdfDocuments::load($this->db)];
        $pdf = WatchPdf::mpdf($this->tempDir, 'Daftar Inventaris Berfoto', $style);
        $html = InventoryCatalog::html($catalog, static fn (string $filename): ?string => $storage->read($filename), $context, $style, self::photos($pdf));
        self::write($pdf, $html);
        $this->seal($pdf, 'catalog', 'Daftar Inventaris Berfoto', (string) ($catalog['library_name'] ?? ''), $printedBy, $html, $catalog['items']);

        return ['filename' => 'daftar-inventaris-berfoto-' . self::slug($group . '-' . $library, $group) . '.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => $catalog['items']];
    }

    /**
     * The photos of the daftar inventaris berfoto as a ZIP, with the same filters as the printed list.
     *
     * @return array{filename: string, bytes: string, count: int}
     */
    public function catalogPhotos(PhotoStorage $storage, string $group, array $categories, string $library, string $photos): array
    {
        require_once __DIR__ . '/InventoryCatalog.php';
        $catalog = InventoryCatalog::build($this->db, $group, $categories, $library, $photos);
        $zip = InventoryCatalog::archive($catalog, static fn (string $filename): ?string => $storage->read($filename), $this->tempDir);

        return ['filename' => 'foto-inventaris-' . self::slug($group . '-' . $library, $group) . '.zip', 'bytes' => $zip['bytes'], 'count' => $zip['files']];
    }

    /**
     * The photos of the daftar area dan fasilitas as a ZIP, with the same filters as the printed list.
     *
     * @return array{filename: string, bytes: string, count: int}
     */
    public function areaPhotos(PhotoStorage $itemPhotos, string $group, string $library): array
    {
        require_once __DIR__ . '/AreaCatalog.php';
        $catalog = AreaCatalog::build($this->db, $group, $library);
        $areaPhotos = AreaPhotos::storage();
        $zip = AreaCatalog::archive($catalog, static fn (string $filename): ?string => $areaPhotos->read($filename), static fn (string $filename): ?string => $itemPhotos->read($filename), $this->tempDir);

        return ['filename' => 'foto-area-dan-fasilitas' . ($group !== '' ? '-' . $group : '') . '.zip', 'bytes' => $zip['bytes'], 'count' => $zip['files']];
    }

    /**
     * Daftar area dan fasilitas: the areas inside the rooms with their rooms and photos, by group.
     *
     * @return array{filename: string, bytes: string, count: int}
     */
    public function areas(PhotoStorage $itemPhotos, string $group, string $library, string $libraryName, string $printedBy, string $style = ''): array
    {
        self::ensureRuntime();
        require_once __DIR__ . '/AreaCatalog.php';
        $catalog = AreaCatalog::build($this->db, $group, $library);
        $areaPhotos = AreaPhotos::storage();
        $style = $this->style($style);
        $pdf = WatchPdf::mpdf($this->tempDir, 'Daftar Area dan Fasilitas', $style);
        $html = AreaCatalog::html($catalog, static fn (string $filename): ?string => $areaPhotos->read($filename), static fn (string $filename): ?string => $itemPhotos->read($filename), ['library_name' => $libraryName, 'printed_by' => $printedBy, 'documents' => PdfDocuments::load($this->db)], $style, self::photos($pdf));
        self::write($pdf, $html);
        $this->seal($pdf, 'areas', 'Daftar Area dan Fasilitas', $library, $printedBy, $html, $catalog['areas']);

        return ['filename' => 'daftar-area-dan-fasilitas' . ($group !== '' ? '-' . $group : '') . '.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => $catalog['areas']];
    }

    /**
     * Daftar area dan luas ruangan: the rooms with their floor area and the areas inside them, by
     * library location, without photos.
     *
     * @return array{filename: string, bytes: string, count: int}
     */
    public function roomSizes(string $library, string $libraryName, string $printedBy, string $style = ''): array
    {
        self::ensureRuntime();
        require_once __DIR__ . '/RoomSizes.php';
        $list = RoomSizes::build($this->db, $library);
        $style = $this->style($style);
        $pdf = WatchPdf::mpdf($this->tempDir, 'Daftar Area dan Luas Ruangan', $style);
        $html = RoomSizes::html($list, ['library_name' => $libraryName, 'printed_by' => $printedBy, 'documents' => PdfDocuments::load($this->db)], $style);
        self::write($pdf, $html);
        $this->seal($pdf, 'rooms', 'Daftar Area dan Luas Ruangan', $library, $printedBy, $html, $list['rooms']);

        return ['filename' => 'daftar-area-dan-luas-ruangan' . ($library !== '' ? '-' . self::slug($library, 'lokasi') : '') . '.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => $list['rooms']];
    }

    /**
     * Daftar perangkat lunak: the software register by use, with each application's licence.
     *
     * @return array{filename: string, bytes: string, count: int}
     */
    public function software(string $printedBy, string $style = ''): array
    {
        self::ensureRuntime();
        require_once __DIR__ . '/SoftwareList.php';
        $list = SoftwareList::build($this->db, date('Y-m-d'));
        $style = $this->style($style);
        $pdf = WatchPdf::mpdf($this->tempDir, 'Daftar Perangkat Lunak', $style);
        $html = SoftwareList::html($list, ['printed_by' => $printedBy, 'documents' => PdfDocuments::load($this->db)], $style);
        $pdf->WriteHTML($html);
        $this->seal($pdf, 'software', 'Daftar Perangkat Lunak', '', $printedBy, $html, $list['applications']);

        return ['filename' => 'daftar-perangkat-lunak.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => $list['applications']];
    }

    /**
     * One inspection as a document, as the admin Reports page prints it: what was examined, the
     * findings with their photos, and once finalised the examiner's electronic signature. The berita acara of the check.
     * $style is `latex`, `iso`, `kop` or `kop:<id>`; with none, the default saved in Pengaturan Cetak.
     *
     * @return array{filename: string, bytes: string, count: int}
     */
    public function inspection(Supervision $watch, int $id, string $style = ''): array
    {
        self::ensureRuntime();
        require_once __DIR__ . '/WatchPdf.php';
        require_once __DIR__ . '/PdfDocuments.php';
        $style = $this->style($style);
        $document = $watch->document($id);
        $html = WatchPdf::detail($document, static fn (array $photo): ?string => $watch->photo($id, (int) $photo['id']), $style, PdfDocuments::load($this->db));
        $pdf = WatchPdf::mpdf($this->tempDir, 'Dokumen Pemeriksaan #' . $id, $style);
        $pdf->WriteHTML($html);
        if ($signer = WatchPdf::signer($document)) {
            $this->seal($pdf, $signer['type'], $signer['title'], $signer['scope'], $signer['name'], $html, $signer['count'], (int) $signer['id']);
        }
        Telemetry::count('inspection_pdf');

        return ['filename' => 'pemeriksaan-' . $id . '.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => count($document['results'])];
    }

    /**
     * The photos of one inspection document as they were uploaded, in a ZIP (see WatchPdf::archive).
     *
     * @return array{filename: string, bytes: string, count: int}
     */
    public function inspectionPhotos(Supervision $watch, int $id): array
    {
        require_once __DIR__ . '/WatchPdf.php';
        $zip = WatchPdf::archive($watch->document($id), static fn (array $photo): ?string => $watch->photo($id, (int) $photo['id']), $this->tempDir);

        return ['filename' => 'foto-pemeriksaan-' . $id . '.zip', 'bytes' => $zip['bytes'], 'count' => $zip['files']];
    }

    /**
     * Rekap Sarpras as the Rekap Sarpras page prints it: one library location's recap, or the
     * institution's where several locations hold rooms and none is named (a lone location is
     * always its own). In the style asked for or with none the default saved in Pengaturan Cetak.
     *
     * @return array{filename: string, bytes: string, count: int}
     */
    public function sarpras(Supervision $watch, string $library, string $printedBy, string $style = ''): array
    {
        self::ensureRuntime();
        require_once __DIR__ . '/SarprasPdf.php';
        require_once __DIR__ . '/PdfDocuments.php';
        $places = Sarpras::locations($this->db);
        $codes = array_column($places['locations'], 'code');
        if ($library !== '' && !in_array($library, $codes, true)) {
            throw new RuntimeException('Lokasi perpustakaan tidak ditemukan. Pilih salah satu: ' . implode(', ', $codes) . '.');
        }
        if ($library === '' && count($codes) === 1 && $places['rooms'] > 0) {
            $library = $codes[0];
        }
        $style = $this->style($style);
        $context = ['printed_by' => $printedBy, 'documents' => PdfDocuments::load($this->db)];
        if ($library === '' && $places['rooms'] > 0 && count($codes) > 1) {
            $overview = Sarpras::overview($this->db, $watch, $places['locations']);
            $recap = $overview['recap'];
            $context['locations'] = $overview['locations'];
            $scope = 'Semua lokasi';
        } else {
            $recap = Sarpras::recap($this->db, $watch, $library);
            $names = array_column($places['locations'], 'name', 'code');
            $context['location'] = $scope = (string) ($names[$library] ?? '');
        }
        $html = SarprasPdf::render($recap, $context, $style);
        $pdf = WatchPdf::mpdf($this->tempDir, 'Rekap Sarana dan Prasarana', $style);
        $pdf->WriteHTML($html);
        $this->seal($pdf, 'sarpras', 'Rekap Sarana dan Prasarana Perpustakaan', $scope, $printedBy, $html, count($recap['aspects']));
        Telemetry::count('sarpras_pdf');

        return ['filename' => 'rekap-sarpras-' . ($library !== '' ? self::slug($library, 'lokasi') . '-' : '') . date('Ymd') . '.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => count($recap['aspects'])];
    }

    /**
     * Jadwal pemeriksaan: the routine inspection schedules that are running, as one sheet.
     *
     * @return array{filename: string, bytes: string, count: int}
     */
    public function schedules(Supervision $watch, string $printedBy, string $style = ''): array
    {
        self::ensureRuntime();
        require_once __DIR__ . '/WatchSheets.php';
        $rows = $watch->query("SELECT * FROM inventory_watch_schedules WHERE active=1 AND location_id IS NOT NULL AND (end_date IS NULL OR end_date>=CURRENT_DATE) ORDER BY start_date, id LIMIT " . (self::MAX_ROWS + 1))->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new RuntimeException('Jadwal melebihi 500 baris. Cetak dari halaman Jadwal di SLiMS.');
        }
        usort($rows, static fn (array $a, array $b): int => [Supervision::decode((string) $a['snapshot'])['room_name'] ?? '', $a['id']] <=> [Supervision::decode((string) $b['snapshot'])['room_name'] ?? '', $b['id']]);
        $rooms = (int) $watch->query('SELECT COUNT(*) FROM inventory_locations')->fetchColumn();
        $style = $this->style($style);
        $pdf = WatchPdf::mpdf($this->tempDir, 'Jadwal Pemeriksaan', $style);
        $html = WatchSheets::schedules($rows, ['printed_by' => $printedBy, 'rooms' => $rooms, 'documents' => PdfDocuments::load($this->db)], $style);
        $pdf->WriteHTML($html);
        $this->seal($pdf, 'schedules', 'Jadwal Pemeriksaan Sarana dan Prasarana', '', $printedBy, $html, count($rows));

        return ['filename' => 'jadwal-pemeriksaan.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => count($rows)];
    }

    /**
     * Checklist pemeriksaan: one checklist, or with no id those the running schedules use, each as
     * the form an examiner fills in.
     *
     * @return array{filename: string, bytes: string, count: int}
     */
    public function checklists(Supervision $watch, int $id, string $printedBy, string $style = ''): array
    {
        self::ensureRuntime();
        require_once __DIR__ . '/WatchSheets.php';
        $templates = $id > 0
            ? [$watch->row('templates', $id)]
            : $watch->query('SELECT t.* FROM inventory_watch_templates t WHERE t.id IN (SELECT s.template_id FROM inventory_watch_schedules s WHERE s.active=1 AND s.location_id IS NOT NULL AND (s.end_date IS NULL OR s.end_date>=CURRENT_DATE)) ORDER BY t.name, t.id')->fetchAll(PDO::FETCH_ASSOC);
        $style = $this->style($style);
        $pdf = WatchPdf::mpdf($this->tempDir, 'Checklist Pemeriksaan', $style);
        $pdf->WriteHTML(WatchSheets::checklists($templates, ['printed_by' => $printedBy, 'documents' => PdfDocuments::load($this->db)], $style));

        return ['filename' => 'checklist-pemeriksaan' . ($id > 0 ? '-' . $id : '') . '.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => count($templates)];
    }

    /**
     * The supervision report for a period, as the admin Reports page prints it, in the style
     * asked for or with none the default saved in Pengaturan Cetak (see inspection()).
     *
     * @param  array{from: string, to: string, library: string, room: int, inspection_status: string, finding_status: string}  $filter
     * @return array{filename: string, bytes: string, count: int}
     */
    public function period(Supervision $watch, array $filter, string $printedBy, string $style = ''): array
    {
        self::ensureRuntime();
        require_once __DIR__ . '/WatchPdf.php';
        require_once __DIR__ . '/PdfDocuments.php';
        $style = $this->style($style);
        $rows = $watch->inspections($filter, 1, self::MAX_ROWS + 1);
        if (count($rows) > self::MAX_ROWS) {
            throw new RuntimeException('Laporan melebihi 500 pemeriksaan. Pilih periode yang lebih pendek.');
        }
        $library = $filter['library'] !== '' ? (string) $watch->query('SELECT location_name FROM mst_location WHERE location_id = ?', [$filter['library']])->fetchColumn() : '';
        $room = $filter['room'] ? (string) $watch->query('SELECT room_name FROM inventory_locations WHERE id = ?', [$filter['room']])->fetchColumn() : '';
        $context = ['library' => $library, 'room' => $room, 'printed_by' => $printedBy, 'documents' => PdfDocuments::load($this->db)];
        $html = WatchPdf::summary($filter, $watch->summary($filter, true), $rows, $context, $style);
        $pdf = WatchPdf::mpdf($this->tempDir, 'Laporan Pengawasan dan Pemeliharaan', $style);
        $pdf->WriteHTML($html);
        $this->seal($pdf, 'report', 'Laporan Pengawasan dan Pemeliharaan ' . PdfLayout::date($filter['from']) . ' - ' . PdfLayout::date($filter['to']), implode(' · ', array_filter([$library, $room], 'strlen')), $printedBy, $html, count($rows));
        Telemetry::count('report_pdf');
        $where = array_filter([$filter['library'], $filter['room'] ? 'ruang-' . $filter['room'] : ''], 'strlen');

        return ['filename' => 'laporan-pengawasan-' . ($where ? self::slug(implode('-', $where), 'lokasi') . '-' : '') . $filter['from'] . '-' . $filter['to'] . '.pdf', 'bytes' => $pdf->Output('', 'S'), 'count' => count($rows)];
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
