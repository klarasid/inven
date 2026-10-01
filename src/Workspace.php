<?php
namespace SLiMS\Plugins\Inventory;

/** Read models for the React workspace. Mutations stay in the existing services. */
final class Workspace
{
    public static function endpoint(string $file, array $params=[]): string
    {
        return AWB.'plugin_container.php?'.http_build_query(array_merge(['mod'=>'stock_take','id'=>md5(realpath(dirname(__DIR__).'/'.$file))],$params));
    }
    public static function shell(string $view, bool $write): void
    {
        self::telemetryNotice();
        $config=['view'=>$view,'query'=>$_GET,'write'=>$write,'uid'=>(int)($_SESSION['uid']??0),
            'api'=>self::endpoint('inspection.php',['workspace'=>'api']),
            'watch'=>self::endpoint('inspection.php'),
            'inventory'=>self::endpoint('index.php',['workspace'=>'save']),
            'today'=>date('Y-m-d')];
        $asset=SWB.'plugins/inventaris-barang/assets/app/';
        $version=(string)(@filemtime(dirname(__DIR__).'/assets/app/inventory-app.js')?:'2');
        $config['viewer']=SWB.'plugins/inventaris-barang/assets/viewer/index.html?v='.(string)(@filemtime(dirname(__DIR__).'/assets/viewer/viewer.js')?:'1');
        // data-version ties the host to one bundle build; a newer bundle never reuses an older runtime.
        echo '<div data-inventory-app data-version="'.htmlspecialchars($version,ENT_QUOTES,'UTF-8').'" data-config="'.htmlspecialchars(json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT),ENT_QUOTES,'UTF-8').'" data-css="'.htmlspecialchars($asset.'inventory-app.css?v='.$version,ENT_QUOTES,'UTF-8').'"><p role="status">Memuat inventaris…</p></div>';
        echo '<script>(function(){var s=document.createElement("script");s.src='.json_encode($asset.'inventory-app.js?v='.$version).';document.head.appendChild(s);s.onload=function(){s.remove()};s.onerror=function(){document.querySelectorAll("[data-inventory-app]").forEach(function(e){if(!e.shadowRoot)e.textContent="Aplikasi gagal dimuat. Muat ulang halaman."})}})();</script>';
    }
    /**
     * The daily usage report: sent after this page has gone out, when one is due. Until an
     * administrator has opened Data pemakaian, those who may switch it off see a notice saying
     * what is sent and where to look.
     */
    private static function telemetryNotice(): void
    {
        require_once __DIR__.'/UpdateCheck.php';
        require_once __DIR__.'/Telemetry.php';
        try {
            $db=\SLiMS\DB::getInstance();
            Telemetry::sendLater();
            $state=Telemetry::state($db);
            $canManage=class_exists('utility') && \utility::havePrivilege('system','w');
            if(!$canManage || $state['notice_ack'] || !$state['enabled']) return;
            echo '<div class="alert alert-info" role="status" style="margin:0 0 1rem">'
                .'<strong>Klaras Inven mengirim data pemakaian.</strong> Sekali sehari, plugin ini mengirim nama perpustakaan, alamat SLiMS, versi, jumlah ruangan dan barang, pemakaian fitur, serta ringkasan galat ke Klaras untuk merawat plugin ini. Isi inventaris, nama barang, data anggota, dan data petugas tidak pernah dikirim. '
                .'<a href="'.htmlspecialchars(self::endpoint('privacy.php'),ENT_QUOTES,'UTF-8').'">Lihat data yang dikirim atau matikan</a></div>';
        } catch (\Throwable $error) {
            // The notice is never worth breaking the page for.
        }
    }
    /** LIKE patterns for a snapshot's assignee id, as a quoted string (pre-PHP 8.1 data) or a JSON integer. */
    private static function assigneeLike(int $uid): array
    {
        return ['%"assignee":{"id":"'.$uid.'",%','%"assignee":{"id":'.$uid.',%'];
    }
    private static function page(Supervision $w,string $select,string $from,array $args,int $page,string $order): array
    {
        $total=(int)$w->query('SELECT COUNT(*) '.$from,$args)->fetchColumn();
        $rows=$w->query('SELECT '.$select.' '.$from.' ORDER BY '.$order.' LIMIT 20 OFFSET '.(($page-1)*20),$args)->fetchAll(\PDO::FETCH_ASSOC);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/20))];
    }
    public static function read(Supervision $w,array $g,int $uid): array
    {
        $resource=(string)($g['resource']??'options');$page=max(1,(int)($g['page']??1));$id=(int)($g['record']??0);
        $search=mb_substr(trim((string)($g['q']??'')),0,150);$room=(int)($g['room']??0);$library=(string)($g['library']??'');
        if($resource==='options') {
            if(empty($_SESSION['inventory_csrf'])) $_SESSION['inventory_csrf']=bin2hex(random_bytes(24));
            return ['users'=>$w->query('SELECT user_id,realname FROM user ORDER BY realname')->fetchAll(\PDO::FETCH_ASSOC),
                'rooms'=>$w->query('SELECT id,room_name,slims_location_id FROM inventory_locations ORDER BY room_name')->fetchAll(\PDO::FETCH_ASSOC),
                'libraries'=>$w->query('SELECT location_id,location_name FROM mst_location ORDER BY location_name')->fetchAll(\PDO::FETCH_ASSOC),
                'templates'=>$w->query('SELECT id,name,source_id FROM inventory_watch_templates ORDER BY id DESC')->fetchAll(\PDO::FETCH_ASSOC),
                'csrf'=>$_SESSION['inventory_watch_csrf'],'inventoryCsrf'=>$_SESSION['inventory_csrf'],
                'frequencies'=>WatchRecurrence::FREQUENCIES,'outcomes'=>Supervision::OUTCOMES,'priorities'=>Supervision::PRIORITIES];
        }
        if($resource==='tasks') {
            $kind=(string)($g['kind']??'inspections');$history=($g['history']??'')==='1';
            $where=[];$args=[];
            if($library!==''){$where[]='i.library_code=?';$args[]=$library;}
            if($room){$where[]='i.room_key=?';$args[]=$room;}
            if($search!==''){$where[]='i.snapshot LIKE ?';$args[]='%"room_name":"%'.$search.'%';}
            $mine=($g['owner']??'mine')!=='all';
            if($kind==='inspections') {
                $where[]=$history?"i.status='final'":"i.status<>'final'";
                // JSON_EXTRACT is unavailable on MySQL 5.6, match the encoded assignee id in the raw snapshot instead.
                // The id is a string in snapshots written before PHP 8.1 and an integer after (PDO MySQL returns native ints).
                if($mine){$where[]='(i.snapshot LIKE ? OR i.snapshot LIKE ?)';array_push($args,...self::assigneeLike($uid));}
                $result=self::page($w,'i.*','FROM inventory_watch_inspections i WHERE '.implode(' AND ',$where),$args,$page,$history?'i.due_date DESC,i.id DESC':'i.due_date,i.id');
            } else {
                $where[]=$kind==='review'?"f.status='review'":($history?"f.status='closed'":"f.status IN ('open','working')");
                // "Mine" means assigned to me, or for the review queue, reported by me (the reporter verifies).
                if($mine&&$kind==='review'){$where[]='(i.examiner_id=? OR NOT EXISTS(SELECT 1 FROM user u WHERE u.user_id=i.examiner_id))';$args[]=$uid;}
                elseif($mine){$where[]='f.assignee_id=?';$args[]=$uid;}
                $result=self::page($w,'f.*,r.snapshot AS result_snapshot,r.notes,i.snapshot,i.kind,i.examiner_id AS reporter_id,i.examiner_name AS reporter_name','FROM inventory_watch_findings f JOIN inventory_watch_results r ON r.id=f.result_id JOIN inventory_watch_inspections i ON i.id=f.inspection_id WHERE '.implode(' AND ',$where),$args,$page,'f.deadline,f.id');
            }
            foreach($result['rows'] as &$row){$row['snapshot']=Supervision::decode($row['snapshot']);if(isset($row['result_snapshot']))$row['result_snapshot']=Supervision::decode($row['result_snapshot']);}
            return $result;
        }
        if($resource==='counts') {
            $count=fn(string $sql,array $args=[])=>(int)$w->query($sql,$args)->fetchColumn();
            return ['inspections'=>['mine'=>$count("SELECT COUNT(*) FROM inventory_watch_inspections WHERE status<>'final' AND (snapshot LIKE ? OR snapshot LIKE ?)",self::assigneeLike($uid)),'all'=>$count("SELECT COUNT(*) FROM inventory_watch_inspections WHERE status<>'final'")],
                'findings'=>['mine'=>$count("SELECT COUNT(*) FROM inventory_watch_findings WHERE status IN ('open','working') AND assignee_id=?",[$uid]),'all'=>$count("SELECT COUNT(*) FROM inventory_watch_findings WHERE status IN ('open','working')")],
                'review'=>['mine'=>$count("SELECT COUNT(*) FROM inventory_watch_findings f JOIN inventory_watch_inspections i ON i.id=f.inspection_id WHERE f.status='review' AND (i.examiner_id=? OR NOT EXISTS(SELECT 1 FROM user u WHERE u.user_id=i.examiner_id))",[$uid]),'all'=>$count("SELECT COUNT(*) FROM inventory_watch_findings WHERE status='review'")],
                'history'=>$count("SELECT COUNT(*) FROM inventory_watch_inspections WHERE status='final'"),
                'templates'=>$count('SELECT COUNT(*) FROM inventory_watch_templates'),
                'schedules'=>$count("SELECT COUNT(*) FROM inventory_watch_schedules WHERE active=1 AND location_id IS NOT NULL AND (end_date IS NULL OR end_date>=CURRENT_DATE)"),
                'rooms'=>$count('SELECT COUNT(*) FROM inventory_locations'),'items'=>$count('SELECT COUNT(*) FROM inventory_items')];
        }
        if($resource==='inspection'||$resource==='finding') {
            $finding=$resource==='finding'?$w->row('findings',$id):null;
            $d=$w->document($finding?(int)$finding['inspection_id']:$id);$d['finding']=$finding;
            foreach($d['results'] as &$r)$r['snapshot']=Supervision::decode($r['snapshot']);unset($r);
            foreach($d['photos'] as &$p){$p['url']=self::endpoint('inspection.php',['tab'=>'photo','inspection_id'=>$d['inspection']['id'],'photo_id'=>$p['id']]);unset($p['filename']);}
            return $d;
        }
        if($resource==='rooms'||$resource==='items') {
            $isItem=$resource==='items';$where=['1=1'];$args=[];
            if($isItem){$where[]='l.id=?';$args[]=$room;}
            if($library!==''){$where[]='l.slims_location_id=?';$args[]=$library;}
            if($search!==''){$where[]=$isItem?'(i.item_name LIKE ? OR i.item_code LIKE ? OR i.brand_model LIKE ?)':'(l.room_name LIKE ? OR l.location_code LIKE ?)';$args=array_merge($args,array_fill(0,$isItem?3:2,'%'.$search.'%'));}
            $condition=(string)($g['condition']??'');
            if($isItem&&in_array($condition,['B','KB','RB'],true)){$where[]='i.item_condition=?';$args[]=$condition;}
            $from=$isItem?'FROM inventory_items i JOIN inventory_locations l ON l.id=i.location_id':'FROM inventory_locations l';
            $select=$isItem?'i.*,(SELECT MIN(p.id) FROM inventory_item_photos p WHERE p.item_id=i.id AND p.filename IS NOT NULL) photo_id,(SELECT COUNT(*) FROM inventory_item_photos p WHERE p.item_id=i.id) photo_count'
                :"l.*,(SELECT COUNT(*) FROM inventory_items i WHERE i.location_id=l.id) item_count,(SELECT COUNT(*) FROM inventory_items i WHERE i.location_id=l.id AND i.item_condition<>'B') damaged_count,(SELECT location_name FROM mst_location WHERE location_id=l.slims_location_id) library_name";
            $result=self::page($w,$select,$from.' WHERE '.implode(' AND ',$where),$args,$page,$isItem?'i.item_name,i.id':'l.room_name,l.id');
            if($isItem){
                foreach($result['rows'] as &$row)$row['photo_url']=$row['photo_id']?self::endpoint('index.php',['action'=>'item_photo','photo_id'=>$row['photo_id']]):null;
                unset($row);
                $result['room']=$w->query('SELECT l.*,(SELECT location_name FROM mst_location WHERE location_id=l.slims_location_id) library_name FROM inventory_locations l WHERE l.id=?',[$room])->fetch(\PDO::FETCH_ASSOC)?:null;
                $result['conditions']=$w->query('SELECT item_condition,COUNT(*) FROM inventory_items WHERE location_id=? GROUP BY item_condition',[$room])->fetchAll(\PDO::FETCH_KEY_PAIR);
            }
            return $result;
        }
        if($resource==='item'||$resource==='room') {
            $row=$w->query('SELECT * FROM '.($resource==='item'?'inventory_items':'inventory_locations').' WHERE id=?',[$id])->fetch(\PDO::FETCH_ASSOC);
            if(!$row)throw new \RuntimeException('Data tidak ditemukan.');
            $photos=[];
            if($resource==='item')foreach($w->query('SELECT id,filename FROM inventory_item_photos WHERE item_id=? ORDER BY id',[$id])->fetchAll(\PDO::FETCH_ASSOC) as $p)$photos[]=['id'=>$p['id'],'url'=>$p['filename']===null?null:self::endpoint('index.php',['action'=>'item_photo','photo_id'=>$p['id']])];
            return ['record'=>$row,'photos'=>$photos];
        }
        if($resource==='templates'||$resource==='schedules') {
            $table=$resource==='templates'?'templates':'schedules';$where=['1=1'];$args=[];
            if($search!==''){$where[]=$table==='templates'?'name LIKE ?':'snapshot LIKE ?';$args[]=$table==='templates'?'%'.$search.'%':'%"room_name":"%'.$search.'%';}
            if($table==='schedules'){
                if($room){$where[]='location_id=?';$args[]=$room;}
                if($library!==''){$where[]='snapshot LIKE ?';$args[]='%"library_code":"'.$library.'",%';}
                if(($g['history']??'')!=='1')$where[]="active=1 AND location_id IS NOT NULL AND (end_date IS NULL OR end_date>=CURRENT_DATE)";
            }
            $result=self::page($w,'*','FROM inventory_watch_'.$table.' WHERE '.implode(' AND ',$where),$args,$page,'id DESC');
            foreach($result['rows'] as &$row){$key=$table==='templates'?'items':'snapshot';$row[$key]=Supervision::decode($row[$key]);}
            return $result;
        }
        if($resource==='template'||$resource==='schedule'){
            $row=$w->row($resource==='template'?'templates':'schedules',$id);$key=$resource==='template'?'items':'snapshot';$row[$key]=Supervision::decode($row[$key]);return $row;
        }
        if($resource==='pdf_documents') {
            require_once __DIR__.'/PdfDocuments.php';
            return ['settings'=>PdfDocuments::load($w->pdo()),'types'=>PdfDocuments::TYPES,'placeholders'=>PdfDocuments::PLACEHOLDERS];
        }
        if($resource==='update') {
            require_once __DIR__.'/UpdateCheck.php';
            return UpdateCheck::status($w->pdo(),($g['refresh']??'')==='1');
        }
        if($resource==='letterheads') {
            require_once __DIR__.'/Letterheads.php';
            $list=array_values(Letterheads::all($w->pdo()));
            usort($list,fn($a,$b)=>strcasecmp($a['name'],$b['name']));
            return ['templates'=>$list,'bodies'=>Letterheads::BODIES,'maxBytes'=>Letterheads::MAX_BYTES,'fonts'=>PdfFonts::catalog()];
        }
        if($resource==='assets') {
            return $w->query('SELECT id,item_name,item_code,item_condition FROM inventory_items WHERE location_id=? ORDER BY item_name,id',[$room])->fetchAll(\PDO::FETCH_ASSOC);
        }
        if($resource==='scope') {
            $t=$w->row('templates',(int)($g['template_id']??0));return ['items'=>Supervision::decode($t['items']),'assets'=>$w->query('SELECT id,item_name,item_code FROM inventory_items WHERE location_id=? ORDER BY item_name',[$room])->fetchAll(\PDO::FETCH_ASSOC)];
        }
        if($resource==='preview'){
            // Next occurrences as sync() will form them: moved off SLiMS holidays, skipped for daily schedules.
            $start=(string)($g['start_date']??date('Y-m-d'));$end=(string)($g['end_date']??'');$frequency=(string)($g['frequency']??'monthly');
            $dates=[];$moved=[];$holidays=$w->holidays();
            for($n=0;count($dates)<5&&$n<40;$n++){
                $raw=WatchRecurrence::at($start,$frequency,$n);if($end!==''&&$raw>$end)break;
                $date=$holidays->shift($raw,$frequency,$end);if($date===null||in_array($date,$dates,true))continue;
                $dates[]=$date;if($date!==$raw)$moved[$date]=['from'=>$raw,'reason'=>$holidays->reason($raw)];
            }
            return ['dates'=>$dates,'moved'=>(object)$moved];
        }
        if($resource==='reports'){
            $filter=$w->filter($g);$filter['inspection_status']='';$filter['finding_status']='';
            [$where,$args]=$w->where($filter);$result=self::page($w,'i.*','FROM inventory_watch_inspections i WHERE '.$where,$args,$page,'i.due_date DESC,i.id DESC');
            foreach($result['rows'] as &$r)$r['snapshot']=Supervision::decode($r['snapshot']);
            return $result+['summary'=>$w->summary($filter),'filter'=>$filter];
        }
        throw new \RuntimeException('Halaman tidak ditemukan.');
    }
}
