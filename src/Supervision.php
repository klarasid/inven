<?php
namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

final class Supervision
{
    public const OUTCOMES = ['good'=>'Baik','action'=>'Perlu tindakan','unchecked'=>'Tidak diperiksa','na'=>'Tidak berlaku'];
    public const PRIORITIES = ['low'=>'Rendah','medium'=>'Sedang','high'=>'Tinggi'];
    public const STATUSES = ['pending'=>'Belum dimulai','draft'=>'Draf','final'=>'Difinalisasi','open'=>'Terbuka','working'=>'Dikerjakan','review'=>'Menunggu verifikasi','closed'=>'Selesai'];
    private PDO $db;
    private PhotoStorage $storage;
    private array $created = [], $removed = [];
    public function __construct(PDO $db, PhotoStorage $storage) { $this->db=$db; $this->storage=$storage; }
    public static function json(array $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
    public static function decode(string $data): array { return json_decode($data,true,512,JSON_THROW_ON_ERROR); }
    public function query(string $sql, array $args=[]): \PDOStatement { $q=$this->db->prepare($sql); $q->execute($args); return $q; }
    private function text($value, string $label, bool $required=true, int $max=5000): string {
        if (!is_scalar($value) && $value !== null) throw new RuntimeException("$label tidak valid.");
        $value=trim((string)$value);
        if (($required && $value==='') || mb_strlen($value)>$max) throw new RuntimeException("$label wajib diisi dan maksimal $max karakter.");
        return $value;
    }
    public function user(int $id): array {
        $user=$this->query('SELECT user_id AS id, realname AS name FROM user WHERE user_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if (!$user) throw new RuntimeException('Petugas tidak ditemukan.');
        return $user;
    }
    public function row(string $table, int $id, bool $lock=false): array {
        if (!in_array($table,['templates','schedules','inspections','results','findings','actions','photos'],true)) throw new RuntimeException('Jenis data tidak valid.');
        $row=$this->query("SELECT * FROM inventory_watch_$table WHERE id=?".($lock?' FOR UPDATE':''),[$id])->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Data pengawasan tidak ditemukan.');
        return $row;
    }
    private function version(array $row, array $input): void {
        if ((int)($input['version']??0)!==(int)$row['version']) throw new RuntimeException('Data berubah pada sesi lain. Muat ulang sebelum melanjutkan.');
    }
    private function event(int $inspection, ?int $finding, string $event, string $notes, array $actor): void {
        $this->query('INSERT INTO inventory_watch_events (inspection_id,finding_id,event,notes,actor_id,actor_name,created_at) VALUES (?,?,?,?,?,?,NOW())',[$inspection,$finding,$event,$notes,$actor['id'],$actor['name']]);
    }
    public function mutate(string $action, array $input, array $uploads, int $uid): array {
        $actor=$this->user($uid); $this->created=[]; $this->removed=[];
        $this->db->beginTransaction();
        try {
            switch ($action) {
                case 'template': $result=['tab'=>'setup','template_id'=>$this->template($input,$actor)]; break;
                case 'schedule': $result=['tab'=>'setup','schedule_id'=>$this->schedule($input,$actor)]; break;
                case 'stop': $this->stop($input); $result=['tab'=>'setup']; break;
                case 'sync': $result=$this->sync(50); break;
                case 'incidental': $result=['tab'=>'inspection','record'=>$this->incidental($input,$actor)]; break;
                case 'inspection': $this->saveInspection($input,$actor); $result=['tab'=>'inspection','record'=>(int)$input['id']]; break;
                case 'result_photos': $this->resultPhotos($input,$uploads); $result=['tab'=>'inspection','record'=>(int)$input['inspection_id']]; break;
                case 'correction':
                    $inspection=$this->row('inspections',(int)($input['id']??0),true);
                    if ($inspection['status']!=='final') throw new RuntimeException('Catatan koreksi hanya untuk pemeriksaan final.');
                    $this->event((int)$inspection['id'],null,'correction',$this->text($input['notes']??'','Catatan koreksi'),$actor);
                    $result=['tab'=>'inspection','record'=>(int)$inspection['id']]; break;
                case 'finding': $this->finding($input,$uploads,$actor); $result=['tab'=>'finding','record'=>(int)$input['id']]; break;
                default: throw new RuntimeException('Aksi pengawasan tidak dikenal.');
            }
            if (in_array($action, ['inspection','result_photos'], true)) {
                $id=(int)($action==='inspection'?$input['id']:$input['inspection_id']);
                $saved=$this->row('inspections',$id);
                $photos=$this->query('SELECT id,result_id FROM inventory_watch_photos WHERE inspection_id=? AND result_id IS NOT NULL ORDER BY id',[$id])->fetchAll(PDO::FETCH_ASSOC);
                $result['document']=['id'=>$id,'version'=>(int)$saved['version'],'status'=>$saved['status'],'photos'=>$photos];
            }
            $this->db->commit(); $this->storage->cleanup($this->removed); $this->created=[];
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->storage->cleanup($this->created); throw $e;
        }
    }
    private function template(array $input, array $actor): int {
        $name=$this->text($input['name']??'','Nama template',true,255); $items=[];
        foreach ((array)($input['items']??[]) as $item) {
            if (!is_array($item)) throw new RuntimeException('Butir tidak valid.');
            if (trim((string)($item['object']??''))==='') continue;
            $group=$item['group']??'';
            if (!in_array($group,['Sarana','Prasarana','Lingkungan Fisik'],true)) throw new RuntimeException('Kelompok butir tidak valid.');
            $items[]=['group'=>$group,'object'=>$this->text($item['object'],'Objek',true,255),'instruction'=>$this->text($item['instruction']??'','Petunjuk',true,1000)];
        }
        if (!$items || count($items)>100) throw new RuntimeException('Template harus berisi 1–100 butir.');
        $source=(int)($input['source_id']??0); if ($source) $this->row('templates',$source);
        $this->query('INSERT INTO inventory_watch_templates (name,items,source_id,created_by,created_at) VALUES (?,?,?,?,NOW())',[$name,self::json($items),$source?:null,$actor['id']]);
        return (int)$this->db->lastInsertId();
    }
    public function scope(int $locationId, int $templateId, array $mapping): array {
        // Lock room before schedule rows, matching room deletion's lock order.
        $room=$this->query('SELECT l.*,ml.location_id AS library_code,ml.location_name AS library_name FROM inventory_locations l LEFT JOIN mst_location ml ON ml.location_id=l.slims_location_id WHERE l.id=? FOR UPDATE',[$locationId])->fetch(PDO::FETCH_ASSOC);
        if (!$room) throw new RuntimeException('Ruangan tidak ditemukan.');
        $template=$this->row('templates',$templateId); $items=self::decode($template['items']);
        foreach ($items as $position=>&$item) {
            $id=(int)($mapping[$position]??0); $item['item_id']=null; $item['item_name']=''; $item['item_code']='';
            if ($id) {
                $asset=$this->query('SELECT id,item_name,item_code FROM inventory_items WHERE id=? AND location_id=?',[$id,$locationId])->fetch(PDO::FETCH_ASSOC);
                if (!$asset) throw new RuntimeException('Barang cakupan harus berada di ruangan yang dipilih.');
                $item['item_id']=$id; $item['item_name']=$asset['item_name']; $item['item_code']=$asset['item_code'];
            }
        }
        unset($item);
        return ['room_id'=>$locationId,'room_name'=>$room['room_name'],'location_code'=>$room['location_code'],'library_code'=>$room['library_code']??'','library_name'=>$room['library_name']??'Tidak ditentukan','template_id'=>$templateId,'template_name'=>$template['name'],'items'=>$items];
    }
    private function schedule(array $input, array $actor): int {
        $scope=$this->scope((int)($input['location_id']??0),(int)($input['template_id']??0),(array)($input['mapping']??[]));
        $frequency=$input['frequency']??'';
        if (!isset(WatchRecurrence::FREQUENCIES[$frequency])) throw new RuntimeException('Frekuensi tidak valid.');
        $start=WatchRecurrence::date((string)($input['start_date']??''))->format('Y-m-d');
        $end=empty($input['end_date'])?null:WatchRecurrence::date((string)$input['end_date'])->format('Y-m-d');
        if ($end && $end<$start) throw new RuntimeException('Tanggal akhir mendahului tanggal mulai.');
        $assignee=$this->user((int)($input['assignee_id']??0));
        $scope['assignee']=$assignee;
        $replace=(int)($input['replaces_id']??0);
        if ($replace) {
            $old=$this->row('schedules',$replace,true); $this->version($old,$input);
            if (!$old['active'] || $start<date('Y-m-d') || (int)$old['location_id']!==$scope['room_id'] || $start<=$old['start_date']) throw new RuntimeException('Pengganti harus untuk ruangan yang sama, dimulai setelah awal jadwal lama dan tidak di masa lalu.');
            $last=WatchRecurrence::date($start)->modify('-1 day')->format('Y-m-d');
            if ($old['end_date'] && $old['end_date']<$last) throw new RuntimeException('Tanggal efektif melewati akhir jadwal lama.');
            if ($this->query('SELECT 1 FROM inventory_watch_inspections WHERE schedule_id=? AND due_date>=? LIMIT 1',[$replace,$start])->fetchColumn()) throw new RuntimeException('Sudah ada pemeriksaan pada tanggal pengganti. Pilih tanggal efektif berikutnya.');
            $this->query('UPDATE inventory_watch_schedules SET end_date=?,version=version+1 WHERE id=?',[$last,$replace]);
        }
        $this->query('INSERT INTO inventory_watch_schedules (location_id,template_id,snapshot,frequency,start_date,end_date,assignee_id,assignee_name,replaces_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())',[$scope['room_id'],$scope['template_id'],self::json($scope),$frequency,$start,$end,$assignee['id'],$assignee['name'],$replace?:null]);
        return (int)$this->db->lastInsertId();
    }
    private function stop(array $input): void {
        $row=$this->row('schedules',(int)($input['id']??0),true); $this->version($row,$input);
        $effective=WatchRecurrence::date((string)($input['effective']??''))->format('Y-m-d');
        if ($effective<date('Y-m-d') || $effective<$row['start_date']) throw new RuntimeException('Tanggal penghentian tidak boleh di masa lalu atau sebelum awal jadwal.');
        if ($this->query('SELECT 1 FROM inventory_watch_inspections WHERE schedule_id=? AND due_date>=? LIMIT 1',[$row['id'],$effective])->fetchColumn()) throw new RuntimeException('Pemeriksaan sudah terbentuk. Hentikan mulai hari berikutnya.');
        $last=WatchRecurrence::date($effective)->modify('-1 day')->format('Y-m-d');
        if ($row['end_date'] && $last>$row['end_date']) throw new RuntimeException('Penghentian tidak boleh memperpanjang jadwal.');
        $this->query('UPDATE inventory_watch_schedules SET end_date=?,version=version+1 WHERE id=?',[$last,$row['id']]);
    }
    public static function end(array $row, string $until): string { return $row['end_date']?min($until,$row['end_date']):$until; }
    private function sync(int $limit): array {
        $this->query('UPDATE inventory_watch_schedules SET active=0 WHERE location_id IS NULL AND active=1');
        $rows=$this->query('SELECT id,location_id FROM inventory_watch_schedules WHERE active=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC); $created=0;
        foreach ($rows as $row) {
            if (!$this->query('SELECT id FROM inventory_locations WHERE id=? FOR UPDATE',[$row['location_id']])->fetchColumn()) continue;
            $schedule=$this->row('schedules',(int)$row['id'],true);
            if (!$schedule['active']) continue;
            $until=self::end($schedule,date('Y-m-d')); $index=(int)$schedule['next_index'];
            while (($due=WatchRecurrence::at($schedule['start_date'],$schedule['frequency'],$index))<=$until) {
                if (!$this->query('SELECT id FROM inventory_watch_inspections WHERE schedule_id=? AND due_date=?',[$schedule['id'],$due])->fetchColumn()) {
                    $this->createInspection(self::decode($schedule['snapshot']),$due,'routine','',(int)$schedule['id'],null);
                }
                ++$index; ++$created;
                if ($created >= $limit) break;
            }
            $done=$schedule['end_date'] && WatchRecurrence::at($schedule['start_date'],$schedule['frequency'],$index)>$schedule['end_date'];
            $this->query('UPDATE inventory_watch_schedules SET next_index=?,active=? WHERE id=?',[$index,$done?0:1,$schedule['id']]);
            if ($created >= $limit) break;
        }
        return ['tab'=>'dashboard','generated'=>$created,'more'=>$created >= $limit];
    }
    private function createInspection(array $scope,string $due,string $kind,string $reason,?int $schedule,?int $parent): int {
        $this->query('INSERT INTO inventory_watch_inspections (schedule_id,location_id,library_code,room_key,kind,due_date,snapshot,reason,parent_id,notes,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',[$schedule,$scope['room_id'],$scope['library_code'],$scope['room_id'],$kind,$due,self::json($scope),$reason,$parent,'']);
        $id=(int)$this->db->lastInsertId();
        foreach ($scope['items'] as $position=>$item) $this->query('INSERT INTO inventory_watch_results (inspection_id,position,snapshot,notes) VALUES (?,?,?,?)',[$id,$position,self::json($item),'']);
        return $id;
    }
    private function incidental(array $input,array $actor): int {
        $scope=$this->scope((int)($input['location_id']??0),(int)($input['template_id']??0),(array)($input['mapping']??[]));
        $scope['assignee']=$actor;
        $reason=$this->text($input['reason']??'','Alasan pemeriksaan insidental');
        $parent=(int)($input['parent_id']??0);
        if ($parent) {
            $previous=$this->row('inspections',$parent);
            if ($previous['status']!=='final' || (int)$previous['room_key']!==$scope['room_id']) throw new RuntimeException('Pemeriksaan ulang harus terkait pemeriksaan final di ruangan yang sama.');
        }
        return $this->createInspection($scope,date('Y-m-d'),'incidental',$reason,null,$parent?:null);
    }
    private function saveInspection(array $input,array $actor): void {
        $inspection=$this->row('inspections',(int)($input['id']??0),true);
        // Idempotent resubmission of an already finalized document.
        if ($inspection['status']==='final' && ($input['submit_mode']??'')==='final') return;
        if ($inspection['status']==='final') throw new RuntimeException('Pemeriksaan final tidak dapat ditimpa. Tambahkan catatan koreksi.');
        $this->version($inspection,$input);
        $final=($input['submit_mode']??'')==='final';
        $performed=empty($input['performed_date'])?null:WatchRecurrence::date((string)$input['performed_date'])->format('Y-m-d');
        if (($final && !$performed) || ($performed && $performed>date('Y-m-d'))) throw new RuntimeException('Tanggal pelaksanaan wajib untuk finalisasi dan tidak boleh di masa depan.');
        $notes=$this->text($input['notes']??'','Catatan',false);
        $results=$this->query('SELECT * FROM inventory_watch_results WHERE inspection_id=? ORDER BY position',[$inspection['id']])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($results as $result) {
            $data=$input['results'][$result['id']]??[];
            $outcome=$data['outcome']??''; $note=$this->text($data['notes']??'','Catatan hasil',false);
            if (($outcome!=='' && !isset(self::OUTCOMES[$outcome])) || ($final && $outcome==='')) throw new RuntimeException('Lengkapi hasil setiap butir sebelum finalisasi.');
            if ($final && in_array($outcome,['action','unchecked','na'],true) && $note==='') throw new RuntimeException('Hasil selain Baik wajib memiliki catatan/alasan.');
            $assignee=null; $priority=null; $deadline=null;
            if ($outcome==='action') {
                if (!empty($data['assignee_id'])) $assignee=$this->user((int)$data['assignee_id']);
                $priority=($data['priority']??'')?:null;
                if ($priority && !isset(self::PRIORITIES[$priority])) throw new RuntimeException('Prioritas tidak valid.');
                if (!empty($data['deadline'])) $deadline=WatchRecurrence::date((string)$data['deadline'])->format('Y-m-d');
                if ($final && (!$assignee || !$priority || !$deadline)) throw new RuntimeException('Temuan wajib memiliki penanggung jawab, prioritas, dan tenggat.');
            }
            $this->query('UPDATE inventory_watch_results SET outcome=?,notes=?,assignee_id=?,assignee_name=?,priority=?,deadline=? WHERE id=?',[$outcome,$note,$assignee['id']??null,$assignee['name']??null,$priority,$deadline,$result['id']]);
            if ($final && $outcome==='action') $this->query('INSERT INTO inventory_watch_findings (inspection_id,result_id,assignee_id,assignee_name,priority,deadline,created_at) VALUES (?,?,?,?,?,?,NOW())',[$inspection['id'],$result['id'],$assignee['id'],$assignee['name'],$priority,$deadline]);
        }
        $this->query('UPDATE inventory_watch_inspections SET status=?,performed_date=?,examiner_id=?,examiner_name=?,notes=?,version=version+1,finalized_at=? WHERE id=?',[$final?'final':'draft',$performed,$actor['id'],$actor['name'],$notes,$final?date('Y-m-d H:i:s'):null,$inspection['id']]);
        $this->event((int)$inspection['id'],null,$final?'finalize':'save_draft',$final?'Pemeriksaan difinalisasi.':'Draf diperbarui.',$actor);
    }
    private function photos(int $inspection,?int $result,?int $action,array $photos,array $remove): void {
        $where=$result!==null?'result_id=?':'action_id=?'; $target=$result??$action;
        $rows=$this->query("SELECT id,filename FROM inventory_watch_photos WHERE inspection_id=? AND $where",[$inspection,$target])->fetchAll(PDO::FETCH_KEY_PAIR);
        $remove=array_unique(array_map('intval',$remove));
        foreach ($remove as $id) if (!isset($rows[$id])) throw new RuntimeException('Foto tidak sesuai dengan dokumen ini.');
        if (count($rows)-count($remove)+count($photos)>5) throw new RuntimeException('Maksimal lima foto per hasil/catatan tindakan.');
        foreach ($remove as $id) { $this->query('DELETE FROM inventory_watch_photos WHERE id=?',[$id]); $this->removed[]=$rows[$id]; }
        foreach ($photos as $photo) {
            $filename=$this->storage->write($photo); $this->created[]=$filename;
            $this->query('INSERT INTO inventory_watch_photos (inspection_id,result_id,action_id,filename,created_at) VALUES (?,?,?,?,NOW())',[$inspection,$result,$action,$filename]);
        }
    }
    private function resultPhotos(array $input,array $uploads): void {
        $inspection=$this->row('inspections',(int)($input['inspection_id']??0),true);
        if ($inspection['status']==='final') throw new RuntimeException('Bukti pemeriksaan final tidak dapat diubah.');
        $this->version($inspection,$input);
        $result=$this->row('results',(int)($input['result_id']??0));
        if ((int)$result['inspection_id']!==(int)$inspection['id']) throw new RuntimeException('Hasil tidak sesuai pemeriksaan.');
        $this->photos((int)$inspection['id'],(int)$result['id'],null,ItemPhotos::uploads($uploads),(array)($input['remove']??[]));
        $this->query("UPDATE inventory_watch_inspections SET version=version+1,status='draft' WHERE id=?",[$inspection['id']]);
    }
    private function finding(array $input,array $uploads,array $actor): void {
        $finding=$this->row('findings',(int)($input['id']??0),true); $this->version($finding,$input);
        $mode=$input['mode']??''; $status=$finding['status']; $id=(int)$finding['id']; $inspection=(int)$finding['inspection_id'];
        if ($mode==='start' && $status==='open') {
            $this->query("UPDATE inventory_watch_findings SET status='working',version=version+1 WHERE id=?",[$id]);
            $this->event($inspection,$id,'start','Pekerjaan dimulai.',$actor); return;
        }
        if (in_array($mode,['verify','reject'],true) && $status==='review') {
            $notes=$this->text($input['notes']??'','Catatan verifikasi');
            $this->query('UPDATE inventory_watch_findings SET status=?,closed_at=?,version=version+1 WHERE id=?',[$mode==='verify'?'closed':'working',$mode==='verify'?date('Y-m-d H:i:s'):null,$id]);
            $this->event($inspection,$id,$mode,$notes,$actor); return;
        }
        if (!in_array($mode,['draft','submit'],true) || !in_array($status,['open','working'],true)) throw new RuntimeException('Transisi status tidak tersedia. Muat ulang halaman.');
        // Opening a form is read-only. Start is recorded with the first successful save.
        if ($status==='open') $this->event($inspection,$id,'start','Pekerjaan dimulai.',$actor);
        $kind=$input['kind']??'';
        if (!in_array($kind,['repair','maintenance','none'],true)) throw new RuntimeException('Jenis tindakan tidak valid.');
        $description=$this->text($input['description']??'','Uraian pekerjaan/alasan');
        $performed=WatchRecurrence::date((string)($input['performed_date']??''))->format('Y-m-d');
        if ($performed>date('Y-m-d')) throw new RuntimeException('Tanggal pekerjaan tidak boleh di masa depan.');
        $cost=$this->text($input['cost']??'','Biaya',false,20);
        if ($cost!=='' && !preg_match('/\A[0-9]{1,16}(?:\.[0-9]{1,2})?\z/',$cost)) throw new RuntimeException('Biaya harus angka positif dengan maksimal dua desimal.');
        $draft=$this->query('SELECT * FROM inventory_watch_actions WHERE finding_id=? AND submitted_at IS NULL FOR UPDATE',[$id])->fetch(PDO::FETCH_ASSOC);
        if ($draft) {
            $action=(int)$draft['id'];
            $this->query('UPDATE inventory_watch_actions SET kind=?,description=?,performed_date=?,actor_id=?,actor_name=?,cost=? WHERE id=?',[$kind,$description,$performed,$actor['id'],$actor['name'],$cost===''?null:$cost,$action]);
        } else {
            $this->query('INSERT INTO inventory_watch_actions (finding_id,kind,description,performed_date,actor_id,actor_name,cost,created_at) VALUES (?,?,?,?,?,?,?,NOW())',[$id,$kind,$description,$performed,$actor['id'],$actor['name'],$cost===''?null:$cost]);
            $action=(int)$this->db->lastInsertId();
        }
        $this->photos($inspection,null,$action,ItemPhotos::uploads($uploads),(array)($input['remove']??[]));
        if ($mode==='submit') {
            if ($kind!=='none' && !(int)$this->query('SELECT COUNT(*) FROM inventory_watch_photos WHERE action_id=?',[$action])->fetchColumn()) throw new RuntimeException('Perbaikan/pemeliharaan wajib memiliki minimal satu foto hasil.');
            $this->query('UPDATE inventory_watch_actions SET submitted_at=NOW() WHERE id=?',[$action]);
        }
        $this->query('UPDATE inventory_watch_findings SET status=?,version=version+1 WHERE id=?',[$mode==='submit'?'review':'working',$id]);
        $this->event($inspection,$id,$mode==='submit'?'submit':'save_action',$description,$actor);
    }
    public function document(int $id): array {
        $inspection=$this->row('inspections',$id);
        return ['inspection'=>$inspection,'snapshot'=>self::decode($inspection['snapshot']),
            'results'=>$this->query('SELECT * FROM inventory_watch_results WHERE inspection_id=? ORDER BY position',[$id])->fetchAll(PDO::FETCH_ASSOC),
            'findings'=>$this->query('SELECT * FROM inventory_watch_findings WHERE inspection_id=? ORDER BY id',[$id])->fetchAll(PDO::FETCH_ASSOC),
            'actions'=>$this->query('SELECT a.* FROM inventory_watch_actions a JOIN inventory_watch_findings f ON f.id=a.finding_id WHERE f.inspection_id=? ORDER BY a.id',[$id])->fetchAll(PDO::FETCH_ASSOC),
            'events'=>$this->query('SELECT * FROM inventory_watch_events WHERE inspection_id=? ORDER BY id',[$id])->fetchAll(PDO::FETCH_ASSOC),
            'photos'=>$this->query('SELECT * FROM inventory_watch_photos WHERE inspection_id=? ORDER BY id',[$id])->fetchAll(PDO::FETCH_ASSOC)];
    }
    public function photo(int $inspection,int $photo): ?string {
        $row=$this->query('SELECT filename FROM inventory_watch_photos WHERE id=? AND inspection_id=?',[$photo,$inspection])->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Foto tidak ditemukan pada pemeriksaan ini.');
        return $this->storage->read($row['filename']);
    }
    public function filter(array $input): array {
        $from=(string)($input['from']??date('Y-m-01')); $to=(string)($input['to']??date('Y-m-t'));
        WatchRecurrence::date($from); WatchRecurrence::date($to);
        if ($to<$from) throw new RuntimeException('Periode tidak valid.');
        return ['from'=>$from,'to'=>$to,'library'=>$this->text($input['library']??'','Kode perpustakaan',false,3),'room'=>max(0,(int)($input['room']??0)), 'inspection_status'=>in_array($input['inspection_status']??'', ['pending','draft','final'],true)?$input['inspection_status']:'', 'finding_status'=>in_array($input['finding_status']??'', ['open','working','review','closed'],true)?$input['finding_status']:''];
    }
    public function where(array $filter,string $alias='i'): array {
        $where="$alias.due_date BETWEEN ? AND ?"; $args=[$filter['from'],$filter['to']];
        if ($filter['library']!=='') { $where.=" AND $alias.library_code=?"; $args[]=$filter['library']; }
        if ($filter['room']) { $where.=" AND $alias.room_key=?"; $args[]=$filter['room']; }
        return [$where,$args];
    }
    public function inspections(array $filter,int $page=1,int $limit=20): array {
        [$where,$args]=$this->where($filter);
        if (!empty($filter['inspection_status'])) { $where.=' AND i.status=?'; $args[]=$filter['inspection_status']; }
        $offset=max(0,$page-1)*$limit;
        return $this->query("SELECT i.* FROM inventory_watch_inspections i WHERE $where ORDER BY i.due_date DESC,i.id DESC LIMIT ".(int)$limit.' OFFSET '.$offset,$args)->fetchAll(PDO::FETCH_ASSOC);
    }
    public function summary(array $filter, bool $includeFindings=false): array {
        [$where,$args]=$this->where($filter);
        $today=date('Y-m-d');
        $counts=$this->query("SELECT COUNT(*) total,COALESCE(SUM(kind='routine'),0) routine,COALESCE(SUM(kind='incidental'),0) incidental,COALESCE(SUM(kind='historical'),0) historical,COALESCE(SUM(status='final'),0) finalized,COALESCE(SUM(kind='routine' AND status='final'),0) routine_final,COALESCE(SUM(status<>'final' AND due_date<?),0) late FROM inventory_watch_inspections i WHERE $where",array_merge([$today],$args))->fetch(PDO::FETCH_ASSOC);
        $findings=$this->query("SELECT COUNT(*) total,COALESCE(SUM(f.status<>'closed'),0) open,COALESCE(SUM(f.status='closed'),0) closed,COALESCE(SUM(f.status<>'closed' AND f.deadline<?),0) late FROM inventory_watch_findings f JOIN inventory_watch_inspections i ON i.id=f.inspection_id WHERE $where",array_merge([$today],$args))->fetch(PDO::FETCH_ASSOC);
        // Unfilled or unchecked results remain applicable; only explicit NA is excluded.
        $coverage=$this->query("SELECT COUNT(*) total,COALESCE(SUM(r.outcome IN ('good','action')),0) examined,COALESCE(SUM(r.outcome='na'),0) na FROM inventory_watch_results r JOIN inventory_watch_inspections i ON i.id=r.inspection_id WHERE $where AND i.kind='routine' AND i.status='final'",$args)->fetch(PDO::FETCH_ASSOC);
        $scheduleStats=[]; $rooms=[]; $planned=0; $unformed=0; $projectedItems=0; $projectedLate=0;
        $schedules=$this->query('SELECT * FROM inventory_watch_schedules ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($schedules as $schedule) {
            $snapshot=self::decode($schedule['snapshot']);
            if (($filter['library']!=='' && $snapshot['library_code']!==$filter['library']) || ($filter['room'] && $snapshot['room_id']!==$filter['room'])) continue;
            $end=self::end($schedule,$filter['to']);
            // Deleted rooms no longer produce future occurrences; formed history still counts below.
            if ($schedule['location_id']===null) {
                $formed=(int)$this->query('SELECT COUNT(*) FROM inventory_watch_inspections WHERE schedule_id=? AND due_date BETWEEN ? AND ?',[$schedule['id'],$filter['from'],$filter['to']])->fetchColumn();
                $planned+=$formed;
                if ($formed) $scheduleStats[]=['room'=>$snapshot['room_name'],'template'=>$snapshot['template_name'],'frequency'=>WatchRecurrence::FREQUENCIES[$schedule['frequency']],'planned'=>$formed,'formed'=>$formed,'final'=>(int)$this->query("SELECT COUNT(*) FROM inventory_watch_inspections WHERE schedule_id=? AND due_date BETWEEN ? AND ? AND status='final'",[$schedule['id'],$filter['from'],$filter['to']])->fetchColumn()];
                continue;
            }
            $formed=$this->query('SELECT due_date FROM inventory_watch_inspections WHERE schedule_id=? AND due_date BETWEEN ? AND ?',[$schedule['id'],$filter['from'],$end])->fetchAll(PDO::FETCH_COLUMN);
            $formedCount=count($formed); $schedulePlanned=0;
            $formed=array_fill_keys($formed,true);
            foreach (WatchRecurrence::dates($schedule['start_date'],$schedule['frequency'],$filter['from'],$end) as $date) {
                $rooms[$snapshot['room_id']]=true; ++$planned; ++$schedulePlanned;
                if ($date<=$today && !isset($formed[$date])) { ++$unformed; $projectedItems+=count($snapshot['items']); if ($date<$today) ++$projectedLate; }
            }
            if ($schedulePlanned) $scheduleStats[]=['room'=>$snapshot['room_name'],'template'=>$snapshot['template_name'],'frequency'=>WatchRecurrence::FREQUENCIES[$schedule['frequency']],'planned'=>$schedulePlanned,'formed'=>$formedCount,'final'=>(int)$this->query("SELECT COUNT(*) FROM inventory_watch_inspections WHERE schedule_id=? AND due_date BETWEEN ? AND ? AND status='final'",[$schedule['id'],$filter['from'],$filter['to']])->fetchColumn()];
        }
        $historic=$this->query("SELECT DISTINCT room_key FROM inventory_watch_inspections i WHERE $where AND kind='routine'",$args)->fetchAll(PDO::FETCH_COLUMN);
        foreach ($historic as $room) $rooms[$room]=true;
        $examinedRooms=(int)$this->query("SELECT COUNT(DISTINCT i.room_key) FROM inventory_watch_inspections i JOIN inventory_watch_results r ON r.inspection_id=i.id WHERE $where AND i.kind='routine' AND i.status='final' AND r.outcome IN ('good','action')",$args)->fetchColumn();
        $pendingItems=(int)$this->query("SELECT COUNT(*) FROM inventory_watch_results r JOIN inventory_watch_inspections i ON i.id=r.inspection_id WHERE $where AND i.kind='routine' AND i.status<>'final'",$args)->fetchColumn();
        $missing=[];
        foreach ($this->query('SELECT l.*,ml.location_name FROM inventory_locations l LEFT JOIN mst_location ml ON ml.location_id=l.slims_location_id')->fetchAll(PDO::FETCH_ASSOC) as $room) {
            if (($filter['library']!=='' && (string)$room['slims_location_id']!==$filter['library']) || ($filter['room'] && (int)$room['id']!==$filter['room'])) continue;
            if (!isset($rooms[$room['id']])) $missing[]=$room;
        }
        $findingRows=[];
        if ($includeFindings) {
            foreach ($this->query("SELECT f.*,r.snapshot AS result_snapshot,i.snapshot AS inspection_snapshot FROM inventory_watch_findings f JOIN inventory_watch_results r ON r.id=f.result_id JOIN inventory_watch_inspections i ON i.id=f.inspection_id WHERE $where ORDER BY f.deadline,f.id",$args)->fetchAll(PDO::FETCH_ASSOC) as $finding) {
                $finding['object']=self::decode($finding['result_snapshot'])['object']; $finding['room']=self::decode($finding['inspection_snapshot'])['room_name']; $findingRows[]=$finding;
            }
        }
        return ['schedules'=>$scheduleStats,'finding_rows'=>$findingRows,'counts'=>$counts,'findings'=>$findings,'planned'=>max($planned,(int)$counts['routine']),'unformed'=>$unformed,'unformed_late'=>$projectedLate,'room_total'=>count($rooms),'room_examined'=>$examinedRooms,'item_examined'=>(int)$coverage['examined'],'item_applicable'=>(int)$coverage['total']-(int)$coverage['na']+$pendingItems+$projectedItems,'item_na'=>(int)$coverage['na'],'missing_rooms'=>$missing];
    }
}
