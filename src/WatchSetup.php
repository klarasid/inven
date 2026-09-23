<?php
namespace SLiMS\Plugins\Inventory;

trait WatchSetup
{
    private static function setup(Supervision $watch,array $rooms,array $templates,bool $write,array $get,array $filter): void {
        // Accept legacy setup links while making the default page a focused list.
        if ($write && !empty($get['replaces_id'])) { self::newInspection($watch,$rooms,$templates,$get,false); return; }
        if ($write && !empty($get['template_id'])) { self::templateEditor($watch,$get); return; }
        echo '<div x-data="{panel:'.InventoryUi::json(($get['panel']??'')==='templates'?'templates':'schedules').'}"><div class="inv-tabs" role="tablist" aria-label="Checklist dan jadwal"><button type="button" role="tab" :aria-selected="panel===\'schedules\'" @click="panel=\'schedules\'">Jadwal pemeriksaan</button><button type="button" role="tab" :aria-selected="panel===\'templates\'" @click="panel=\'templates\'">Template checklist</button></div>';
        echo '<section x-show="panel===\'schedules\'" class="watch-card"><div class="inv-row"><div><h3>Jadwal pemeriksaan</h3><p>Atur kegiatan rutin untuk setiap ruangan.</p></div>';
        if ($write) self::link('+ Buat jadwal',['tab'=>'schedule']);
        echo '</div><div class="table-wrap"><table><thead><tr><th>Ruangan / checklist</th><th>Frekuensi / petugas</th><th>Periode</th><th>Status</th><th>Aksi</th></tr></thead><tbody>';
        $schedules=$watch->query('SELECT * FROM inventory_watch_schedules ORDER BY id DESC')->fetchAll(\PDO::FETCH_ASSOC); $count=0;
        foreach ($schedules as $schedule) {
            $s=Supervision::decode($schedule['snapshot']);
            if (($filter['library']!=='' && $s['library_code']!==$filter['library'])||($filter['room'] && (int)$s['room_id']!==$filter['room'])) continue;
            if ($schedule['start_date']>$filter['to'] || ($schedule['end_date'] && $schedule['end_date']<$filter['from'])) continue;
            $count++;
            $active=$schedule['active'] && $schedule['location_id']!==null && (!$schedule['end_date']||$schedule['end_date']>=date('Y-m-d'));
            echo '<tr><td><strong>'.self::e($s['room_name']).'</strong><small>'.self::e($s['template_name']).'</small></td><td>'.self::e(WatchRecurrence::FREQUENCIES[$schedule['frequency']]).'<small>'.self::e($schedule['assignee_name']).'</small></td><td>'.self::e($schedule['start_date']).'<small>s.d. '.self::e($schedule['end_date']?:'seterusnya').'</small></td><td><span class="inv-badge '.($active?'inv-status-final':'').'">'.($active?'Aktif':'Tidak aktif').'</span></td><td>';
            if ($write && $active) {
                self::link('Ganti',['tab'=>'schedule','replaces_id'=>$schedule['id'],'location_id'=>$schedule['location_id'],'template_id'=>$schedule['template_id']]);
                echo '<details><summary>Hentikan jadwal</summary>';self::form('stop',['id'=>$schedule['id'],'version'=>$schedule['version']]);self::input('Tidak dijadwalkan mulai','effective',date('Y-m-d',strtotime('+1 day')),'date',true);self::end('Hentikan jadwal');echo '</details>';
            }
            echo '</td></tr>';
        }
        if (!$count) echo '<tr><td colspan="5" class="inventory-empty">Belum ada jadwal dalam periode ini. Pilih Buat jadwal untuk memulai, atau ubah filter.</td></tr>';
        echo '</tbody></table></div></section><section x-show="panel===\'templates\'" x-cloak class="watch-card"><div class="inv-row"><div><h3>Template checklist</h3><p>Revisi membuat versi baru. Jadwal lama tetap memakai versi sebelumnya.</p></div>';
        if ($write) self::link('+ Buat checklist',['tab'=>'template']);
        echo '</div><div class="table-wrap"><table><thead><tr><th>Nama checklist</th><th>Butir</th><th>Aksi</th></tr></thead><tbody>';
        foreach ($templates as $template) {
            echo '<tr><td><strong>'.self::e($template['name']).'</strong><small>Versi tersimpan #'.(int)$template['id'].'</small></td><td>'.count(Supervision::decode($template['items'])).' butir</td><td>';
            if ($write) self::link('Salin / revisi',['tab'=>'template','template_id'=>$template['id']]);
            else echo '<details><summary>Lihat butir</summary>'; 
            if (!$write) { foreach (Supervision::decode($template['items']) as $item) echo '<p>'.self::e($item['group'].' — '.$item['object']).'<br>'.self::e($item['instruction']).'</p>'; echo '</details>'; }
            echo '</td></tr>';
        }
        if (!$templates) echo '<tr><td colspan="3" class="inventory-empty">Belum ada checklist. Buat checklist pertama dari contoh yang tersedia.</td></tr>';
        echo '</tbody></table></div></section></div>';
    }

