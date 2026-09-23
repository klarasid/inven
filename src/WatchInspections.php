<?php
namespace SLiMS\Plugins\Inventory;

trait WatchInspections
{
    private static function listing(Supervision $watch,array $filter,bool $write,int $page): void {
        $page=max(1,$page);$rows=$watch->inspections($filter,$page,20);
        echo '<div class="watch-card"><h3>Pemeriksaan</h3>';if($write)self::link('Pemeriksaan insidental',['tab'=>'new']);
        echo '<div class="table-wrap"><table><thead><tr><th>Tanggal jadwal</th><th>Ruangan</th><th>Jenis / status</th><th>Pelaksanaan</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $s=Supervision::decode($row['snapshot']);
            echo '<tr><td>'.self::e($row['due_date']).'</td><td>'.self::e($s['library_name'].' / '.$s['room_name']).'</td><td>'.($row['kind']==='historical'?'Impor riwayat':($row['kind']==='routine'?'Terjadwal':'Insidental')).' / '.self::badge($row['status']).($row['status']!=='final'&&$row['due_date']<date('Y-m-d')?' <strong class="text-danger">Terlambat</strong>':'').'</td><td>'.self::e($row['performed_date']??'—').'<br>'.self::e($row['examiner_name']??'').'</td><td>';self::link('Buka',['tab'=>'inspection','record'=>$row['id']]);self::link('PDF',['tab'=>'pdf','record'=>$row['id']],true);echo '</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="5">Belum ada pemeriksaan dalam filter ini.</td></tr>';
        echo '</tbody></table></div>'; self::pagination(self::$tab==='reports'?'reports':'inspections',$filter,$page,count($rows));echo '</div>';
    }
    private static function pagination(string $tab,array $filter,int $page,int $count): void {
        if($page>1)self::link('Sebelumnya',array_merge($filter,['tab'=>$tab,'page'=>$page-1]));
        if($count===20)self::link('Berikutnya',array_merge($filter,['tab'=>$tab,'page'=>$page+1]));
    }
    private static function inspection(Supervision $watch,int $id,bool $write): void {
        $d=$watch->document($id);$i=$d['inspection'];$s=$d['snapshot'];$editable=$write&&$i['status']!=='final';
        echo '<div class="watch-card" data-inspection="'.$id.'"><div class="inv-row"><div><h3>Pemeriksaan #'.$id.' · '.self::e($s['room_name']).'</h3><p>'.self::e($s['library_name'].' / '.$s['template_name']).'</p></div><span data-document-status>'.self::badge($i['status']).'</span></div><p>Jadwal '.self::e($i['due_date']).' · '.($i['kind']==='historical'?'Impor riwayat':($i['kind']==='routine'?'Terjadwal':'Insidental')).'</p>';
        if ($i['reason']) echo '<p>Alasan: '.self::e($i['reason']).'</p>';
        echo '<div class="inv-actions">';
        if($i['parent_id'])self::link('Pemeriksaan asal',['tab'=>'inspection','record'=>$i['parent_id']]);
        self::link('Cetak detail PDF',['tab'=>'pdf','record'=>$id],true);
        if($write&&$i['status']==='final'&&$i['location_id'])self::link('Pemeriksaan ulang',['tab'=>'new','parent_id'=>$id,'location_id'=>$i['location_id'],'template_id'=>$s['template_id']]);
        echo '</div>';
        $results=[]; $evidence=[];
        foreach ($d['results'] as $r) {
            $rid=(int)$r['id']; $results[$rid]=['outcome'=>$r['outcome']??''];
            $photos=[]; foreach ($d['photos'] as $photo) if ((int)$photo['result_id']===$rid) $photos[]=['id'=>(int)$photo['id'],'url'=>self::url(['tab'=>'photo','inspection_id'=>$id,'photo_id'=>$photo['id']])];
            $evidence[$rid]=['photos'=>$photos,'files'=>[],'remove'=>[],'previews'=>[]];
        }
        if($editable) {
            $config=['id'=>$id,'version'=>(int)$i['version'],'results'=>$results,'evidence'=>$evidence];
            self::form('inspection',['id'=>$id,'version'=>$i['version']],'inventoryInspection('.InventoryUi::json($config).')');
            echo '<div class="inv-progress"><div class="inv-row"><strong>Kelengkapan hasil</strong><span x-text="completed+\' / \'+Object.keys(results).length+\' butir\'"></span></div><progress :value="completed" max="'.count($results).'" aria-label="Butir dengan hasil terisi"></progress></div><div class="watch-grid">';
            self::input('Tanggal pelaksanaan sebenarnya','performed_date',$i['performed_date']??date('Y-m-d'),'date');self::textarea('Catatan pemeriksaan','notes',$i['notes']);echo '</div>';
        } else echo '<p>Pelaksanaan: '.self::e($i['performed_date']??'—').' · Pemeriksa: '.self::e($i['examiner_name']??'—').'</p><p>'.nl2br(self::e($i['notes'])).'</p>';
        foreach (['Sarana','Prasarana','Lingkungan Fisik'] as $group) {
            $rows=array_filter($d['results'],fn($r)=>Supervision::decode($r['snapshot'])['group']===$group);
            if (!$rows) continue;
            echo '<h3 class="inv-group-title">'.self::e($group).'<small>'.count($rows).' butir</small></h3>';
            foreach ($rows as $r) {
                $item=Supervision::decode($r['snapshot']);$rid=(int)$r['id'];$p='results['.$rid.']';
                echo '<section class="inv-result" data-result="'.$rid.'"><h4>'.self::e($item['object']).'</h4><p>'.self::e($item['instruction']).'</p><p class="inv-object">'.self::e($item['item_id']?$item['item_name'].' ['.$item['item_code'].']':'Aspek ruangan').'</p>';
                if ($editable) {
                    echo '<div class="watch-grid"><label class="watch-field">Hasil pemeriksaan<select class="form-control" name="'.$p.'[outcome]" x-model="results['.$rid.'].outcome"><option value="">Belum diisi</option>';
                    foreach (Supervision::OUTCOMES as $key=>$label) echo '<option value="'.self::e($key).'">'.self::e($label).'</option>';
                    echo '</select></label><div>';self::textarea('Catatan / alasan',$p.'[notes]',$r['notes']);echo '<small>Alasan wajib untuk hasil selain Baik saat finalisasi.</small></div></div>';
                    echo '<div class="inv-assignment" x-show="results['.$rid.'].outcome===\'action\'" x-cloak><h4>Penugasan tindak lanjut</h4><div class="watch-grid">';
                    self::select('Penanggung jawab',$p.'[assignee_id]',self::actorOptions(),$r['assignee_id']??'');self::select('Prioritas',$p.'[priority]',[''=>'Pilih prioritas']+Supervision::PRIORITIES,$r['priority']??'');self::input('Tenggat',$p.'[deadline]',$r['deadline']??'','date');echo '</div></div>';
                    echo '<div class="inv-evidence"><h4>Foto bukti</h4><div class="watch-photos"><template x-for="photo in evidence['.$rid.'].photos" :key="photo.id"><div><a class="notAJAX" :href="photo.url" target="_blank" rel="noopener"><img :src="photo.url" alt="Foto bukti pemeriksaan" loading="lazy"></a><label><input type="checkbox" :value="String(photo.id)" x-model="evidence['.$rid.'].remove" :disabled="busy"> Hapus foto</label></div></template><template x-for="(preview,index) in evidence['.$rid.'].previews" :key="preview"><div><img :src="preview" alt="Pratinjau foto baru"><button type="button" class="btn btn-default" @click="removeFile('.$rid.',index)" :disabled="busy">Batal unggah</button></div></template></div><label class="watch-field">Tambah foto<input class="form-control" type="file" accept="image/jpeg,image/png,image/webp" multiple @change="pickPhotos($event,'.$rid.')" :disabled="busy"></label><small>Maksimal 5 foto, masing-masing 2 MB. JPEG, PNG, atau WebP.</small><button type="button" class="btn btn-default" @click="saveEvidence('.$rid.')" :disabled="busy || !hasPhotos('.$rid.')">Simpan foto butir</button><p class="inv-save-status" role="status" x-text="photoMessages['.$rid.'] || \'\'"></p></div>';
                } else {
                    echo '<p><strong>'.self::e(Supervision::OUTCOMES[$r['outcome']]??'Belum diisi').'</strong></p><p>'.nl2br(self::e($r['notes'])).'</p>';
                    self::photos(array_values(array_filter($d['photos'],fn($p)=>(int)$p['result_id']===$rid)));
                }
                echo '</section>';
            }
        }
        if ($editable) echo '<p class="inv-save-status" role="status" x-text="message"></p><div class="inv-footer"><button class="btn btn-default" type="submit" name="submit_mode" value="draft" :disabled="busy">Simpan draf</button><button class="btn btn-primary" type="submit" name="submit_mode" value="final" :disabled="busy">Finalisasi pemeriksaan</button><span x-show="busy" x-cloak>Menyimpan…</span></div></form>';
        foreach ($d['findings'] as $f) { echo '<p>Temuan #'.(int)$f['id'].' — '.self::badge($f['status']).' ';self::link('Tindak lanjut',['tab'=>'finding','record'=>$f['id']]);echo '</p>'; }
        if($write&&$i['status']==='final') { echo '<details><summary>Tambahkan catatan koreksi</summary>';self::form('correction',['id'=>$id]);self::textarea('Catatan koreksi (hasil asli tetap disimpan)','notes','',true);self::end('Tambahkan koreksi');echo '</details>'; }
        self::events($d['events']); echo '</div>';
    }
}
