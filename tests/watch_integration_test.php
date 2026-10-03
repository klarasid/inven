<?php
declare(strict_types=1);
namespace SLiMS { class DB {public static \PDO $connection;public static function getInstance(): \PDO{return self::$connection;}} }
namespace {
if (!defined('AWB')) define('AWB','/');
if (!defined('SWB')) define('SWB','/');
use SLiMS\Plugins\Inventory\Supervision as W;
use SLiMS\Plugins\Inventory\PhotoStorage;
require __DIR__.'/../src/WatchRecurrence.php';require __DIR__.'/../src/Supervision.php';require __DIR__.'/../src/ItemPhotos.php';require __DIR__.'/../src/PhotoStorage.php';require __DIR__.'/../src/WatchPdf.php';require __DIR__.'/../src/WatchView.php';
require dirname(__DIR__,3).'/lib/Migration/Migration.php';require __DIR__.'/../migration/7_CreateInventorySupervision.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "ok   $label\n";}
if(!getenv('INVENTORY_TEST_DSN')){fwrite(STDERR,"Set INVENTORY_TEST_DSN, INVENTORY_TEST_USER and INVENTORY_TEST_PASSWORD.\n");exit(1);}
$prefix=getenv('INVENTORY_WATCH_TEST_PREFIX')?:'iw_test_'.bin2hex(random_bytes(6)).'_';
if(!preg_match('/\Aiw_test_[a-f0-9]{12}_\z/',$prefix))throw new RuntimeException('Unsafe prefix');
$names=['inventory_watch_photos','inventory_watch_events','inventory_watch_actions','inventory_watch_findings','inventory_watch_results','inventory_watch_inspections','inventory_watch_schedules','inventory_watch_templates','inventory_items','inventory_locations','mst_location','user'];
class WatchTestConnection extends PDO {
 public array $names;public string $prefix;
 private function sql(string $sql):string{foreach($this->names as$name)$sql=preg_replace('/\b'.$name.'\b/',$this->prefix.$name,$sql);return $sql;}
 public function exec(string $statement):int|false{return parent::exec($this->sql($statement));}
 public function prepare(string $query,array $options=[]):\PDOStatement|false{return parent::prepare($this->sql($query),$options);}
 public function query(string $query,?int $fetchMode=null,mixed ...$args):\PDOStatement|false{return $fetchMode===null?parent::query($this->sql($query)):parent::query($this->sql($query),$fetchMode,...$args);}
}
$db=new WatchTestConnection(getenv('INVENTORY_TEST_DSN'),getenv('INVENTORY_TEST_USER'),getenv('INVENTORY_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->names=$names;$db->prefix=$prefix;\SLiMS\DB::$connection=$db;
$directory=sys_get_temp_dir().'/'.$prefix.'photos';$storage=new PhotoStorage($directory);$watch=new W($db,$storage);
$mutate=fn($action,$data=[])=>$watch->mutate($action,$data,[],1);
$reject=function(callable $call,string $label):void{try{$call();}catch(RuntimeException $e){check(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);};
if(in_array('--worker',$argv??[],true)){file_put_contents(sys_get_temp_dir().'/'.$prefix.'ready','1');$mutate('sync');exit;}
// HTTP fixture exercises real is_uploaded_file(), shared by all photo mutations.
if(PHP_SAPI==='cli-server' && isset($_GET['controller'])) {
 define('INDEX_AUTH',true); define('SB',sys_get_temp_dir().'/'.$prefix.'bootstrap/'); define('LIB',SB.'lib/'); define('FLS','files'); define('DS',DIRECTORY_SEPARATOR);
 class utility {public static function havePrivilege($module,$mode='r'){return ($_GET['access']??'write')!=='none' && ($mode==='r'||($_GET['access']??'write')==='write');}}
 function do_checkIP($scope){} function writeLog(...$args){}
 require __DIR__.'/../supervision.php'; return;
}
if(PHP_SAPI==='cli-server'){
 header('Content-Type: application/json');
 try{echo json_encode(['ok'=>true,'result'=>$watch->mutate((string)$_POST['watch_action'],$_POST,$_FILES['photos']??[],1)]);}catch(Throwable $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}return;
}
$server=null;$worker=null;
$bootstrap=sys_get_temp_dir().'/'.$prefix.'bootstrap';
try{
 $db->exec('CREATE TABLE user (user_id INT PRIMARY KEY,realname VARCHAR(255)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE mst_location (location_id VARCHAR(3) PRIMARY KEY,location_name VARCHAR(255)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE inventory_locations (id INT UNSIGNED PRIMARY KEY,room_name VARCHAR(255),location_code VARCHAR(100),slims_location_id VARCHAR(3)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE inventory_items (id INT UNSIGNED PRIMARY KEY,location_id INT UNSIGNED,item_name VARCHAR(255),item_code VARCHAR(150),FOREIGN KEY(location_id) REFERENCES inventory_locations(id) ON DELETE CASCADE) ENGINE=InnoDB');
 $db->exec("INSERT INTO user VALUES(1,'Petugas Asli'),(2,'Petugas Kedua')");$db->exec("INSERT INTO mst_location VALUES('P01','Perpustakaan Asli'),('P02','Cabang')");
 $db->exec("INSERT INTO inventory_locations VALUES(1,'Ruang Asli','CARD','P01'),(2,'Ruang Dua','CARD','P01'),(3,'Tanpa Jadwal',NULL,'P02')");
 $db->exec("INSERT INTO inventory_items VALUES(1,1,'Kursi Asli','P01-INV-000001'),(2,2,'Meja Lain','P01-INV-000002')");
 (new CreateInventorySupervision())->up();(new CreateInventorySupervision())->up();
 check((int)$db->query('SELECT COUNT(*) FROM inventory_items')->fetchColumn()===2,'migration idempotent; inventory preserved');
 $items=[];foreach(['Kursi <Asli>','Atap','Kebersihan','Akses']as$object)$items[]=['group'=>'Sarana','object'=>$object,'instruction'=>'Periksa dengan teliti'];
 $template=$mutate('template',['name'=>'Checklist <Asli>','items'=>$items])['template_id'];
 $today=date('Y-m-d');$yesterday=date('Y-m-d',strtotime('-1 day'));$start=date('Y-m-d',strtotime('-2 days'));
 $scheduleInput=['location_id'=>1,'template_id'=>$template,'mapping'=>[0=>1],'frequency'=>'daily','start_date'=>$start,'end_date'=>$today,'assignee_id'=>1];
 $schedule=$mutate('schedule',$scheduleInput)['schedule_id'];
 $reject(fn()=>$mutate('schedule',array_replace($scheduleInput,['mapping'=>[0=>2]])),'cross-room inventory mapping rejected');
 $filter=$watch->filter(['from'=>$start,'to'=>$today]);$before=$watch->summary($filter);
 check($before['unformed']===3&&$before['planned']===3&&$before['item_applicable']===12,'read-only dashboard shows unformed due dates and coverage');
 check((int)$db->query('SELECT COUNT(*) FROM inventory_watch_inspections')->fetchColumn()===0,'read-only summary does not generate inspections');
 putenv('INVENTORY_WATCH_TEST_PREFIX='.$prefix);
 $db->beginTransaction();$db->query('SELECT id FROM inventory_locations WHERE id=1 FOR UPDATE')->fetchColumn();
 $worker=proc_open([PHP_BINARY,__FILE__,'--worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);
 $ready=sys_get_temp_dir().'/'.$prefix.'ready';$deadline=microtime(true)+10;while(!is_file($ready)&&microtime(true)<$deadline)usleep(10000);
 check(is_file($ready)&&proc_get_status($worker)['running'],'second schedule generator waits on room lock');$db->commit();
 $mutate('sync');$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($worker);$worker=null;unlink($ready);
 check($exit===0&&$error===''&&(int)$db->query('SELECT COUNT(*) FROM inventory_watch_inspections')->fetchColumn()===3,'concurrent catch-up produces one inspection per date');
 $mutate('sync');check((int)$db->query('SELECT COUNT(*) FROM inventory_watch_inspections')->fetchColumn()===3,'sync repeat is idempotent');
 $id=(int)$db->query('SELECT MIN(id) FROM inventory_watch_inspections')->fetchColumn();$doc=$watch->document($id);
 $resultIds=array_column($doc['results'],'id');$answers=[];foreach(['good','action','unchecked','na']as$n=>$outcome)$answers[$resultIds[$n]]=['outcome'=>$outcome,'notes'=>$outcome==='good'?'':'Catatan wajib','assignee_id'=>1,'priority'=>'high','deadline'=>$yesterday];
 $save=['id'=>$id,'version'=>1,'performed_date'=>$today,'results'=>$answers,'notes'=>'Catatan pemeriksaan','submit_mode'=>'final'];
 $bad=$save;unset($bad['results'][$resultIds[3]]);$reject(fn()=>$mutate('inspection',$bad),'incomplete finalization rejected');
 $bad=$save;$bad['results'][$resultIds[1]]['notes']='';$reject(fn()=>$mutate('inspection',$bad),'action note required');
 $bad=$save;$bad['results'][$resultIds[1]]['assignee_id']='';$reject(fn()=>$mutate('inspection',$bad),'finding assignment required');
 $saved=$mutate('inspection',$save);check($saved['document']['version']===2 && $saved['document']['status']==='final','mutation returns committed document version and status');$mutate('inspection',$save);
 $finalRows=$watch->inspections($watch->filter(['from'=>$start,'to'=>$today,'inspection_status'=>'final']));check(count($finalRows)===1&&$finalRows[0]['status']==='final','inspection status filter applies before pagination');
 check((int)$db->query('SELECT COUNT(*) FROM inventory_watch_findings')->fetchColumn()===1,'finalization atomically creates findings exactly once');
 $reject(fn()=>$mutate('inspection',array_replace($save,['submit_mode'=>'draft','version'=>2])),'final inspection cannot be overwritten');
 $reject(fn()=>$mutate('result_photos',['inspection_id'=>$id,'result_id'=>$resultIds[0],'version'=>2]),'final evidence immutable');
 $mutate('correction',['id'=>$id,'notes'=>'Tambahan koreksi']);
 $finding=(int)$db->query('SELECT id FROM inventory_watch_findings')->fetchColumn();
 $mutate('finding',['id'=>$finding,'version'=>1,'mode'=>'start']);
 $reject(fn()=>$mutate('finding',['id'=>$finding,'version'=>1,'mode'=>'start']),'stale transition rejected');
 $action=['id'=>$finding,'version'=>2,'mode'=>'submit','kind'=>'repair','description'=>'Diperbaiki','performed_date'=>$today,'cost'=>'1000.50'];
 $reject(fn()=>$mutate('finding',$action),'repair requires result photo');
 check((int)$db->query('SELECT COUNT(*) FROM inventory_watch_actions')->fetchColumn()===0,'failed submission rolls action creation back');
 // Real multipart request to test normalized storage and transaction cleanup.
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($socket,false);fclose($socket);
 $server=proc_open([PHP_BINARY,'-S',$address,__FILE__],[0=>['pipe','r'],1=>['file',sys_get_temp_dir().'/'.$prefix.'http.log','a'],2=>['file',sys_get_temp_dir().'/'.$prefix.'http.log','a']],$serverPipes);fclose($serverPipes[0]);
 mkdir($bootstrap.'/lib',0700,true);mkdir($bootstrap.'/admin/default',0700,true);
 file_put_contents($bootstrap.'/lib/ip_based_access.inc.php','<?php');
 file_put_contents($bootstrap.'/admin/default/session.inc.php', '<?php session_start(); $_SESSION["uid"]=1; $_SESSION["inventory_watch_csrf"]="test-csrf";');
 file_put_contents($bootstrap.'/admin/default/session_check.inc.php','<?php');
 $deadline=microtime(true)+5;do{$conn=@stream_socket_client('tcp://'.$address,$errno,$errstr,.2);if($conn){fclose($conn);break;}usleep(10000);}while(microtime(true)<$deadline);
 $endpoint=function(array $query,array $post=[])use($address):array{$curl=curl_init('http://'.$address.'/?controller=1&'.http_build_query($query));curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);if($post)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);$body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);return [$status,$body];};
 [$status,$body]=$endpoint(['access'=>'none']);check($status===403,'controller rejects unauthenticated/unprivileged read');
 [$status,$body]=$endpoint(['access'=>'read'],['watch_action'=>'sync','csrf_token'=>'test-csrf']);check($status===403&&!json_decode($body,true)['ok'],'controller rejects write by read-only user');
 [$status,$body]=$endpoint([],['watch_action'=>'sync','csrf_token'=>'wrong']);check($status===403&&!json_decode($body,true)['ok'],'controller rejects invalid CSRF');
 $beforeCount=(int)$db->query('SELECT COUNT(*) FROM inventory_watch_inspections')->fetchColumn();
 [$status,$body]=$endpoint(['access'=>'read']);check($status===200&&str_contains($body,'&quot;write&quot;:false')&&(int)$db->query('SELECT COUNT(*) FROM inventory_watch_inspections')->fetchColumn()===$beforeCount,'controller GET renders without database mutation');
 [$status,$body]=$endpoint(['tab'=>'scope','access'=>'read','location_id'=>1,'template_id'=>$template]);$scope=json_decode($body,true);check($status===200&&count($scope['items'])===4&&count($scope['assets'])===1,'read-only wizard scope endpoint returns room assets and checklist');
 [$status,$body]=$endpoint([],['watch_action'=>'sync','csrf_token'=>'test-csrf']);check($status===200&&json_decode($body,true)['ok'],'authorized controller POST uses AJAX response');
 $im=imagecreatetruecolor(20,20);$upload=sys_get_temp_dir().'/'.$prefix.'upload.png';imagepng($im,$upload);imagedestroy($im);
 $post=function(array $data,string $file)use($address):array{$curl=curl_init('http://'.$address);$data['photos[0]']=new CURLFile($file,'image/png','proof.png');curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$data,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);$response=curl_exec($curl);if($response===false)throw new RuntimeException(curl_error($curl));curl_close($curl);return json_decode($response,true,512,JSON_THROW_ON_ERROR);};
 $response=$post(['watch_action'=>'finding']+$action,$upload);check($response['ok']===true,'multipart repair photo normalized and submitted');
 $draftPhoto=$db->query('SELECT * FROM inventory_watch_photos')->fetch(PDO::FETCH_ASSOC);check($watch->photo($id,(int)$draftPhoto['id'])!==null,'authorized document photo can be read');
 $reject(fn()=>$watch->photo($id+1,(int)$draftPhoto['id']),'photo scoped to inspection');
 $mutate('finding',['id'=>$finding,'version'=>3,'mode'=>'reject','notes'=>'Perlu penguatan']);
 $submittedBefore=$db->query('SELECT filename FROM inventory_watch_photos')->fetchColumn();
 $badAction=['watch_action'=>'finding','id'=>$finding,'version'=>4,'mode'=>'submit','kind'=>'repair','description'=>'Percobaan','performed_date'=>$today,'remove[0]'=>$draftPhoto['id']];
 $response=$post($badAction,$upload);check(!$response['ok'],'cannot remove evidence from previously submitted action');
 check($db->query('SELECT filename FROM inventory_watch_photos')->fetchColumn()===$submittedBefore,'rejected edit preserves submitted evidence');
 $mutate('finding',['id'=>$finding,'version'=>4,'mode'=>'submit','kind'=>'none','description'=>'Hasil dinilai cukup; tidak perlu pekerjaan tambahan','performed_date'=>$today]);
 $mutate('finding',['id'=>$finding,'version'=>5,'mode'=>'verify','notes'=>'Diperiksa kembali dan sesuai']);
 check($watch->row('findings',$finding)['status']==='closed','same actor may verify; no-work closure requires review');
 $summary=$watch->summary($filter,true);
 check($summary['item_examined']===2&&$summary['item_applicable']===11&&$summary['item_na']===1&&$summary['room_examined']===1,'coverage retains unchecked and pending; excludes NA');
 check((int)$summary['findings']['closed']===1&&(int)$summary['findings']['open']===0,'verified status reflected in dashboard');
 $incidental=$mutate('incidental',['location_id'=>1,'template_id'=>$template,'reason'=>'Pemeriksaan ulang','parent_id'=>$id])['record'];
 check($watch->row('inspections',$incidental)['kind']==='incidental','reinspection linked and separate from routine');
 $reject(fn()=>$mutate('incidental',['location_id'=>2,'template_id'=>$template,'reason'=>'Salah ruang','parent_id'=>$id]),'reinspection must match original room');
 $incidentalDoc=$watch->document($incidental);$photoInput=['watch_action'=>'result_photos','inspection_id'=>$incidental,'result_id'=>$incidentalDoc['results'][0]['id'],'version'=>1];
 $response=$post($photoInput,$upload);check($response['ok'],'draft result accepts real multipart proof');
 check($response['result']['document']['version']===2 && count($response['result']['document']['photos'])===1 && !isset($response['result']['document']['photos'][0]['filename']),'photo response returns new version and public metadata');
 $ownPhoto=$db->query('SELECT id FROM inventory_watch_photos WHERE result_id='.(int)$incidentalDoc['results'][0]['id'])->fetchColumn();
 $reject(fn()=>$mutate('result_photos',['inspection_id'=>$incidental,'result_id'=>$resultIds[0],'version'=>2]),'result upload target must belong to inspection');
 $reject(fn()=>$mutate('result_photos',['inspection_id'=>$incidental,'result_id'=>$incidentalDoc['results'][0]['id'],'version'=>2,'remove'=>[$draftPhoto['id']]]),'draft photo deletion cannot target another document');
 $mutate('result_photos',['inspection_id'=>$incidental,'result_id'=>$incidentalDoc['results'][0]['id'],'version'=>2,'remove'=>[$ownPhoto]]);
 check(!(int)$db->query('SELECT COUNT(*) FROM inventory_watch_photos WHERE result_id='.(int)$incidentalDoc['results'][0]['id'])->fetchColumn(),'draft evidence removable after commit');
 $beforeFiles=count(glob($directory.'/*.jpg')?:[]);
 $db->exec("CREATE TRIGGER ".$prefix."photo_failure BEFORE INSERT ON inventory_watch_photos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='simulated metadata failure'");
 try {$response=$post(array_replace($photoInput,['version'=>3]),$upload);check(!$response['ok']&&count(glob($directory.'/*.jpg')?:[])===$beforeFiles,'metadata failure rolls back database and cleans new physical photo');}
 finally {$db->exec('DROP TRIGGER IF EXISTS '.$prefix.'photo_failure');}
 $invalid=sys_get_temp_dir().'/'.$prefix.'bad.png';file_put_contents($invalid,'<?php payload');
 $response=$post(array_replace($photoInput,['version'=>3]),$invalid);unlink($invalid);check(!$response['ok'],'forged image rejected through HTTP');
 $roomTwoSchedule=$mutate('schedule',['location_id'=>2,'template_id'=>$template,'frequency'=>'daily','start_date'=>$today,'assignee_id'=>2])['schedule_id'];
 $mutate('stop',['id'=>$roomTwoSchedule,'version'=>1,'effective'=>$today]);$mutate('sync');
 check(!(int)$db->query('SELECT COUNT(*) FROM inventory_watch_inspections WHERE schedule_id='.$roomTwoSchedule)->fetchColumn(),'effective stop prevents future formation');
 $originalVersion=$mutate('schedule',['location_id'=>2,'template_id'=>$template,'frequency'=>'daily','start_date'=>$today,'assignee_id'=>2])['schedule_id'];
 $replacement=$mutate('schedule',['location_id'=>2,'template_id'=>$template,'frequency'=>'weekly','start_date'=>date('Y-m-d',strtotime('+1 day')),'assignee_id'=>2,'replaces_id'=>$originalVersion,'version'=>1])['schedule_id'];
 check($watch->row('schedules',$originalVersion)['end_date']===$today&&$watch->row('schedules',$replacement)['replaces_id']==$originalVersion,'schedule replacement preserves old version and effective boundary');
 $reject(fn()=>$mutate('stop',['id'=>$originalVersion,'version'=>1,'effective'=>date('Y-m-d',strtotime('+1 day'))]),'stale schedule revision rejected');
 $mutate('sync');
 $copy=$mutate('template',['name'=>'Revised','source_id'=>$template,'items'=>[['group'=>'Prasarana','object'=>'Baru','instruction'=>'Revisi']]])['template_id'];
 $db->exec("UPDATE inventory_items SET location_id=2,item_name='Dipindahkan' WHERE id=1");$db->exec("UPDATE user SET realname='Nama Baru' WHERE user_id=1");$db->exec("UPDATE inventory_locations SET room_name='Nama Baru' WHERE id=1");
 $historic=$watch->document($id);check($historic['snapshot']['room_name']==='Ruang Asli'&&$historic['inspection']['examiner_name']==='Petugas Asli'&&W::decode($historic['results'][0]['snapshot'])['item_name']==='Kursi Asli','identity snapshots survive edits, moves and template revisions');
 $db->exec('DELETE FROM inventory_locations WHERE id=1');$mutate('sync');
 check($watch->row('schedules',$schedule)['active']==0&&$watch->row('inspections',$id)['location_id']===null,'room deletion disables schedule without deleting history');
 $detail=\SLiMS\Plugins\Inventory\WatchPdf::detail($watch->document($id),fn($p)=>$watch->photo($id,(int)$p['id']));
 check(str_contains($detail,'Kursi &lt;Asli&gt;')&&str_contains($detail,'Verifikasi diterima')&&str_contains($detail,'data:image/jpeg;base64,'),'PDF detail includes escaped checklist, photos and verification');
 $summary=$watch->summary($filter,true);$html=\SLiMS\Plugins\Inventory\WatchPdf::summary($filter,$summary,$watch->inspections($filter));
 check(str_contains($html,'Jadwal versus Realisasi')&&str_contains($html,'Selesai'),'period PDF includes realization and findings');
 $reject(fn()=>\SLiMS\Plugins\Inventory\WatchPdf::summary($filter,$summary,array_fill(0,501,[])),'summary export refuses truncation above 500');
 ob_start();\SLiMS\Plugins\Inventory\WatchView::render($watch,'/plugin','inspection',$filter,false,'csrf',['record'=>$id]);$view=ob_get_clean();check(!str_contains($view,'name="watch_action"')&&str_contains($view,'data-write="0"'),'read-only detail has no mutation forms');
 ob_start();\SLiMS\Plugins\Inventory\WatchView::render($watch,'/plugin','finding',$filter,true,'csrf',['record'=>$finding]);$view=ob_get_clean();check(str_contains($view,'Verifikasi diterima'),'closed finding renders verification history');
 $longSchedule=$mutate('schedule',['location_id'=>3,'template_id'=>$template,'frequency'=>'daily','start_date'=>date('Y-m-d',strtotime('-60 days')),'end_date'=>$today,'assignee_id'=>2])['schedule_id'];
 $batch=$mutate('sync');check($batch['generated']===50&&$batch['more'],'catch-up bounded to fifty occurrences');
 $batch=$mutate('sync');check($batch['generated']===11&&!$batch['more']&&(int)$db->query('SELECT COUNT(*) FROM inventory_watch_inspections WHERE schedule_id='.$longSchedule)->fetchColumn()===61,'next batch resumes without skips');
 foreach (['setup','template','schedule','inspections','findings','reports','new'] as $tab) {
     ob_start();\SLiMS\Plugins\Inventory\WatchView::render($watch,'/plugin',$tab,$filter,true,'csrf',[]);$page=ob_get_clean();
     if ($out=getenv('INVENTORY_VIEW_OUTPUT')) file_put_contents($out.'/'.$tab.'.html',$page);
     check(str_contains($page,'id="inventory-watch"')&&!str_contains($page,'Warning:'),'writer page renders: '.$tab);
 }
 if ($out=getenv('INVENTORY_VIEW_OUTPUT')) {
     ob_start();\SLiMS\Plugins\Inventory\WatchView::render($watch,'/plugin','inspection',$filter,true,'csrf',['record'=>$incidental]);$page=ob_get_clean();file_put_contents($out.'/inspection.html',$page);
 }
 // Emit PDF through installed runtime if available, without touching application paths.
 $autoload=getenv('INVENTORY_TEST_AUTOLOAD')?:__DIR__.'/../vendor/autoload.php';if(is_file($autoload)){require_once $autoload;$pdf=new \Mpdf\Mpdf(['tempDir'=>sys_get_temp_dir(),'exposeVersion'=>false]);$pdf->WriteHTML($detail);check(str_starts_with($pdf->Output('','S'),'%PDF-'),'mPDF renders document with evidence');$periodPdf=new \Mpdf\Mpdf(['tempDir'=>sys_get_temp_dir(),'exposeVersion'=>false]);$periodPdf->WriteHTML($html);check(str_starts_with($periodPdf->Output('','S'),'%PDF-'),'mPDF renders period report');}
}finally{
 if(is_resource($server)){proc_terminate($server);proc_close($server);}if(is_resource($worker)){proc_terminate($worker);proc_close($worker);}
 if($db->inTransaction())$db->rollBack();foreach($names as$name)$db->exec('DROP TABLE IF EXISTS '.$name);
 foreach(glob($directory.'/*')?:[]as$file)unlink($file);if(is_file($directory.'/.htaccess'))unlink($directory.'/.htaccess');if(is_dir($directory))rmdir($directory);
 foreach (['lib/ip_based_access.inc.php','admin/default/session.inc.php','admin/default/session_check.inc.php'] as $file) if(is_file($bootstrap.'/'.$file))unlink($bootstrap.'/'.$file);
 foreach (['admin/default','admin','lib',''] as $folder) if(is_dir($bootstrap.'/'.$folder))rmdir($bootstrap.'/'.$folder);
 foreach(['upload.png','http.log','ready']as$suffix){$file=sys_get_temp_dir().'/'.$prefix.$suffix;if(is_file($file))unlink($file);}
}
}