    private static function templateEditor(Supervision $watch,array $get): void {
        $selected=!empty($get['template_id'])?$watch->row('templates',(int)$get['template_id']):null;
        $items=$selected?Supervision::decode($selected['items']):[
            ['group'=>'Sarana','object'=>'Meja, kursi, dan rak','instruction'=>'Periksa kestabilan, kelengkapan, dan kerusakan.'],
            ['group'=>'Prasarana','object'=>'Lantai, atap, dan pintu','instruction'=>'Periksa kebocoran, kerusakan, dan hambatan akses.'],
            ['group'=>'Lingkungan Fisik','object'=>'Kebersihan dan kenyamanan','instruction'=>'Periksa kebersihan, pencahayaan, ventilasi, dan jalur keluar.']];
        echo '<section class="watch-card"><h3>'.($selected?'Revisi checklist':'Buat checklist').'</h3><p>Sesuaikan contoh dengan kebutuhan ruangan. Contoh bukan standar penilaian resmi.</p>';
        self::form('template',['source_id'=>$selected['id']??0]);
        self::input('Nama checklist','name',$selected?$selected['name'].' (revisi)':'Checklist pemeriksaan ruangan','text',true);
        echo '<div x-data="inventoryChecklist('.InventoryUi::json($items).')"><div class="inv-row"><h4>Butir pemeriksaan</h4><span x-text="items.length+\' / 100 butir\'"></span></div><template x-for="(item,index) in items" :key="item.key"><section class="inv-checklist-row"><div class="inv-row"><strong x-text="\'Butir \'+(index+1)"></strong><button type="button" class="btn btn-default" @click="remove(index)" :disabled="items.length===1">Hapus butir</button></div><div class="watch-grid"><label class="watch-field">Kelompok<select class="form-control" :name="\'items[\'+index+\'][group]\'" x-model="item.group"><option>Sarana</option><option>Prasarana</option><option>Lingkungan Fisik</option></select></label><label class="watch-field">Objek<input required class="form-control" :name="\'items[\'+index+\'][object]\'" x-model="item.object"></label></div><label class="watch-field">Petunjuk pemeriksaan<textarea class="form-control" rows="2" :name="\'items[\'+index+\'][instruction]\'" x-model="item.instruction"></textarea></label></section></template><button type="button" class="btn btn-default" @click="add()" :disabled="items.length>=100">+ Tambah butir</button></div><div class="inv-footer">';
        echo '<button class="btn btn-primary" type="submit" :disabled="busy">Simpan versi checklist</button> '; self::link('Batal',['tab'=>'setup','panel'=>'templates']);echo '</div></form></section>';
    }

