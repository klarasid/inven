<?php
/**
 * Smoke test for a release package: renders every PDF the plugin prints with the package's own
 * vendor directory, so pruned fonts or missing libraries fail the build instead of a user's print.
 *   php tools/smoke.php <path to packaged inventaris-barang folder>
 */
declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

error_reporting(E_ALL & ~E_DEPRECATED);
$package = rtrim((string) ($argv[1] ?? ''), '/');
if ($package === '' || !is_file("$package/vendor/autoload.php")) {
    fwrite(STDERR, "Pemakaian: php tools/smoke.php <folder paket inventaris-barang>\n");
    exit(2);
}
$work = sys_get_temp_dir() . '/inventaris-smoke-' . getmypid();
@mkdir("$work/images/inventaris-barang/kop", 0777, true);
define('SB', "$work/");
require "$package/vendor/autoload.php";
foreach (['PdfLayout', 'PdfTemplate', 'PdfTemplateModern', 'PdfLatex', 'PdfIso', 'PdfFonts', 'Letterheads', 'PdfLetterhead', 'LabelSheet'] as $class) require_once "$package/src/$class.php";

$failed = 0;
$run = function (string $label, callable $render) use (&$failed): void {
    try {
        $pdf = $render();
        $bytes = $pdf->Output('', 'S');
        $ok = str_starts_with($bytes, '%PDF-') && strlen($bytes) > 1000;
        echo ($ok ? 'ok   ' : 'FAIL ') . $label . ' (' . round(strlen($bytes) / 1024) . " KB)\n";
        $failed += $ok ? 0 : 1;
    } catch (\Throwable $e) {
        echo "FAIL $label — " . $e->getMessage() . "\n";
        $failed++;
    }
};
$tmp = $work;
$location = ['room_name' => 'Ruang Uji', 'location_code' => 'U-01', 'province' => 'Jawa Tengah', 'signature_city' => 'Semarang', 'knowing_name' => 'A', 'manager_name' => 'B'];
$items = [['item_name' => 'Meja', 'item_code' => 'INV-1', 'item_condition' => 'B', 'acquisition_price' => 1000]];
$body = fn(string $style) => $style::begin() . $style::titleBlock('Laporan Uji', 'Penyusun', 'Periode', ['number' => 'N-1', 'revision' => '00'])
    . $style::abstract('Ringkasan <b>tebal</b> dan <i>miring</i>.') . $style::section('Bagian') . $style::table('Tabel', ['A', ['B', 'r']], [['satu', '1']])
    . $style::signatures([['Mengetahui,', 'Kepala', ''], ['Disusun,', 'Petugas', 'C']], 'Semarang, 1 Januari 2026');

$run('KIR klasik', function () use ($location, $items, $tmp) {
    $pdf = new \Mpdf\Mpdf(['tempDir' => $tmp, 'format' => [330, 216]]);
    $pdf->WriteHTML(PdfTemplate::render($location, $items));
    return $pdf;
});
$run('KIR modern', function () use ($location, $items, $tmp) {
    $pdf = PdfLayout::mpdf($tmp, 'KIR', PdfTemplateModern::footer($location), ['format' => [330, 216]]);
    $pdf->WriteHTML(PdfTemplateModern::render($location, $items));
    return $pdf;
});
$run('Laporan gaya LaTeX (CMU Serif)', function () use ($body, $tmp) {
    $pdf = PdfLatex::mpdf($tmp, 'Uji');
    $pdf->WriteHTML($body(PdfLatex::class));
    return $pdf;
});
$run('Laporan gaya ISO', function () use ($body, $tmp) {
    $pdf = PdfIso::mpdf($tmp, 'Uji');
    $pdf->WriteHTML($body(PdfIso::class));
    return $pdf;
});
$run('Laporan ber-kop', function () use ($body, $tmp, $work) {
    $kop = new \Mpdf\Mpdf(['tempDir' => $tmp]);
    $kop->WriteHTML('<p>KOP</p>');
    $kop->Output("$work/images/inventaris-barang/kop/0123456789abcdef.pdf", 'F');
    PdfLetterhead::configure(['id' => '0123456789abcdef', 'pages' => 1, 'sizes' => [[210.0, 297.0]], 'first' => ['top' => 40, 'right' => 20, 'bottom' => 25, 'left' => 20], 'next' => ['top' => 25, 'bottom' => 25], 'body' => 'latex', 'font' => 'freeserif', 'first_only' => true]);
    $pdf = PdfLetterhead::mpdf($tmp, 'Uji');
    $pdf->WriteHTML($body(PdfLetterhead::class));
    return $pdf;
});
$run('Label QR', function () use ($tmp) {
    $pdf = LabelSheet::mpdf($tmp, 'a4-3x8', 'Label');
    $pdf->WriteHTML(LabelSheet::render([['item' => ['id' => 1, 'item_name' => 'Meja', 'item_code' => 'INV-1'], 'room' => 'Ruang', 'library' => 'Pusat', 'url' => 'https://example.test/?p=info_barang&i=1&t=abc']], 'a4-3x8'));
    return $pdf;
});
foreach (array_keys(PdfFonts::FONTS) as $font) {
    $run("Font $font", function () use ($font, $tmp) {
        $pdf = PdfLatex::mpdf($tmp, 'Font');
        $pdf->WriteHTML(PdfFonts::css($font) . '<p>Biasa <b>tebal</b> <i>miring</i> <b><i>tebal miring</i></b></p>');
        return $pdf;
    });
}

exec('rm -rf ' . escapeshellarg($work));
echo $failed ? "$failed uji asap gagal\n" : "Uji asap lulus\n";
exit($failed ? 1 : 0);
