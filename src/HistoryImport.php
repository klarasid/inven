<?php
namespace SLiMS\Plugins\Inventory;

/** Archival intake, separate from live inspection and verification transitions. */
final class HistoryImport
{
    private \PDO $db;
    private Supervision $watch;
    public function __construct(\PDO $db,Supervision $watch) {$this->db=$db;$this->watch=$watch;}
    private function query(string $sql,array $args=[]): \PDOStatement {return $this->watch->query($sql,$args);}
    private static function fail(array $row,string $message,string $sheet='Pemeriksaan'): void {throw new \RuntimeException($sheet.' baris '.$row['_row'].': '.$message);}
    private static function date(array $row,string $key,bool $future=false,string $sheet='Pemeriksaan'): string {
        $value=$row[$key];
        try {$date=WatchRecurrence::date($value)->format('Y-m-d');} catch(\RuntimeException $e) {self::fail($row,$key.' harus tanggal YYYY-MM-DD.',$sheet);}
        if(!$future && $date>date('Y-m-d')) self::fail($row,$key.' tidak boleh di masa depan.',$sheet);
        return $date;
    }
    private static function id(array $row,string $key,array $records,string $sheet='Pemeriksaan'): array {
        $value=$row[$key];
        if(!preg_match('/\A[1-9][0-9]{0,9}\z/',$value)||!isset($records[$value])) self::fail($row,$key.' tidak ditemukan. Gunakan ID dari lembar referensi.',$sheet);
        return $records[$value];
    }
    private static function text(array $row,string $key,int $limit=255,string $sheet='Pemeriksaan'): string {
        if($row[$key]===''||mb_strlen($row[$key])>$limit) self::fail($row,$key.' wajib diisi, maksimal '.$limit.' karakter.',$sheet);
        return $row[$key];
    }
    public function validate(array $sheets,bool $lock=false): array {
        $checks=$sheets['Pemeriksaan']??[];$actions=$sheets['Tindak_lanjut']??[];
        if(!$checks||count($checks)>500||count($actions)>500) throw new \RuntimeException('Isi 1–500 butir pemeriksaan dan maksimal 500 tindakan.');
        $rooms=[];foreach($this->query('SELECT l.*,ml.location_name AS library_name FROM inventory_locations l LEFT JOIN mst_location ml ON ml.location_id=l.slims_location_id ORDER BY l.id'.($lock?' FOR UPDATE':''))->fetchAll(\PDO::FETCH_ASSOC) as $r)$rooms[$r['id']]=$r;
        $users=[];foreach($this->query('SELECT user_id AS id,realname AS name FROM user')->fetchAll(\PDO::FETCH_ASSOC) as $r)$users[$r['id']]=$r;
        $groups=[];$outcomes=array_flip(Supervision::OUTCOMES);$priorities=array_flip(Supervision::PRIORITIES);
        foreach($checks as $r) {
            $ref=$r['nomor_pemeriksaan'];$number=$r['nomor_butir'];
            if(!preg_match('/\A[A-Za-z0-9_-]{1,80}\z/',$ref)) self::fail($r,'nomor_pemeriksaan harus 1–80 huruf, angka, - atau _.');
            $ref=strtoupper($ref);
            if(!preg_match('/\A[1-9][0-9]{0,3}\z/',$number)) self::fail($r,'nomor_butir harus angka 1–9999.');
            $room=self::id($r,'id_ruangan',$rooms);$examiner=self::id($r,'id_pemeriksa',$users);$date=self::date($r,'tanggal_pemeriksaan');
            $name=self::text($r,'nama_checklist');$object=self::text($r,'objek');
            if(!in_array($r['kelompok'],['Sarana','Prasarana','Lingkungan Fisik'],true)) self::fail($r,'kelompok tidak dikenal.');
            if(!isset($outcomes[$r['hasil']])) self::fail($r,'hasil tidak dikenal.');
            $outcome=$outcomes[$r['hasil']];
            if($outcome!=='good')self::text($r,'catatan',5000);
            $assignee=null;$priority=null;$deadline=null;
            if($outcome==='action') {
                $assignee=self::id($r,'id_penanggung_jawab',$users);
                if(!isset($priorities[$r['prioritas']]))self::fail($r,'prioritas tidak dikenal.');
                $priority=$priorities[$r['prioritas']];$deadline=self::date($r,'tenggat',true);
                if($deadline<$date)self::fail($r,'tenggat mendahului pemeriksaan.');
            } elseif($r['id_penanggung_jawab']!==''||$r['prioritas']!==''||$r['tenggat']!=='') self::fail($r,'kolom penanggung jawab, prioritas dan tenggat hanya untuk Perlu tindakan.');
            $item=['group'=>$r['kelompok'],'object'=>$object,'instruction'=>'Pencatatan hasil pemeriksaan historis.','item_id'=>null,'item_name'=>'','item_code'=>''];
            if($r['id_barang']!=='') {
                if(!preg_match('/\A[1-9][0-9]{0,9}\z/',$r['id_barang']))self::fail($r,'id_barang tidak valid.');
                $asset=$this->query('SELECT id,item_name,item_code FROM inventory_items WHERE id=? AND location_id=?'.($lock?' FOR UPDATE':''),[$r['id_barang'],$room['id']])->fetch(\PDO::FETCH_ASSOC);
                if(!$asset)self::fail($r,'barang tidak ditemukan di ruangan tersebut.');
                $item['item_id']=(int)$asset['id'];$item['item_name']=$asset['item_name'];$item['item_code']=$asset['item_code'];
            }
            if(!isset($groups[$ref])) {
                if($this->query("SELECT id FROM inventory_watch_inspections WHERE kind='historical' AND reason=? LIMIT 1",['Impor riwayat: '.$ref])->fetchColumn())self::fail($r,'nomor '.$ref.' sudah pernah diimpor.');
                $scope=['room_id'=>(int)$room['id'],'room_name'=>$room['room_name'],'location_code'=>$room['location_code'],'library_code'=>$room['slims_location_id']??'','library_name'=>$room['library_name']??'Tidak ditentukan','template_id'=>null,'template_name'=>$name,'items'=>[],'assignee'=>$examiner,'import_ref'=>$ref];
                $groups[$ref]=['ref'=>$ref,'date'=>$date,'scope'=>$scope,'examiner'=>$examiner,'results'=>[]];
            }
            $g=&$groups[$ref];
            if($g['date']!==$date||$g['scope']['room_id']!==(int)$room['id']||$g['examiner']['id']!==$examiner['id']||$g['scope']['template_name']!==$name)self::fail($r,'tanggal, ruang, pemeriksa dan checklist harus sama untuk nomor pemeriksaan yang sama.');
            if(isset($g['results'][$number]))self::fail($r,'nomor_butir duplikat dalam pemeriksaan.');
            $g['results'][$number]=['item'=>$item,'outcome'=>$outcome,'notes'=>$r['catatan'],'assignee'=>$assignee,'priority'=>$priority,'deadline'=>$deadline,'action'=>null];
            if(count($g['results'])>100)self::fail($r,'maksimal 100 butir per pemeriksaan.');
            unset($g);
        }
        if(count($groups)>100)throw new \RuntimeException('Maksimal 100 pemeriksaan per berkas.');
        $kinds=['Perbaikan'=>'repair','Pemeliharaan'=>'maintenance','Tanpa pekerjaan'=>'none'];
        foreach($actions as $r) {
            $ref=strtoupper($r['nomor_pemeriksaan']);$number=$r['nomor_butir'];$sheet='Tindak_lanjut';
            if(!isset($groups[$ref]['results'][$number]))self::fail($r,'pasangan nomor_pemeriksaan dan nomor_butir tidak ditemukan.',$sheet);
            $g=&$groups[$ref];$result=&$g['results'][$number];
            if($result['outcome']!=='action')self::fail($r,'tindakan harus terkait hasil Perlu tindakan.',$sheet);
            if($result['action']!==null)self::fail($r,'satu butir hanya menerima satu ringkasan tindakan.',$sheet);
            if(!isset($kinds[$r['jenis_tindakan']]))self::fail($r,'jenis_tindakan tidak dikenal.',$sheet);
            $date=self::date($r,'tanggal_pekerjaan',false,$sheet);
            if($date<$g['date'])self::fail($r,'tanggal_pekerjaan mendahului pemeriksaan.',$sheet);
            $actor=self::id($r,'id_pelaksana',$users,$sheet);$description=self::text($r,'uraian',5000,$sheet);
            $cost=$r['biaya'];if($cost!==''&&!preg_match('/\A[0-9]{1,16}(?:\.[0-9]{1,2})?\z/',$cost))self::fail($r,'biaya harus angka tanpa pemisah ribuan, maksimal dua desimal.',$sheet);
            $verifier=null;$verified=null;
            if($r['id_verifikator']!==''||$r['tanggal_verifikasi']!==''||$r['catatan_verifikasi']!=='') {
                $verifier=self::id($r,'id_verifikator',$users,$sheet);$verified=self::date($r,'tanggal_verifikasi',false,$sheet);self::text($r,'catatan_verifikasi',5000,$sheet);
                if($verified<$date)self::fail($r,'tanggal_verifikasi mendahului pekerjaan.',$sheet);
            }
            $result['action']=['kind'=>$kinds[$r['jenis_tindakan']],'date'=>$date,'actor'=>$actor,'description'=>$description,'cost'=>$cost===''?null:$cost,'evidence'=>$r['referensi_bukti'],'verifier'=>$verifier,'verified'=>$verified,'verification_notes'=>$r['catatan_verifikasi']];
            unset($result,$g);
        }
        return array_values($groups);
    }
    public static function preview(array $groups): array {
        $rows=[];$findings=0;$actions=0;$closed=0;$results=0;
        foreach($groups as $g) {
            $n=0;$c=0;foreach($g['results'] as $r){$results++;if($r['outcome']==='action')$findings++;if($r['action']){$actions++;$n++;if($r['action']['verifier']){$closed++;$c++;}}}
            $rows[]=['number'=>$g['ref'],'date'=>$g['date'],'room'=>$g['scope']['room_name'],'library'=>$g['scope']['library_name'],'examiner'=>$g['examiner']['name'],'results'=>count($g['results']),'actions'=>$n,'closed'=>$c];
        }
        return ['rows'=>$rows,'inspections'=>count($groups),'results'=>$results,'findings'=>$findings,'actions'=>$actions,'closed'=>$closed,'review'=>$actions-$closed,'open'=>$findings-$actions];
    }
    public function save(array $sheets,int $uid): array {
        // Serialize all archival imports, including retries from different sessions.
        $lock='inventory_history_'.substr(hash('sha256',(string)$this->query('SELECT DATABASE()')->fetchColumn()),0,32);
        if((int)$this->query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1)throw new \RuntimeException('Impor lain sedang berlangsung. Coba lagi.');
        try {
            $this->db->beginTransaction();$actor=$this->watch->user($uid);$groups=$this->validate($sheets,true);$ids=[];
            foreach($groups as $g) {
                $scope=$g['scope'];$scope['items']=array_column(array_values($g['results']),'item');
                $this->query("INSERT INTO inventory_watch_inspections (location_id,library_code,room_key,kind,due_date,performed_date,status,snapshot,reason,examiner_id,examiner_name,notes,created_at,finalized_at) VALUES (?,?,?,'historical',?,?,'final',?,?,?,?,?,NOW(),NOW())",[$scope['room_id'],$scope['library_code'],$scope['room_id'],$g['date'],$g['date'],Supervision::json($scope),'Impor riwayat: '.$g['ref'],$g['examiner']['id'],$g['examiner']['name'],'Riwayat diimpor; tanggal finalisasi adalah waktu pencatatan di sistem.']);
                $id=(int)$this->db->lastInsertId();$ids[]=$id;$position=0;
                $this->event($id,null,'import_history','Impor riwayat '.$g['ref'].'. Pemeriksa asli: '.$g['examiner']['name'].'. Pelaksanaan: '.$g['date'].'.',$actor);
                foreach($g['results'] as $number=>$r) {
                    $this->query('INSERT INTO inventory_watch_results (inspection_id,position,snapshot,outcome,notes,assignee_id,assignee_name,priority,deadline) VALUES (?,?,?,?,?,?,?,?,?)',[$id,$position++,Supervision::json($r['item']),$r['outcome'],$r['notes'],$r['assignee']['id']??null,$r['assignee']['name']??null,$r['priority'],$r['deadline']]);
                    $rid=(int)$this->db->lastInsertId();if($r['outcome']!=='action')continue;
                    $a=$r['action'];$status=$a?($a['verifier']?'closed':'review'):'open';
                    $this->query('INSERT INTO inventory_watch_findings (inspection_id,result_id,status,assignee_id,assignee_name,priority,deadline,created_at,closed_at) VALUES (?,?,?,?,?,?,?,NOW(),?)',[$id,$rid,$status,$r['assignee']['id'],$r['assignee']['name'],$r['priority'],$r['deadline'],$a&&$a['verified']?$a['verified'].' 00:00:00':null]);
                    $fid=(int)$this->db->lastInsertId();
                    $this->event($id,$fid,'import_history','Temuan historis '.$g['ref'].' butir '.$number.'.',$actor);
                    if(!$a)continue;
                    $description=$a['description'].($a['evidence']!==''?"\nReferensi bukti arsip: ".$a['evidence']:'');
                    $this->query('INSERT INTO inventory_watch_actions (finding_id,kind,description,performed_date,actor_id,actor_name,cost,submitted_at,created_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())',[$fid,$a['kind'],$description,$a['date'],$a['actor']['id'],$a['actor']['name'],$a['cost']]);
                    $this->event($id,$fid,'import_action','Pekerjaan historis oleh '.$a['actor']['name'].' pada '.$a['date'].'. Foto tidak diimpor. '.($a['evidence']!==''?'Referensi: '.$a['evidence']:'Referensi bukti tidak disertakan.'),$actor);
                    if($a['verifier'])$this->event($id,$fid,'import_verification','Verifikasi historis oleh '.$a['verifier']['name'].' (ID '.$a['verifier']['id'].') pada '.$a['verified'].': '.$a['verification_notes'],$actor);
                }
            }
            $this->db->commit();
            require_once __DIR__.'/UpdateCheck.php';require_once __DIR__.'/Telemetry.php';Telemetry::count('history_import');
            return self::preview($groups)+['ids'=>$ids];
        } catch(\Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        finally {$this->query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    private function event(int $id,?int $finding,string $event,string $notes,array $actor): void {
        $this->query('INSERT INTO inventory_watch_events (inspection_id,finding_id,event,notes,actor_id,actor_name,created_at) VALUES (?,?,?,?,?,?,NOW())',[$id,$finding,$event,$notes,$actor['id'],$actor['name']]);
    }
}