    private static function scopeForm(Supervision $watch,array $rooms,array $templates,array $get,bool $incidental): void {
        echo '<h3>'.($incidental?'Pemeriksaan insidental / ulang':(!empty($get['replaces_id'])?'Ganti jadwal':'Buat jadwal')).'</h3>';
        if (!$rooms || !$templates) { echo '<div class="inventory-empty">Tambahkan ruangan dan template checklist terlebih dahulu.</div>';self::link('Kelola ruangan',['tab'=>'inventory']);self::link('Kelola checklist',['tab'=>'setup','panel'=>'templates']);return; }
        $old=!empty($get['replaces_id'])?$watch->row('schedules',(int)$get['replaces_id']):null;
        $location=(int)($get['location_id']??$rooms[0]['id']);$template=(int)($get['template_id']??$templates[0]['id']);
        $chosen=$watch->row('templates',$template);
        $assets=$watch->query('SELECT id,item_name,item_code FROM inventory_items WHERE location_id=? ORDER BY item_name',[$location])->fetchAll(\PDO::FETCH_ASSOC);
        $mapping=[];
        if ($old && (int)$old['template_id']===$template) foreach (Supervision::decode($old['snapshot'])['items'] as $item) $mapping[]=(string)($item['item_id']??'');
        $config=['location'=>$location,'template'=>$template,'items'=>Supervision::decode($chosen['items']),'assets'=>$assets,'mapping'=>$mapping,'scopeUrl'=>InventoryUi::url(['tab'=>'scope'])];
        echo '<div x-data="inventoryWizard('.InventoryUi::json($config).')"><ol class="inv-steps"><li :class="{active:step===1}">1. Ruangan & checklist</li><li :class="{active:step===2}">2. Cakupan barang</li><li :class="{active:step===3}">3. '.($incidental?'Alasan pemeriksaan':'Waktu & petugas').'</li></ol>';
        self::form($incidental?'incidental':'schedule',['replaces_id'=>$old['id']??0,'version'=>$old['version']??0,'parent_id'=>$get['parent_id']??0]);
        echo '<div x-show="step===1" class="watch-grid"><label class="watch-field">Ruangan<select class="form-control" name="location_id" x-model="location" @change="scopeChanged=true">';
        foreach ($rooms as $room) echo '<option value="'.(int)$room['id'].'">'.self::e($room['room_label']).'</option>';
        echo '</select></label><label class="watch-field">Template checklist<select class="form-control" name="template_id" x-model="template" @change="scopeChanged=true">';
        foreach ($templates as $t) echo '<option value="'.(int)$t['id'].'">'.self::e($t['name']).'</option>';
        echo '</select></label></div><div x-show="step===2" x-cloak><p>Hubungkan setiap butir dengan barang atau pilih Aspek ruangan.</p><template x-for="(item,index) in items" :key="index"><label class="watch-field"><span x-text="item.group+\' — \'+item.object"></span><select class="form-control" :name="\'mapping[\'+index+\']\'" x-model="mapping[index]"><option value="">Aspek ruangan</option><template x-for="asset in assets" :key="asset.id"><option :value="String(asset.id)" x-text="asset.item_name+(asset.item_code ? \' [\'+asset.item_code+\']\' : \'\')"></option></template></select></label></template></div><div x-show="step===3" x-cloak class="watch-grid">';
        if ($incidental) self::textarea('Alasan pemeriksaan','reason','',true);
        else {
            self::select('Frekuensi','frequency',WatchRecurrence::FREQUENCIES,$old['frequency']??'monthly',true);
            self::input($old?'Mulai versi baru':'Tanggal mulai','start_date',$old?date('Y-m-d',strtotime('+1 day')):date('Y-m-d'),'date',true);
            self::input('Tanggal akhir (opsional)','end_date','','date');
            self::select('Penanggung jawab','assignee_id',self::actorOptions(),$old['assignee_id']??($_SESSION['uid']??''),true);
        }
        echo '</div><p class="text-danger" role="alert" x-show="scopeError" x-text="scopeError" x-cloak></p><div class="inv-footer"><button type="button" class="btn btn-default" x-show="step>1" @click="step--" :disabled="busy||loading">Sebelumnya</button><button type="button" class="btn btn-primary" x-show="step<3" @click="next()" :disabled="loading" x-text="loading?\'Memuat…\':\'Lanjutkan\'"></button><button type="submit" class="btn btn-primary" x-show="step===3" :disabled="busy" x-cloak>'.($incidental?'Buat pemeriksaan':'Simpan jadwal').'</button></div></form></div>';
    }
    private static function newInspection(Supervision $watch,array $rooms,array $templates,array $get,bool $incidental=true): void { echo '<div class="watch-card">';self::scopeForm($watch,$rooms,$templates,$get,$incidental);echo '</div>'; }
}
