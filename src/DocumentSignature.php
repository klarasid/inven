<?php

declare(strict_types=1);

namespace SLiMS\Plugins\Inventory;

use PDO;

require_once __DIR__ . '/PublicLink.php';
require_once __DIR__ . '/PdfLayout.php';

/**
 * Electronic signature of a printed document: the signer, the time and a SHA-256 fingerprint of
 * the document's content are recorded, and a block with a QR code is added at the end of the PDF.
 * The QR opens a public page (verifikasi.php) that tells whether the code is on record and shows
 * what was signed. Printing the same content again for the same signer keeps the same code.
 */
final class DocumentSignature
{
    public const PAGE = 'verifikasi_dokumen';
    public const TYPES = [
        'kir' => 'Kartu Inventaris Ruangan', 'catalog' => 'Daftar inventaris berfoto', 'sarpras' => 'Rekap Sarpras',
        'areas' => 'Daftar area dan fasilitas', 'rooms' => 'Daftar area dan luas ruangan', 'software' => 'Daftar perangkat lunak',
    ];
    private const CODE_LENGTH = 16;

    public static function code(PDO $db, string $type, string $hash, string $signer): string
    {
        return substr(hash_hmac('sha256', 'doc:' . $type . '|' . $hash . '|' . $signer, PublicLink::secret($db)), 0, self::CODE_LENGTH);
    }

    public static function valid(string $code): bool
    {
        return (bool) preg_match('/\A[0-9a-f]{' . self::CODE_LENGTH . '}\z/', $code);
    }

    /**
     * Records the signature of a document, or finds the one already recorded for this content and signer.
     *
     * @return array{code:string,doc_type:string,title:string,scope:string,signer_name:string,content_hash:string,item_count:int,signed_at:string}
     */
    public static function sign(PDO $db, string $type, string $title, string $scope, string $signer, ?int $signerId, string $content, int $count): array
    {
        $hash = hash('sha256', $content);
        $code = self::code($db, $type, $hash, $signer);
        $find = $db->prepare('SELECT * FROM inventory_signed_documents WHERE code = ?');
        $find->execute([$code]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            try {
                $db->prepare('INSERT INTO inventory_signed_documents (code, doc_type, title, scope, signer_name, signer_id, content_hash, item_count, signed_at) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$code, $type, mb_substr($title, 0, 200), mb_substr($scope, 0, 200), mb_substr($signer, 0, 150), $signerId ?: null, $hash, max(0, $count), date('Y-m-d H:i:s')]);
            } catch (\PDOException $race) {
                // Two prints of the same document at once: the other one's row is the record.
            }
            $find->execute([$code]);
            $row = $find->fetch(PDO::FETCH_ASSOC);
        }
        return $row;
    }

    public static function find(PDO $db, string $code): ?array
    {
        if (!self::valid($code)) return null;
        $find = $db->prepare('SELECT * FROM inventory_signed_documents WHERE code = ?');
        $find->execute([$code]);
        return $find->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** The address the QR opens: this SLiMS's verification page for the code. */
    public static function url(string $code): string
    {
        $base = defined('SWB') ? (string) SWB : '/';
        return PublicLink::scheme() . '://' . PublicLink::host() . $base . 'index.php?' . http_build_query(['p' => self::PAGE, 'c' => $code]);
    }

    /** The block printed at the end of a document: QR code, signer, time and the start of the fingerprint. */
    public static function block(array $record, string $url): string
    {
        $e = static fn($value): string => PdfLayout::e($value);
        return '<table style="width:100%;margin-top:8mm;border:0.3mm solid #6b7280;page-break-inside:avoid;font-size:8.8pt;" autosize="1"><tr>'
            . '<td style="width:30mm;padding:2.5mm;vertical-align:middle;text-align:center;"><barcode code="' . $e($url) . '" type="QR" error="M" disableborder="1" size="0.9" /></td>'
            . '<td style="padding:2.5mm;vertical-align:middle;">'
            . '<b>Ditandatangani secara elektronik</b><br>'
            . 'Oleh: ' . $e($record['signer_name']) . '<br>'
            . 'Pada: ' . PdfLayout::date(substr((string) $record['signed_at'], 0, 10)) . ' ' . $e(substr((string) $record['signed_at'], 11, 5)) . '<br>'
            . 'Kode: <b>' . $e(strtoupper(implode('-', str_split((string) $record['code'], 4)))) . '</b> · SHA-256: ' . $e(substr((string) $record['content_hash'], 0, 16)) . '…<br>'
            . '<span style="font-size:8pt;color:#555555;">Pindai kode QR untuk memeriksa keaslian dokumen ini di situs perpustakaan.</span>'
            . '</td></tr></table>';
    }

    /**
     * Signs the document and adds the block to the end of the PDF. A failure (the table is not
     * there yet, no QR library) leaves the document as it was: printing does not depend on it.
     */
    public static function stamp(\Mpdf\Mpdf $pdf, PDO $db, string $type, string $title, string $scope, string $signer, ?int $signerId, string $content, int $count): ?array
    {
        try {
            if (!class_exists(\Mpdf\QrCode\QrCode::class)) return null;
            $record = self::sign($db, $type, $title, $scope, $signer !== '' ? $signer : 'Petugas perpustakaan', $signerId, $content, $count);
            $pdf->WriteHTML(self::block($record, self::url((string) $record['code'])));
            return $record;
        } catch (\Throwable $error) {
            error_log('Inventory document signature: ' . $error->getMessage());
            return null;
        }
    }
}
