<?php
require __DIR__.'/../src/HistoryWorkbook.php';
use SLiMS\Plugins\Inventory\HistoryWorkbook as X;
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "ok $label\n";}
$path=tempnam(sys_get_temp_dir(),'history-test-');
$check=['HIST-001','1','2025-08-15','1','1','Checklist','Sarana','Kursi & meja <utama>','','Baik','','','',''];
$base=['Pemeriksaan'=>[X::CHECKS,$check],'Tindak_lanjut'=>[X::ACTIONS]];
$reject=function($fn,$label){try{$fn();}catch(RuntimeException $e){check(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);};
$patch=function($fn)use($path,$base){file_put_contents($path,X::write($base));$zip=new ZipArchive();$zip->open($path);$fn($zip);$zip->close();};
try {
 file_put_contents($path,X::template());check(X::read($path)===['Pemeriksaan'=>[],'Tindak_lanjut'=>[]],'blank template roundtrip; examples ignored');
 file_put_contents($path,X::write($base));$rows=X::read($path);check($rows['Pemeriksaan'][0]['objek']===$check[7]&&$rows['Pemeriksaan'][0]['_row']===2,'Unicode/XML text and row number preserved');
 $patch(function($z){$s=$z->getFromName('xl/worksheets/sheet1.xml');$s=str_replace('<c r="C2" t="inlineStr"><is><t xml:space="preserve">2025-08-15</t></is></c>','<c r="C2"><v>45884</v></c>',$s);$z->addFromString('xl/worksheets/sheet1.xml',$s);});check(X::read($path)['Pemeriksaan'][0]['tanggal_pemeriksaan']==='2025-08-15','Excel numeric dates normalized');
 $patch(function($z){$s=$z->getFromName('xl/worksheets/sheet1.xml');$s=str_replace('<c r="H2" t="inlineStr">','<c r="H2" t="inlineStr"><f>1+1</f>',$s);$z->addFromString('xl/worksheets/sheet1.xml',$s);});$reject(fn()=>X::read($path),'formulas rejected');
 $patch(function($z){$s=$z->getFromName('xl/worksheets/sheet1.xml');$s=str_replace('<worksheet','<!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/passwd">]><worksheet',$s);$z->addFromString('xl/worksheets/sheet1.xml',$s);});$reject(fn()=>X::read($path),'external entities rejected');
 $doctype='<!DOCTYPE x [<!ENTITY a "AAAAAAAAAA"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]><worksheet';
 $patch(function($z)use($doctype){$s=str_replace(['<worksheet','>Checklist<'],['<?xml version="1.0" encoding="UTF-16"?>'.$doctype,'>&b;<'],$z->getFromName('xl/worksheets/sheet1.xml'));$z->addFromString('xl/worksheets/sheet1.xml',"\xFF\xFE".mb_convert_encoding($s,'UTF-16LE','UTF-8'));});$reject(fn()=>X::read($path),'DOCTYPE hidden in UTF-16 rejected');
 $patch(function($z){$s=$z->getFromName('xl/worksheets/sheet1.xml');$z->addFromString('xl/worksheets/sheet1.xml','<?xml version="1.0" encoding="UTF-7"?>'.str_replace('<worksheet','+ADwAIQ-DOCTYPE x +AFsAXQA+-<worksheet',$s));});$reject(fn()=>X::read($path),'XML declared in another encoding rejected');
 $patch(function($z){$s=$z->getFromName('xl/worksheets/sheet1.xml');$z->addFromString('xl/worksheets/sheet1.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\r\n".$s);});check(count(X::read($path)['Pemeriksaan'])===1,'UTF-8 declaration as Excel writes it accepted');
 $patch(function($z){$z->addFromString('xl/vbaProject.bin','macro');});$reject(fn()=>X::read($path),'macro payload rejected');
 $patch(function($z){$z->addFromString('bomb.xml',str_repeat('a',8*1024*1024));});$reject(fn()=>X::read($path),'ZIP inflated size bounded');
 $patch(function($z){$s=$z->getFromName('xl/workbook.xml');$s=str_replace('<sheets>','<workbookPr date1904="1"/><sheets>',$s);$z->addFromString('xl/workbook.xml',$s);});$reject(fn()=>X::read($path),'unsupported date epoch rejected');
 $bad=$base;$bad['Pemeriksaan'][0][0]='wrong';file_put_contents($path,X::write($bad));$reject(fn()=>X::read($path),'wrong headers rejected');
 file_put_contents($path,'not a zip');$reject(fn()=>X::read($path),'invalid workbook rejected');
} finally {unlink($path);}
