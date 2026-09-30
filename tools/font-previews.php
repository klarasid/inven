<?php
/**
 * Renders assets/fonts/preview/<key>.png: each font's name drawn in that font, from the same TTF mPDF
 * uses, for the font picker. Run from the plugin directory after changing PdfFonts::FONTS:
 *   php tools/font-previews.php
 */
require __DIR__ . '/../src/PdfFonts.php';

$out = __DIR__ . '/../assets/fonts/preview';
if (!is_dir($out)) mkdir($out, 0775, true);
$size = 26;        // px at 2x; shown at half size in the picker
$height = 44;
foreach (\SLiMS\Plugins\Inventory\PdfFonts::FONTS as $key => [$name, , , $file]) {
    $font = realpath(__DIR__ . '/../' . $file);
    if (!$font) { fwrite(STDERR, "Font tidak ditemukan: $file\n"); exit(1); }
    $box = imagettfbbox($size, 0, $font, $name);
    $width = (int) ceil(max($box[2], $box[4]) - min($box[0], $box[6])) + 8;
    $image = imagecreatetruecolor($width, $height);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagettftext($image, $size, 0, 2 - min($box[0], $box[6]), 33, imagecolorallocate($image, 17, 24, 39), $font, $name);
    imagepng($image, "$out/$key.png", 9);
    imagedestroy($image);
    echo "$key.png ({$width}×{$height})\n";
}
