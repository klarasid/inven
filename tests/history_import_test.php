<?php
declare(strict_types=1);
namespace SLiMS {class DB {public static \PDO $connection;public static function getInstance(): \PDO{return self::$connection;}}}
namespace {
require __DIR__.'/../src/WatchRecurrence.php';require __DIR__.'/../src/Supervision.php';require __DIR__.'/../src/PhotoStorage.php';require __DIR__.'/../src/HistoryImport.php';require __DIR__.'/../src/HistoryWorkbook.php';
require dirname(__DIR__,3).'/lib/Migration/Migration.php';require __DIR__.'/../migration/7_CreateInventorySupervision.php';
use SLiMS\Plugins\Inventory\HistoryImport as H;
use SLiMS\Plugins\Inventory\HistoryWorkbook as X;
if(!getenv('INVENTORY_TEST_DSN')){fwrite(STDERR,"Set INVENTORY_TEST_DSN, INVENTORY_TEST_USER, INVENTORY_TEST_PASSWORD.\n");exit(1);}
class HistoryTestConnection extends \PDO {
 public string $prefix;public array $names;
 private function sql(string $sql):string{foreach($this->names as $name)$sql=preg_replace('/\b'.$name.'\b/',$this->prefix.$name,$sql);return $sql;}
 public function exec(string $s):int|false{return parent::exec($this->sql($s));}
 public function prepare(string $s,array $o=[]):\PDOStatement|false{return parent::prepare($this->sql($s),$o);}
 public function query(string $s,?int $m=null,mixed ...$a):\PDOStatement|false{return $m===null?parent::query($this->sql($s)):parent::query($this->sql($s),$m,...$a);}
}
function check($ok,$label){if(!$ok)throw new \RuntimeException($label);echo "ok $label\n";}
function reject($fn,$label){try{$fn();}catch(\RuntimeException $e){check(true,$label);return;}throw new \RuntimeException('Expected rejection: '.$label);}
$prefix=getenv('INVENTORY_HISTORY_TEST_PREFIX')?:'ih_test_'.bin2hex(random_bytes(6)).'_';
if(!preg_match('/\Aih_test_[a-f0-9]{12}_\z/',$prefix))throw new \RuntimeException('Unsafe prefix');
$db=new HistoryTestConnection(getenv('INVENTORY_TEST_DSN'),getenv('INVENTORY_TEST_USER'),getenv('INVENTORY_TEST_PASSWORD'),[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
$db->prefix=$prefix;$db->names=['inventory_watch_photos','inventory_watch_events','inventory_watch_actions','inventory_watch_findings','inventory_watch_results','inventory_watch_inspections','inventory_watch_schedules','inventory_watch_templates','inventory_items','inventory_locations','mst_location','user'];
\SLiMS\DB::$connection=$db;$directory=sys_get_temp_dir().'/'.$prefix.'photos';
$watch=new \SLiMS\Plugins\Inventory\Supervision($db,new \SLiMS\Plugins\Inventory\PhotoStorage($directory));$import=new H($db,$watch);
if(PHP_SAPI==='cli-server') {
 define('INDEX_AUTH',true);define('SB',sys_get_temp_dir().'/'.$prefix.'bootstrap/');define('LIB',SB.'lib/');define('AWB','/');define('SWB','/');
 class utility{public static function havePrivilege($module,$mode='r'){return ($_GET['access']??'write')!=='none'&&($mode==='r'||($_GET['access']??'write')==='write');}}
 function do_checkIP($scope){}function writeLog(...$args){}
 require __DIR__.'/../src/WatchController.php';return;
}
$server=null;$path=tempnam(sys_get_temp_dir(),'history-import-');$bootstrap=sys_get_temp_dir().'/'.$prefix.'bootstrap';
try {
 $db->exec('CREATE TABLE user (user_id INT PRIMARY KEY,realname VARCHAR(255)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE mst_location (location_id VARCHAR(3) PRIMARY KEY,location_name VARCHAR(255)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE inventory_locations (id INT UNSIGNED PRIMARY KEY,room_name VARCHAR(255),location_code VARCHAR(100),slims_location_id VARCHAR(3)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE inventory_items (id INT UNSIGNED PRIMARY KEY,location_id INT UNSIGNED,item_name VARCHAR(255),item_code VARCHAR(150),FOREIGN KEY(location_id) REFERENCES inventory_locations(id)) ENGINE=InnoDB');
 $db->exec("INSERT INTO user VALUES(1,'Pengimpor'),(2,'Pemeriksa asli'),(3,'Pelaksana asli'),(4,'Verifikator asli')");
 $db->exec("INSERT INTO mst_location VALUES('A','Lokasi A'),('B','Lokasi B')");
 $db->exec("INSERT INTO inventory_locations VALUES(1,'Ruang Baca','CARD','A'),(2,'Ruang Baca','CARD','B')");
 $db->exec("INSERT INTO inventory_items VALUES(1,1,'Meja A','A-001'),(2,2,'Meja B','B-001')");
 (new \CreateInventorySupervision())->up();
 $row=array_combine(X::CHECKS,['HIST-001','1','2025-08-15','2','2','Checklist lama','Sarana','Meja','2','Perlu tindakan','Meja rusak','3','Sedang','2025-08-20'])+['_row'=>2];
 $action=array_combine(X::ACTIONS,['HIST-001','1','2025-08-16','Perbaikan','3','Mengganti kaki meja','125000.50','Arsip 2025/08','4','2025-08-17','Sudah baik'])+['_row'=>2];
 $sheets=['Pemeriksaan'=>[$row],'Tindak_lanjut'=>[$action]];
 $preview=H::preview($import->validate($sheets));check($preview['closed']===1&&$preview['rows'][0]['library']==='Lokasi B','preview resolves duplicate room names by ID and verification status');
 check((int)$db->query('SELECT COUNT(*) FROM inventory_watch_inspections')->fetchColumn()===0,'preview makes no writes');
 foreach(['id_ruangan'=>'999','id_pemeriksa'=>'999','id_barang'=>'1','tanggal_pemeriksaan'=>'2999-01-01','hasil'=>'Selesai','kelompok'=>'Lain','prioritas'=>'Kritis','tenggat'=>'2025-08-01','catatan'=>''] as $field=>$value){$bad=$sheets;$bad['Pemeriksaan'][0][$field]=$value;reject(fn()=>$import->validate($bad),'invalid '.$field.' rejected');}
 foreach(['nomor_butir'=>'99','tanggal_pekerjaan'=>'2025-08-01','biaya'=>'1.000,50','id_pelaksana'=>'999','tanggal_verifikasi'=>'2025-08-15','id_verifikator'=>'','catatan_verifikasi'=>''] as $field=>$value){$bad=$sheets;$bad['Tindak_lanjut'][0][$field]=$value;reject(fn()=>$import->validate($bad),'invalid action '.$field.' rejected');}
 $bad=$sheets;$bad['Pemeriksaan'][]=$row;reject(fn()=>$import->validate($bad),'duplicate result rejected');
 $bad=$sheets;$bad['Tindak_lanjut'][]=$action;reject(fn()=>$import->validate($bad),'duplicate action rejected');
 $bad=$sheets;$bad['Pemeriksaan'][]=array_replace($row,['nomor_butir'=>'2','id_ruangan'=>'1','id_barang'=>'1']);reject(fn()=>$import->validate($bad),'inconsistent inspection header rejected');
 $result=$import->save($sheets,1);$id=$result['ids'][0];$doc=$watch->document($id);
 check($doc['inspection']['kind']==='historical'&&$doc['inspection']['status']==='final'&&$doc['inspection']['due_date']==='2025-08-15'&&$doc['inspection']['performed_date']==='2025-08-15','historical period and completion retained');
 check($doc['inspection']['examiner_name']==='Pemeriksa asli'&&$doc['actions'][0]['actor_name']==='Pelaksana asli'&&$doc['events'][0]['actor_name']==='Pengimpor','original actors and importer recorded separately');
 check($doc['findings'][0]['status']==='closed'&&$doc['findings'][0]['closed_at']==='2025-08-17 00:00:00'&&$doc['actions'][0]['cost']==='125000.50','verified work, date and exact cost preserved');
 check(str_contains($doc['actions'][0]['description'],'Arsip 2025/08')&&count($doc['photos'])===0,'archive reference stored without fabricated photos');
 check((int)$db->query('SELECT COUNT(*) FROM inventory_watch_schedules')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM inventory_watch_templates')->fetchColumn()===0,'import creates no recurring schedules or templates');
 reject(fn()=>$import->save($sheets,1),'repeated reference rejected');
 $bad=$sheets;$bad['Pemeriksaan'][0]['nomor_pemeriksaan']='hist-001';$bad['Tindak_lanjut'][0]['nomor_pemeriksaan']='hist-001';reject(fn()=>$import->save($bad,1),'case variant duplicate rejected');
 $unverified=$sheets;$unverified['Pemeriksaan'][0]['nomor_pemeriksaan']='HIST-002';$unverified['Tindak_lanjut'][0]=array_replace($action,['nomor_pemeriksaan'=>'HIST-002','id_verifikator'=>'','tanggal_verifikasi'=>'','catatan_verifikasi'=>'']);
 $id2=$import->save($unverified,1)['ids'][0];check($watch->document($id2)['findings'][0]['status']==='review','completed work without historical verification awaits verification');
 $open=$sheets;$open['Pemeriksaan'][0]['nomor_pemeriksaan']='HIST-003';$open['Tindak_lanjut']=[];$id3=$import->save($open,1)['ids'][0];check($watch->document($id3)['findings'][0]['status']==='open','unresolved historical finding remains open');
 $summary=$watch->summary($watch->filter(['from'=>'2025-08-01','to'=>'2025-08-31']));check((int)$summary['counts']['historical']===3&&(int)$summary['counts']['routine']===0,'historical records report in original period separately from routine');
 // Force a late database error after the inspection/result/finding have been inserted.
 $db->exec("CREATE TRIGGER {$prefix}fail_action BEFORE INSERT ON inventory_watch_actions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture failure'");
 $rollback=$unverified;$rollback['Pemeriksaan'][0]['nomor_pemeriksaan']='HIST-ROLLBACK';$rollback['Tindak_lanjut'][0]['nomor_pemeriksaan']='HIST-ROLLBACK';
 reject(fn()=>$import->save($rollback,1),'late failure rejected');check((int)$db->query('SELECT COUNT(*) FROM inventory_watch_inspections')->fetchColumn()===3,'late failure rolls back entire import');$db->exec("DROP TRIGGER {$prefix}fail_action");
 // Exercise actual multipart uploads, authorization, CSRF, preview tokens and replay.
 putenv('INVENTORY_HISTORY_TEST_PREFIX='.$prefix);
 mkdir($bootstrap.'/lib',0700,true);mkdir($bootstrap.'/admin/default',0700,true);
 file_put_contents($bootstrap.'/lib/ip_based_access.inc.php','<?php');file_put_contents($bootstrap.'/admin/default/session_check.inc.php','<?php');
 file_put_contents($bootstrap.'/admin/default/session.inc.php','<?php session_start(); $_SESSION["uid"]=1; $_SESSION["inventory_watch_csrf"]="test-csrf";');
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);$address=stream_socket_get_name($socket,false);fclose($socket);
 $server=proc_open([PHP_BINARY,'-S',$address,__FILE__],[0=>['pipe','r'],1=>['file',$bootstrap.'/http.log','a'],2=>['file',$bootstrap.'/http.log','a']],$pipes);fclose($pipes[0]);
 $until=microtime(true)+5;do{$conn=@stream_socket_client('tcp://'.$address,$errno,$errstr,.2);if($conn){fclose($conn);break;}usleep(10000);}while(microtime(true)<$until);
 $cookie=$bootstrap.'/cookies';
 $http=function(array $query,array $post=[])use($address,$cookie){$c=curl_init('http://'.$address.'/?'.http_build_query($query));curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_COOKIEFILE=>$cookie]);if($post)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post]);$body=curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$code,$body];};
 [$status,$body]=$http(['access'=>'read'],['watch_action'=>'history_preview','csrf_token'=>'test-csrf']);check($status===403,'read-only import rejected');
 [$status]=$http([],['watch_action'=>'history_preview','csrf_token'=>'bad']);check($status===403,'invalid CSRF rejected');
 [$status]=$http([],['watch_action'=>'history_commit','csrf_token'=>'test-csrf','token'=>'invalid']);check($status===422,'commit requires valid preview');
 [$status,$body]=$http(['tab'=>'history_template']);file_put_contents($path,$body);check($status===200&&X::read($path)===['Pemeriksaan'=>[],'Tindak_lanjut'=>[]],'template endpoint returns valid workbook');
 $upload=$unverified;$upload['Pemeriksaan'][0]['nomor_pemeriksaan']='HIST-HTTP';$upload['Tindak_lanjut'][0]['nomor_pemeriksaan']='HIST-HTTP';
 $raw=[];foreach(['Pemeriksaan'=>X::CHECKS,'Tindak_lanjut'=>X::ACTIONS] as $name=>$headers){$raw[$name]=[$headers];foreach($upload[$name] as $r){unset($r['_row']);$raw[$name][]=array_values($r);}}
 file_put_contents($path,X::write($raw));
 [$status,$body]=$http([],['watch_action'=>'history_preview','csrf_token'=>'test-csrf','workbook'=>new \CURLFile($path,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','riwayat.xlsx')]);$reply=json_decode($body,true);check($status===200&&isset($reply['data']['token']),'multipart preview returns validation token');
 $commit=['watch_action'=>'history_commit','csrf_token'=>'test-csrf','token'=>$reply['data']['token'],'request_id'=>'12345678-1234-1234-1234-123456789abc'];
 [$status,$body]=$http([],$commit);$reply=json_decode($body,true);check($status===200&&$reply['data']['inspections']===1,'HTTP commit saves validated workbook');
 [$status,$again]=$http([],$commit);check($status===200&&$again===$body&&(int)$db->query('SELECT COUNT(*) FROM inventory_watch_inspections')->fetchColumn()===4,'retry returns committed result without duplicate');
} finally {
 if(is_resource($server)){proc_terminate($server);proc_close($server);}
 if($db->inTransaction())$db->rollBack();
 $db->exec("DROP TRIGGER IF EXISTS {$prefix}fail_action");
 foreach($db->names as $table)$db->exec('DROP TABLE IF EXISTS '.$table);
 if(is_file($path))unlink($path);
 foreach([$directory,$bootstrap] as $dir)if(is_dir($dir)){foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($dir);}
}
}
