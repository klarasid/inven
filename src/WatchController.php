<?php
// Routed by the authenticated SLiMS admin plugin container.
defined('INDEX_AUTH') || die('Direct access not allowed!');
require LIB . 'ip_based_access.inc.php';
do_checkIP('smc'); do_checkIP('smc-stocktake');
require SB . 'admin/default/session.inc.php';
require_once SB . 'admin/default/session_check.inc.php';
require_once __DIR__ . '/PhotoStorage.php';
require_once __DIR__ . '/ItemPhotos.php';
require_once __DIR__ . '/WatchRecurrence.php';
require_once __DIR__ . '/Supervision.php';
require_once __DIR__ . '/WatchView.php';
use SLiMS\Plugins\Inventory\Supervision;
use SLiMS\Plugins\Inventory\WatchView;
use SLiMS\Plugins\Inventory\InventoryUi;
require_once __DIR__ . '/InventoryUi.php';
require_once __DIR__ . '/Workspace.php';
require_once __DIR__ . '/WorkspaceRequests.php';
require_once __DIR__ . '/HistoryWorkbook.php';
require_once __DIR__ . '/HistoryImport.php';

$canRead=utility::havePrivilege('stock_take','r');
$canWrite=utility::havePrivilege('stock_take','w');
function watch_log(string $action,string $message): void {
    try { writeLog('staff',(string)($_SESSION['uid']??0),'Pengawasan & Pemeliharaan',$message,'stock_take',$action); }
    catch (Throwable $e) { error_log('Supervision audit: '.$e->getMessage()); }
}
$isPost=$_SERVER['REQUEST_METHOD']==='POST';
if (!$canRead || ($isPost && !$canWrite)) {
    http_response_code(403); watch_log('Denied','Akses pengawasan ditolak.');
    if ($isPost) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'message'=>'Anda tidak memiliki hak akses pengawasan.']); }
    else echo '<div class="alert alert-danger">Anda tidak memiliki hak baca pengawasan.</div>';
    return;
}
if (empty($_SESSION['inventory_watch_csrf'])) $_SESSION['inventory_watch_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['inventory_watch_csrf'];
$db=\SLiMS\DB::getInstance(); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
// Subdirectory inherits the existing web-server denial for inventory photos.
$storage=new \SLiMS\Plugins\Inventory\PhotoStorage(SB.'images/inventaris-barang/pengawasan');
$watch=new Supervision($db,$storage);
$tab=(string)($_GET['tab']??($inventoryWatchPage??'reports'));
if ($tab==='dashboard') $tab='reports';
$base=InventoryUi::url(['tab'=>$tab]);
try {
    if ($isPost) {
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store');
        if (!is_string($_POST['csrf_token']??null) || !hash_equals($csrf,$_POST['csrf_token'])) {
            http_response_code(403); watch_log('Denied','Token CSRF pengawasan ditolak.'); throw new RuntimeException('Token formulir tidak valid. Muat ulang halaman.');
        }
        if ($cached=\SLiMS\Plugins\Inventory\WorkspaceRequests::cached('watch')) { echo json_encode($cached); return; }
        $action=(string)($_POST['watch_action']??'');
        if (in_array($action,['history_preview','history_commit'],true)) {
            $import=new \SLiMS\Plugins\Inventory\HistoryImport($db,$watch);
            if ($action==='history_preview') {
                unset($_SESSION['inventory_history_preview']);
                $file=$_FILES['workbook']??[];
                if (!is_array($file) || ($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Pilih berkas Excel .xlsx maksimal 2 MB.');
                if (strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION))!=='xlsx') throw new RuntimeException('Format yang diterima adalah .xlsx.');
                $sheets=\SLiMS\Plugins\Inventory\HistoryWorkbook::read($file['tmp_name']);
                $groups=$import->validate($sheets);
                $token=bin2hex(random_bytes(32));
                $_SESSION['inventory_history_preview']=['token'=>$token,'uid'=>(int)$_SESSION['uid'],'expires'=>time()+1800,'sheets'=>$sheets];
                $data=\SLiMS\Plugins\Inventory\HistoryImport::preview($groups)+['token'=>$token];
                echo json_encode(['ok'=>true,'data'=>$data]); return;
            }
            $preview=$_SESSION['inventory_history_preview']??null;
            if (!$preview || $preview['uid']!==(int)$_SESSION['uid'] || $preview['expires']<time() || !is_string($_POST['token']??null) || !hash_equals($preview['token'],$_POST['token'])) throw new RuntimeException('Pratinjau berakhir atau berubah. Unggah dan validasi ulang berkas.');
            $data=$import->save($preview['sheets'],(int)$_SESSION['uid']);
            unset($_SESSION['inventory_history_preview']);
            watch_log('Import','Impor riwayat pemeriksaan: '.Supervision::json($data['ids']));
            echo json_encode(\SLiMS\Plugins\Inventory\WorkspaceRequests::remember('watch',['ok'=>true,'message'=>'Riwayat berhasil diimpor.','data'=>$data])); return;
        }
        $result=$watch->mutate($action,$_POST,$_FILES['photos']??[],(int)($_SESSION['uid']??0));
        watch_log('Update','Aksi '.$action.'; '.Supervision::json($result));
        $navigation=array_intersect_key($_GET,array_flip(['library','room','from','to','inspection_status','finding_status','return_tab','list_page']));
        $navigation=array_merge($navigation,array_intersect_key($result,array_flip(['tab','record','template_id','schedule_id'])));
        if ($action==='template') { $navigation['panel']='templates'; unset($navigation['template_id']); }
        $metadata=$result['document']??null;
        if ($metadata) foreach ($metadata['photos'] as &$photo) $photo['url']=InventoryUi::url(['tab'=>'photo','inspection_id'=>$metadata['id'],'photo_id'=>$photo['id']]);
        unset($photo);
        echo json_encode(\SLiMS\Plugins\Inventory\WorkspaceRequests::remember('watch',['ok'=>true,'message'=>'Data pengawasan berhasil disimpan.','url'=>InventoryUi::url($navigation),'document'=>$metadata,'generated'=>$result['generated']??0,'more'=>$result['more']??false]));
        return;
    }
    if ($tab==='history_template') {
        $rooms=$watch->query('SELECT l.id,l.room_name,l.slims_location_id,ml.location_name FROM inventory_locations l LEFT JOIN mst_location ml ON ml.location_id=l.slims_location_id ORDER BY ml.location_name,l.room_name,l.id')->fetchAll(PDO::FETCH_NUM);
        $users=$watch->query('SELECT user_id,realname FROM user ORDER BY realname')->fetchAll(PDO::FETCH_NUM);
        $items=$watch->query('SELECT id,location_id,item_code,item_name FROM inventory_items ORDER BY location_id,item_name,id')->fetchAll(PDO::FETCH_NUM);
        $bytes=\SLiMS\Plugins\Inventory\HistoryWorkbook::template($rooms,$users,$items);
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="template-riwayat-pemeriksaan.xlsx"');
        echo $bytes; return;
    }
    if ($tab==='photo') {
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        $bytes=$watch->photo((int)($_GET['inspection_id']??0),(int)($_GET['photo_id']??0));
        if ($bytes===null) throw new RuntimeException('Berkas foto tidak tersedia.');
        header('Content-Type: image/jpeg'); header('Content-Disposition: inline; filename="bukti-pengawasan.jpg"'); echo $bytes; return;
    }
    if ($tab==='scope') {
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store');
        $template=$watch->row('templates',(int)($_GET['template_id']??0));
        $assets=$watch->query('SELECT id,item_name,item_code FROM inventory_items WHERE location_id=? ORDER BY item_name',[(int)($_GET['location_id']??0)])->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'items'=>Supervision::decode($template['items']),'assets'=>$assets]); return;
    }
    if (($_GET['workspace']??'')==='api') {
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store');
        echo json_encode(['ok'=>true,'data'=>\SLiMS\Plugins\Inventory\Workspace::read($watch,$_GET,(int)($_SESSION['uid']??0))]); return;
    }
    if (!in_array($tab,['pdf'],true)) {
        $view=$inventoryWorkspaceView??'tasks';
        \SLiMS\Plugins\Inventory\Workspace::shell($view,$canWrite); return;
    }
    $filter=$watch->filter($_GET);
    if (in_array($tab,['reports','pdf'],true)) { $filter['inspection_status']=''; $filter['finding_status']=''; }
    if ($tab==='pdf') {
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        $requests=array_values(array_filter((array)($_SESSION['inventory_watch_pdf_requests']??[]),static fn($time)=>is_int($time)&&$time>time()-60));
        if (count($requests)>=10) { http_response_code(429); header('Retry-After: 60'); throw new RuntimeException('Terlalu banyak permintaan PDF. Coba lagi satu menit kemudian.'); }
        $requests[]=time(); $_SESSION['inventory_watch_pdf_requests']=$requests;
        session_write_close();
        $autoload=dirname(__DIR__).'/vendor/autoload.php'; if (is_file($autoload)) require_once $autoload;
        if (!class_exists(\Mpdf\Mpdf::class)) throw new RuntimeException('Dependensi mPDF belum tersedia. Jalankan composer install di direktori plugin.');
        require_once __DIR__ . '/WatchPdf.php';
        $id=(int)($_GET['record']??0);
        if ($id) $html=\SLiMS\Plugins\Inventory\WatchPdf::detail($watch->document($id),fn($photo)=>$watch->photo($id,(int)$photo['id']));
        else {
            $rows=$watch->inspections($filter,1,501);
            if (count($rows)>500) { http_response_code(413); throw new RuntimeException('Laporan melebihi 500 pemeriksaan. Persempit periode atau pilih ruangan.'); }
            $html=\SLiMS\Plugins\Inventory\WatchPdf::summary($filter,$watch->summary($filter,true),$rows);
        }
        $temp=SB.FLS.DS.'cache';
        $pdf=new \Mpdf\Mpdf(['tempDir'=>$temp,'exposeVersion'=>false]);
        $pdf->SetTitle('Pengawasan dan Pemeliharaan Perpustakaan'); $pdf->WriteHTML($html);
        watch_log('Print','Laporan pengawasan '.($id?'#'.$id:Supervision::json($filter)));
        $pdf->Output('pengawasan-pemeliharaan.pdf','I'); return;
    }
    WatchView::render($watch,$base,$tab,$filter,$canWrite,$csrf,$_GET);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $expected=$e instanceof RuntimeException && !($e instanceof PDOException);
    $schema=$e instanceof PDOException && in_array((int)($e->errorInfo[1]??0),[1146,1054],true);
    $message=$schema?'Struktur pengawasan belum tersedia. Jalankan migrasi plugin hingga versi 7 melalui System → Plugins.':($expected?$e->getMessage():'Operasi pengawasan gagal. Periksa log PHP.');
    if (!$expected) error_log('Supervision error: '.$e->getMessage());
    if ($isPost || $tab==='scope' || ($_GET['workspace']??'')==='api') { header('Content-Type: application/json; charset=utf-8'); if (http_response_code()<400) http_response_code(str_contains($message,'sesi lain')?409:422); echo json_encode(['ok'=>false,'message'=>$message,'errors'=>\SLiMS\Plugins\Inventory\WorkspaceRequests::errors($message)]); }
    else { if ($tab==='photo') http_response_code(404); header('Content-Type: text/html; charset=utf-8'); echo '<div class="alert alert-danger">'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</div>'; }
}
