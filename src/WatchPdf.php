<?php
namespace SLiMS\Plugins\Inventory;

final class WatchPdf
{
    private static function e($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
    private static function style(): string { return '<style>body{font-family: sans-serif;font-size:10pt}h1{font-size:16pt}h2{font-size:13pt}h3{font-size:11pt}table{border-collapse:collapse;width:100%;margin-bottom:12px}th,td{border:1px solid #aaa;padding:5px;vertical-align:top}th{background:#eee}.photo{width:120px;margin:4px}p{margin:5px 0}</style>'; }
    public static function summary(array $filter,array $summary,array $rows): string {
        if(count($rows)>500)throw new \RuntimeException('Laporan melebihi 500 pemeriksaan. Persempit periode.');
        $h=self::style().'<h1>Pengawasan &amp; Pemeliharaan Perpustakaan</h1><p>Periode jadwal: '.self::e($filter['from']).' s.d. '.self::e($filter['to']).'</p><p>Filter perpustakaan: '.self::e($filter['library']?:'Semua').' · ID ruangan: '.self::e($filter['room']?:'Semua').'</p><p>Dicetak: '.date('Y-m-d H:i:s').'</p>';
        $c=$summary['counts'];$f=$summary['findings'];
        $h.='<h2>Realisasi dan cakupan</h2><table><tr><th>Indikator</th><th>Jumlah</th></tr>';
        foreach(['Rencana pemeriksaan rutin'=>$summary['planned'],'Rutin difinalisasi'=>$c['routine_final'],'Insidental tercatat'=>$c['incidental'],'Pemeriksaan terlambat'=>(int)$c['late']+$summary['unformed_late'],'Jatuh tempo belum dibentuk'=>$summary['unformed'],'Ruangan diperiksa / dalam jadwal'=>$summary['room_examined'].' / '.$summary['room_total'],'Butir diperiksa / berlaku'=>$summary['item_examined'].' / '.$summary['item_applicable'],'Butir tidak berlaku'=>$summary['item_na'],'Temuan belum selesai'=>$f['open'],'Temuan lewat tenggat'=>$f['late'],'Selesai terverifikasi'=>$f['closed']]as$label=>$value)$h.='<tr><td>'.self::e($label).'</td><td>'.self::e($value).'</td></tr>';
        $h.='</table><p>Cakupan butir menghitung pemeriksaan rutin terbentuk dan jadwal yang jatuh tempo. Hanya hasil final dihitung diperiksa. Tidak diperiksa tetap dalam penyebut; Tidak berlaku dikeluarkan. Status temuan adalah status saat cetak dari pemeriksaan pada periode ini.</p><h2>Ruangan tanpa jadwal dalam periode</h2>';
        if(!$summary['missing_rooms'])$h.='<p>Tidak ada.</p>';
        foreach($summary['missing_rooms']as$room)$h.='<p>'.self::e($room['room_name']).' — '.self::e($room['location_name']??'Tidak ditentukan').'</p>';
        $h.='<h2>Jadwal versus realisasi</h2><table><tr><th>ID / ruangan</th><th>Jenis / jadwal</th><th>Pelaksanaan / pemeriksa</th><th>Status</th></tr>';
        foreach($rows as$row){$s=Supervision::decode($row['snapshot']);$h.='<tr><td>#'.(int)$row['id'].' '.self::e($s['room_name']).'<br>'.self::e($s['library_name']).'</td><td>'.($row['kind']==='routine'?'Rutin':($row['kind']==='incidental'?'Insidental':'Pemeriksaan')).'<br>'.self::e($row['due_date']).'</td><td>'.self::e($row['performed_date']??'Belum dilaksanakan').'<br>'.self::e($row['examiner_name']??'—').'</td><td>'.self::e(Supervision::STATUSES[$row['status']]).'</td></tr>';}
        $h.='</table><h2>Daftar rencana jadwal</h2><table><tr><th>Ruangan / template</th><th>Frekuensi</th><th>Rencana / terbentuk / final</th></tr>';
        foreach($summary['schedules']??[]as$row)$h.='<tr><td>'.self::e($row['room']).'<br>'.self::e($row['template']).'</td><td>'.self::e($row['frequency']).'</td><td>'.$row['planned'].' / '.$row['formed'].' / '.$row['final'].'</td></tr>';
        $h.='</table><h2>Temuan dan tindak lanjut</h2><table><tr><th>Temuan / ruangan</th><th>Penanggung jawab / tenggat</th><th>Status</th></tr>';
        foreach($summary['finding_rows']??[]as$row)$h.='<tr><td>#'.(int)$row['id'].' '.self::e($row['object']).'<br>'.self::e($row['room']).'</td><td>'.self::e($row['assignee_name']).'<br>'.self::e($row['deadline']).'</td><td>'.self::e(Supervision::STATUSES[$row['status']]).'</td></tr>';
        return $h.'</table><p>Laporan menyediakan bukti pengawasan; tidak memberikan nilai a–d otomatis.</p>';
    }
    public static function detail(array $document,callable $readPhoto): string {
        $i=$document['inspection'];$s=$document['snapshot'];
        if(count($document['photos'])>500)throw new \RuntimeException('Detail memiliki lebih dari 500 foto. Hubungi administrator untuk ekspor arsip.');
        $h=self::style().'<h1>Dokumen Pemeriksaan #'.(int)$i['id'].'</h1><p>'.self::e($s['library_name'].' — '.$s['room_name']).'</p><p>Checklist: '.self::e($s['template_name']).' · Status: '.self::e(Supervision::STATUSES[$i['status']]).'</p><p>Jenis: '.($i['kind']==='routine'?'Terjadwal':($i['kind']==='incidental'?'Insidental':'Pemeriksaan')).' · Jadwal: '.self::e($i['due_date']).' · Pelaksanaan: '.self::e($i['performed_date']??'—').'</p><p>Pemeriksa: '.self::e($i['examiner_name']??'—').'</p><p>'.nl2br(self::e($i['reason'])).'</p><p>'.nl2br(self::e($i['notes'])).'</p>';
        if($i['parent_id'])$h.='<p>Pemeriksaan ulang dari #'.(int)$i['parent_id'].'</p>';
        $photos=function(?int $result,?int $action)use($document,$readPhoto):string{
            $html='';
            foreach($document['photos']as$photo){
                if(($result!==null&&(int)$photo['result_id']!==$result)||($action!==null&&(int)$photo['action_id']!==$action))continue;
                $bytes=$readPhoto($photo);if($bytes===null){$html.='<p>Berkas foto tidak tersedia.</p>';continue;}
                $image=@imagecreatefromstring($bytes);if(!$image){$html.='<p>Foto tidak dapat dibaca.</p>';continue;}
                $scale=min(1,320/max(imagesx($image),imagesy($image)));$thumb=imagecreatetruecolor(max(1,(int)(imagesx($image)*$scale)),max(1,(int)(imagesy($image)*$scale)));
                imagecopyresampled($thumb,$image,0,0,0,0,imagesx($thumb),imagesy($thumb),imagesx($image),imagesy($image));
                ob_start();imagejpeg($thumb,null,75);$jpeg=ob_get_clean();imagedestroy($thumb);imagedestroy($image);
                $html.='<img class="photo" src="data:image/jpeg;base64,'.base64_encode($jpeg).'">';
            }
            return $html;
        };
        foreach($document['results']as$r){$item=Supervision::decode($r['snapshot']);$h.='<h2>'.self::e($item['group'].' — '.$item['object']).'</h2><p>'.self::e($item['instruction']).'</p><p>Objek: '.self::e($item['item_id']?$item['item_name'].' ['.$item['item_code'].']':'Aspek ruangan').'</p><p>Hasil: <strong>'.self::e(Supervision::OUTCOMES[$r['outcome']]??'Belum diisi').'</strong></p><p>'.nl2br(self::e($r['notes'])).'</p>'.$photos((int)$r['id'],null);}
        $h.='<h2>Temuan dan tindak lanjut</h2>';
        if(!$document['findings'])$h.='<p>Tidak ada temuan.</p>';
        foreach($document['findings']as$f){$h.='<h3>Temuan #'.(int)$f['id'].' · '.self::e(Supervision::STATUSES[$f['status']]).'</h3><p>Penanggung jawab: '.self::e($f['assignee_name']).' · Prioritas: '.self::e(Supervision::PRIORITIES[$f['priority']]).' · Tenggat: '.self::e($f['deadline']).'</p>';
            foreach($document['actions']as$a){if((int)$a['finding_id']!==(int)$f['id'])continue;$h.='<p><strong>'.self::e(['repair'=>'Perbaikan','maintenance'=>'Pemeliharaan','none'=>'Tanpa pekerjaan'][$a['kind']]).'</strong> · '.self::e($a['performed_date']).' · '.self::e($a['actor_name']).' · '.($a['submitted_at']?'Diajukan':'Draf').'</p><p>'.nl2br(self::e($a['description'])).'</p><p>Biaya: '.self::e($a['cost']??'—').'</p>'.$photos(null,(int)$a['id']);}}
        $h.='<h2>Riwayat dan verifikasi</h2>';
        foreach($document['events']as$event)$h.='<p><strong>'.self::e(['verify'=>'Verifikasi diterima','reject'=>'Verifikasi ditolak','correction'=>'Catatan koreksi','finalize'=>'Finalisasi','start'=>'Mulai pekerjaan','submit'=>'Ajukan verifikasi','save_draft'=>'Simpan draf','save_action'=>'Simpan tindakan'][$event['event']]??$event['event']).'</strong> · '.self::e($event['actor_name']).' · '.self::e($event['created_at']).'</p><p>'.nl2br(self::e($event['notes'])).'</p>';
        return $h;
    }
}
