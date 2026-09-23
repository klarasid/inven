<?php
namespace SLiMS\Plugins\Inventory;

/** Restricted XLSX interchange: values only, no formulas, macros or external resources. */
final class HistoryWorkbook
{
    public const CHECKS = ['nomor_pemeriksaan','nomor_butir','tanggal_pemeriksaan','id_ruangan','id_pemeriksa','nama_checklist','kelompok','objek','id_barang','hasil','catatan','id_penanggung_jawab','prioritas','tenggat'];
    public const ACTIONS = ['nomor_pemeriksaan','nomor_butir','tanggal_pekerjaan','jenis_tindakan','id_pelaksana','uraian','biaya','referensi_bukti','id_verifikator','tanggal_verifikasi','catatan_verifikasi'];
    private static function xml(string $text): \SimpleXMLElement {
        if (stripos($text,'<!DOCTYPE')!==false || stripos($text,'<!ENTITY')!==false) throw new \RuntimeException('XML Excel tidak diizinkan.');
        $previous=libxml_use_internal_errors(true);
        try { $xml=simplexml_load_string($text,\SimpleXMLElement::class,LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$xml) throw new \RuntimeException('Struktur XML Excel rusak.');
        return $xml;
    }
    public static function read(string $path): array {
        if (filesize($path)>2*1024*1024) throw new \RuntimeException('Excel maksimal 2 MB.');
        $zip=new \ZipArchive();
        if ($zip->open($path)!==true) throw new \RuntimeException('Unggah berkas Excel .xlsx yang valid.');
        try {
            $size=0;
            if ($zip->numFiles>100) throw new \RuntimeException('Excel terlalu kompleks. Gunakan template.');
            for($i=0;$i<$zip->numFiles;$i++) {
                $stat=$zip->statIndex($i); $size+=$stat['size'];
                if ($size>8*1024*1024 || stripos($stat['name'],'vbaProject')!==false) throw new \RuntimeException('Excel terlalu besar atau memuat makro.');
            }
            $get=static function(string $name) use($zip): \SimpleXMLElement {
                $bytes=$zip->getFromName($name);
                if ($bytes===false) throw new \RuntimeException('Komponen Excel tidak ditemukan: '.$name);
                return self::xml($bytes);
            };
            $book=$get('xl/workbook.xml');
            if ((string)$book->workbookPr['date1904']==='1' || (string)$book->workbookPr['date1904']==='true') throw new \RuntimeException('Gunakan sistem tanggal Excel 1900 atau tanggal teks YYYY-MM-DD.');
            $relations=[];
            foreach($get('xl/_rels/workbook.xml.rels')->Relationship as $rel) {
                if ((string)$rel['TargetMode']==='External') continue;
                $target=(string)$rel['Target'];
                if (strpos($target,'..')!==false || strpos($target,'\\')!==false) throw new \RuntimeException('Relasi Excel tidak valid.');
                $relations[(string)$rel['Id']]=strpos($target,'/')===0?ltrim($target,'/'):'xl/'.$target;
            }
            $strings=[];
            if ($zip->locateName('xl/sharedStrings.xml')!==false) foreach($get('xl/sharedStrings.xml')->si as $si) {
                $strings[]=implode('',array_map('strval',$si->xpath('.//*[local-name()="t"]')));
            }
            $sheets=[];
            foreach($book->sheets->sheet as $sheet) {
                $name=(string)$sheet['name'];
                if (!in_array($name,['Pemeriksaan','Tindak_lanjut'],true)) continue;
                if(isset($sheets[$name])) throw new \RuntimeException('Nama lembar duplikat.');
                $rid=(string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                if (!isset($relations[$rid])) throw new \RuntimeException('Lembar Excel tidak ditemukan.');
                $rows=[]; $expected=$name==='Pemeriksaan'?self::CHECKS:self::ACTIONS;
                foreach($get($relations[$rid])->sheetData->row as $row) {
                    $number=(int)$row['r'];
                    if ($number<1 || $number>1001 || isset($rows[$number])) throw new \RuntimeException($name.': maksimal 1000 baris dan nomor baris harus unik.');
                    $values=array_fill(0,count($expected),''); $seen=[];
                    foreach($row->c as $cell) {
                        if (isset($cell->f)) throw new \RuntimeException($name.' baris '.$number.': rumus tidak didukung; tempel sebagai nilai.');
                        if (!preg_match('/\A([A-Z]+)'.$number.'\z/',(string)$cell['r'],$match)) throw new \RuntimeException('Alamat sel Excel tidak valid.');
                        $col=0;foreach(str_split($match[1]) as $char) $col=$col*26+ord($char)-64;
                        if(isset($seen[$col])) throw new \RuntimeException('Sel Excel duplikat.');$seen[$col]=true;
                        $type=(string)$cell['t'];
                        if ($type==='s') {
                            $key=(string)$cell->v;
                            if (!ctype_digit($key)||!isset($strings[(int)$key])) throw new \RuntimeException('Teks Excel tidak valid.');
                            $value=$strings[(int)$key];
                        } elseif ($type==='inlineStr') $value=implode('',array_map('strval',$cell->is->xpath('.//*[local-name()="t"]')));
                        elseif ($type===''||$type==='n'||$type==='str'||$type==='d') $value=(string)$cell->v;
                        else throw new \RuntimeException($name.' baris '.$number.': tipe sel tidak didukung.');
                        $value=trim($value);
                        if($col>count($expected)) { if($value!=='') throw new \RuntimeException($name.': kolom tambahan tidak didukung.'); continue; }
                        if(mb_strlen($value)>5000) throw new \RuntimeException($name.' baris '.$number.': isi sel terlalu panjang.');
                        if($number>1 && ($type===''||$type==='n') && preg_match('/\A[0-9]+(?:\.0+)?\z/',$value) && (strpos($expected[$col-1],'tanggal_')===0||$expected[$col-1]==='tenggat')) {
                            $serial=(int)$value;
                            if($serial<61||$serial>2958465) throw new \RuntimeException('Tanggal Excel di luar batas.');
                            $value=(new \DateTimeImmutable('1899-12-30'))->modify('+'.$serial.' days')->format('Y-m-d');
                        }
                        $values[$col-1]=$value;
                    }
                    $rows[$number]=$values;
                }
                if (($rows[1]??[])!==$expected) throw new \RuntimeException('Header '.$name.' tidak sesuai template. Jangan ubah nama atau urutan kolom.');
                unset($rows[1]);ksort($rows);$sheets[$name]=[];
                foreach($rows as $n=>$values) if(count(array_filter($values,static fn($v)=>$v!==''))) $sheets[$name][]=array_combine($expected,$values)+['_row'=>$n];
            }
            if(!isset($sheets['Pemeriksaan'],$sheets['Tindak_lanjut'])) throw new \RuntimeException('Lembar Pemeriksaan dan Tindak_lanjut wajib tersedia.');
            return $sheets;
        } finally {$zip->close();}
    }
    public static function write(array $sheets): string {
        $path=tempnam(sys_get_temp_dir(),'inventory-xlsx-'); $zip=new \ZipArchive();
        try {
            if($zip->open($path,\ZipArchive::OVERWRITE)!==true) throw new \RuntimeException('Template tidak dapat dibuat.');
            $esc=static fn($v)=>htmlspecialchars((string)$v,ENT_XML1|ENT_QUOTES,'UTF-8');
            $ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $types='<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
            $book='<workbook xmlns="'.$ns.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';$rels='<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';$i=0;
            foreach($sheets as $name=>$rows) {
                ++$i;$book.='<sheet name="'.$esc($name).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
                $rels.='<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
                $types.='<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $xml='<worksheet xmlns="'.$ns.'"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="20" width="24" customWidth="1"/></cols><sheetData>';
                foreach($rows as $n=>$row) {
                    $xml.='<row r="'.($n+1).'">';
                    foreach(array_values($row) as $c=>$value) {
                        $col='';for($v=$c+1;$v>0;$v=intdiv($v-1,26))$col=chr(65+($v-1)%26).$col;
                        $xml.='<c r="'.$col.($n+1).'" t="inlineStr"><is><t xml:space="preserve">'.$esc($value).'</t></is></c>';
                    }$xml.='</row>';
                }
                $zip->addFromString('xl/worksheets/sheet'.$i.'.xml',$xml.'</sheetData></worksheet>');
            }
            $zip->addFromString('[Content_Types].xml',$types.'</Types>');$zip->addFromString('xl/workbook.xml',$book.'</sheets></workbook>');$zip->addFromString('xl/_rels/workbook.xml.rels',$rels.'</Relationships>');
            $zip->addFromString('_rels/.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->close();return file_get_contents($path);
        } finally {if(is_file($path))unlink($path);}
    }
    public static function template(array $rooms=[],array $users=[],array $items=[]): string {
        return self::write([
            'Pemeriksaan'=>[self::CHECKS], 'Tindak_lanjut'=>[self::ACTIONS],
            'Petunjuk'=>[
                ['Bagian','Petunjuk'],
                ['Mulai','Isi Pemeriksaan dan Tindak_lanjut. Contoh di lembar Contoh tidak diimpor. Hapus contoh jika disalin sebelum mengisi data asli.'],
                ['Batas','XLSX maksimal 2 MB, 100 pemeriksaan, 500 butir, 500 tindakan. Tanpa rumus, makro, atau foto tertanam.'],
                ['Identitas','nomor_pemeriksaan unik permanen (huruf, angka, - atau _); nomor_butir angka unik dalam pemeriksaan. Gunakan nomor yang sama untuk menghubungkan tindakan.'],
                ['Pemeriksaan','Satu baris per butir. Semua butir satu pemeriksaan harus memakai tanggal, ruang, pemeriksa dan nama checklist yang sama.'],
                ['Ruang & petugas','Salin ID dari lembar referensi. ID wajib cocok data sistem; nama lokasi dan ruang hanya panduan. id_barang opsional, harus berada di ruang terpilih.'],
                ['Tanggal','YYYY-MM-DD, contoh 2025-08-15. Tanggal Excel biasa juga diterima (sistem 1900). Pelaksanaan dan verifikasi tidak boleh di masa depan.'],
                ['Kelompok','Sarana / Prasarana / Lingkungan Fisik'],
                ['Hasil','Baik / Perlu tindakan / Tidak diperiksa / Tidak berlaku. Selain Baik wajib catatan.'],
                ['Temuan','Perlu tindakan wajib id_penanggung_jawab, prioritas (Rendah/Sedang/Tinggi), tenggat. Kolom ini kosong untuk hasil lain.'],
                ['Tindak lanjut','Opsional, satu baris per butir dengan hasil Perlu tindakan. Ringkas pekerjaan terkait dalam uraian. jenis_tindakan: Perbaikan / Pemeliharaan / Tanpa pekerjaan.'],
                ['Biaya','Opsional, angka tanpa Rp atau pemisah ribuan, contoh 150000 atau 150000.50.'],
                ['Bukti','referensi_bukti opsional: nomor arsip atau keterangan lokasi bukti. Foto tidak diimpor dan tautan tidak diunduh otomatis.'],
                ['Verifikasi','Isi id_verifikator, tanggal_verifikasi dan catatan_verifikasi hanya bila benar-benar telah diverifikasi. Ketiganya wajib bersama; jika kosong masuk Menunggu verifikasi.'],
                ['Histori','Tanggal asli dipertahankan; waktu impor dan pengimpor dicatat terpisah. Tidak membuat jadwal berulang atau menambah template checklist.'],
                ['Penyimpanan','Validasi dahulu, periksa pratinjau, lalu Simpan impor. Satu kesalahan membatalkan seluruh berkas. Nomor yang sudah diimpor ditolak untuk mencegah duplikasi.'],
            ],
            'Referensi_ruang'=>array_merge([['id_ruangan','nama_ruangan','kode_lokasi','nama_lokasi']],$rooms),
            'Referensi_petugas'=>array_merge([['id_petugas','nama_petugas']],$users),
            'Referensi_barang'=>array_merge([['id_barang','id_ruangan','kode_barang','nama_barang']],$items),
            'Contoh_pemeriksaan'=>[self::CHECKS,['HIST-CONTOH-001','1','2025-08-15','1','1','Pemeriksaan ruangan','Sarana','Kursi','','Perlu tindakan','Kaki kursi longgar','1','Sedang','2025-08-20']],
            'Contoh_tindak_lanjut'=>[self::ACTIONS,['HIST-CONTOH-001','1','2025-08-16','Perbaikan','1','Mengencangkan kaki kursi','50000','Arsip perbaikan Agustus 2025','','','']],
        ]);
    }
}
