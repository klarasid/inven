<?php
namespace SLiMS\Plugins\Inventory;

require_once __DIR__ . '/InventoryUi.php';
require_once __DIR__ . '/WatchReports.php';
require_once __DIR__ . '/WatchSetup.php';
require_once __DIR__ . '/WatchInspections.php';
require_once __DIR__ . '/WatchFindings.php';

final class WatchView
{
    use WatchReports, WatchSetup, WatchInspections, WatchFindings;
    private static string $base, $csrf;
    private static array $users, $context = [];
    private static string $tab;
    public static function e($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
    private static function url(array $params=[]): string { return InventoryUi::url(array_merge(self::$context,$params)); }
    private static function link(string $label,array $params=[],bool $pdf=false): void { echo '<a class="btn btn-sm btn-default '.($pdf?'notAJAX':((strpos($label,'+')===0 || $label==='Pemeriksaan insidental')?'btn-primary':'')).'" '.($pdf?'target="_blank" rel="noopener"':'').' href="'.self::e(self::url($params)).'">'.self::e($label).'</a> '; }
    private static function hidden(string $name,$value): void { echo '<input type="hidden" name="'.self::e($name).'" value="'.self::e($value).'">'; }
    private static function form(string $action,array $hidden=[],string $component='inventoryForm'): void {
        echo '<form novalidate class="watch-form" method="post" enctype="multipart/form-data" action="'.self::e(self::$base).'" x-data="'.$component.'" @submit.prevent.stop="save($event)" @input="$dispatch(&quot;inventory-dirty&quot;)">';
        self::hidden('csrf_token',self::$csrf); self::hidden('watch_action',$action);
        foreach ($hidden as $name=>$value) self::hidden($name,$value);
        echo '<div class="alert alert-danger watch-error" role="alert" x-show="error" x-cloak x-text="error"></div><p class="inv-save-status" role="status" x-show="busy" x-cloak>Menyimpan…</p>';
    }
    private static function end(string $button='Simpan'): void { echo '<button class="btn btn-primary" :disabled="busy" type="submit">'.self::e($button).'</button></form>'; }
    private static function input(string $label,string $name,$value='',string $type='text',bool $required=false): void {
        echo '<label class="watch-field">'.self::e($label).'<input class="form-control" type="'.self::e($type).'" name="'.self::e($name).'" value="'.self::e($value).'" '.($required?'required':'').' '.($type==='number'?'min="0" step="0.01"':'').'></label>';
    }
    private static function textarea(string $label,string $name,$value='',bool $required=false): void {
        echo '<label class="watch-field">'.self::e($label).'<textarea class="form-control" rows="2" name="'.self::e($name).'" '.($required?'required':'').'>'.self::e($value).'</textarea></label>';
    }
    private static function select(string $label,string $name,array $options,$selected='',bool $required=false): void {
        echo '<label class="watch-field">'.self::e($label).'<select class="form-control" name="'.self::e($name).'" '.($required?'required':'').'>';
        foreach ($options as $key=>$text) echo '<option value="'.self::e($key).'" '.((string)$key===(string)$selected?'selected':'').'>'.self::e($text).'</option>';
        echo '</select></label>';
    }
    private static function actorOptions(): array { return [''=>'Pilih petugas']+array_column(self::$users,'realname','user_id'); }
    private static function status(string $value): string { return Supervision::STATUSES[$value]??$value; }
    private static function badge(string $value): string { return '<span class="inv-badge inv-status-'.self::e($value).'">'.self::e(self::status($value)).'</span>'; }
    private static function photos(array $rows,bool $editable=false): void {
        echo '<div class="watch-photos">';
        foreach ($rows as $photo) {
            $url=self::url(['tab'=>'photo','inspection_id'=>$photo['inspection_id'],'photo_id'=>$photo['id']]);
            echo '<div><a class="notAJAX" href="'.self::e($url).'" target="_blank" rel="noopener"><img loading="lazy" alt="Bukti pengawasan" src="'.self::e($url).'"></a>';
            if ($editable) echo '<label><input type="checkbox" name="remove[]" value="'.(int)$photo['id'].'"> Hapus foto</label>';
            echo '</div>';
        }
        echo '</div>';
    }
    private static function upload(): void {
        echo '<div x-data="inventoryUpload"><label class="watch-field">Foto bukti<input type="file" class="form-control" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple @change="change($event)"></label><div class="watch-photos"><template x-for="preview in previews" :key="preview"><img :src="preview" alt="Pratinjau foto hasil pekerjaan"></template></div><p class="text-muted">Maksimal 5 foto per hasil/catatan, masing-masing 2 MB. JPEG, PNG, atau WebP.</p></div>';
    }
    public static function render(Supervision $watch,string $base,string $tab,array $filter,bool $write,string $csrf,array $get): void {
        if ($tab==='dashboard') $tab='reports';
        self::$base=$base; self::$csrf=$csrf; self::$tab=$tab;
        self::$context=array_merge($filter,array_intersect_key($get,array_flip(['return_tab','list_page'])));
        if (in_array($tab,['inspections','findings','reports'],true)) {
            self::$context['return_tab']=$tab; self::$context['list_page']=max(1,(int)($get['page']??1));
        }
        self::$users=$watch->query('SELECT user_id,realname FROM user ORDER BY realname')->fetchAll(\PDO::FETCH_ASSOC);
        $rooms=$watch->query('SELECT id,room_name,slims_location_id FROM inventory_locations ORDER BY room_name')->fetchAll(\PDO::FETCH_ASSOC);
        $libraries=$watch->query('SELECT location_id,location_name FROM mst_location ORDER BY location_name')->fetchAll(\PDO::FETCH_KEY_PAIR);
        foreach ($rooms as $index=>$room) {
            $code=(string)($room['slims_location_id']??'');
            $location=$libraries[$code]??'Lokasi belum ditentukan';
            $rooms[$index]['room_label']=$room['room_name'].' — '.$location.($code!==''?' ('.$code.')':'');
        }
        $templates=$watch->query('SELECT * FROM inventory_watch_templates ORDER BY id DESC')->fetchAll(\PDO::FETCH_ASSOC);
        self::$base=InventoryUi::url(array_merge(self::$context,['tab'=>$tab]));
        InventoryUi::assets();
        echo '<div class="inventory-ui watch" id="inventory-watch" x-data="inventoryPage" @inventory-dirty="dirty=true" @inventory-clean="dirty=false" data-base="'.self::e($base).'" data-csrf="'.self::e($csrf).'" data-write="'.($write?'1':'0').'" data-list="'.(in_array($tab,['setup','inspections','findings','reports'],true)?'1':'0').'" data-refresh="'.self::e(self::url(array_merge($get,['tab'=>$tab]))).'">';
        InventoryUi::header(InventoryUi::section($tab));
        if (!in_array($tab,['setup','inspections','findings','reports'],true)) {
            $back=in_array($get['return_tab']??'', ['inspections','findings','reports'],true)?$get['return_tab']:InventoryUi::section($tab);
            echo '<div class="inv-breadcrumb">'; self::link('← '.InventoryUi::MENUS[$back][1],['tab'=>$back,'page'=>max(1,(int)($get['list_page']??1))]); echo '<span> / Detail</span></div>';
        }
        echo '<div class="watch-sync-status" role="status" x-show="syncMessage" x-cloak><span x-text="syncMessage"></span></div>';
        if (in_array($tab,['setup','inspections','findings','reports'],true)) {
            echo '<form class="watch-filter watch-grid" method="get" @submit.prevent="filter($event.target)">';
            self::hidden('tab',$tab);
            self::select('Perpustakaan','library',[''=>'Semua perpustakaan']+$libraries,$filter['library']);
            self::select('Ruangan','room',[''=>'Semua ruangan']+array_column($rooms,'room_label','id'),$filter['room']);
            self::input('Dari tanggal','from',$filter['from'],'date',true); self::input('Sampai tanggal','to',$filter['to'],'date',true);
            if ($tab==='inspections') self::select('Status','inspection_status',[''=>'Semua status','pending'=>'Belum dimulai','draft'=>'Draf','final'=>'Difinalisasi'],$filter['inspection_status']);
            if ($tab==='findings') self::select('Status','finding_status',[''=>'Semua status','open'=>'Terbuka','working'=>'Dikerjakan','review'=>'Menunggu verifikasi','closed'=>'Selesai'],$filter['finding_status']);
            echo '<div class="inv-filter-actions"><button class="btn btn-default" type="submit">Terapkan</button> <a href="'.self::e(InventoryUi::url(['tab'=>$tab])).'">Reset</a></div></form>';
        }
        if ($tab==='setup') self::setup($watch,$rooms,$templates,$write,$get,$filter);
        elseif ($tab==='template' && $write) self::templateEditor($watch,$get);
        elseif ($tab==='schedule' && $write) self::newInspection($watch,$rooms,$templates,$get,false);
        elseif ($tab==='inspection') self::inspection($watch,(int)($get['record']??0),$write);
        elseif ($tab==='finding') self::finding($watch,(int)($get['record']??0),$write);
        elseif ($tab==='new' && $write) self::newInspection($watch,$rooms,$templates,$get,true);
        elseif ($tab==='inspections') self::listing($watch,$filter,$write,(int)($get['page']??1));
        elseif ($tab==='findings') self::findings($watch,$filter,(int)($get['page']??1));
        elseif ($tab==='reports') {
            self::metrics($watch->summary($filter));
            echo '<div class="watch-card inv-row"><div><h3>Laporan periode</h3><p>Periode mengikuti tanggal jadwal atau pencatatan insidental.</p></div>';
            self::link('Cetak PDF periode',array_merge($filter,['tab'=>'pdf']),true); echo '</div>';
            self::listing($watch,$filter,false,(int)($get['page']??1));
        } else echo '<div class="inventory-empty">Halaman tidak tersedia atau memerlukan hak tulis.</div>';
        echo '</div>';
    }
    private static function events(array $events): void {
        echo '<h4>Riwayat kegiatan</h4>';
        if (!$events) echo '<p class="text-muted">Belum ada kegiatan tercatat.</p>';
        $names=['start'=>'Mulai pekerjaan','save_draft'=>'Simpan draf','finalize'=>'Finalisasi','correction'=>'Koreksi','save_action'=>'Simpan tindakan','submit'=>'Ajukan verifikasi','verify'=>'Verifikasi diterima','reject'=>'Verifikasi ditolak'];
        foreach ($events as $event) echo '<p class="inv-event"><strong>'.self::e($names[$event['event']]??$event['event']).'</strong> — '.self::e($event['actor_name']).' · '.self::e($event['created_at']).'<br>'.nl2br(self::e($event['notes'])).'</p>';
    }

}
