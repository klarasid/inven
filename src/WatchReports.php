<?php
namespace SLiMS\Plugins\Inventory;

trait WatchReports
{
    public static function metrics(array $summary): void {
        $c=$summary['counts'];$f=$summary['findings'];
        echo '<div class="watch-card"><h3>Jadwal dan tindak lanjut</h3><div class="watch-grid">';
        foreach (['Pemeriksaan difinalisasi'=>$c['finalized'],'Pemeriksaan terlambat'=>(int)$c['late']+$summary['unformed_late'],'Temuan belum selesai'=>$f['open'],'Selesai terverifikasi'=>$f['closed']] as $label=>$value) echo '<div class="watch-metric">'.self::e($label).'<strong>'.self::e($value).'</strong></div>';
        echo '</div><details><summary>Rincian jadwal dan temuan</summary><div class="watch-grid">';
        foreach (['Rencana rutin dalam periode'=>$summary['planned'],'Rutin difinalisasi'=>$c['routine_final'],'Insidental tercatat'=>$c['incidental'],'Riwayat impor'=>($c['historical']??0),'Jatuh tempo belum dibentuk'=>$summary['unformed'],'Temuan lewat tenggat'=>$f['late']] as $label=>$value) echo '<div class="watch-metric">'.self::e($label).'<strong>'.self::e($value).'</strong></div>';
        echo '</div></details>';
        echo '<p class="text-muted">Temuan mengikuti periode pemeriksaan asal; status tindak lanjut adalah status saat ini.</p></div>';
        echo '<div class="watch-card"><h3>Cakupan pemeriksaan rutin</h3><p>Ruangan diperiksa: <strong>'.$summary['room_examined'].' / '.$summary['room_total'].'</strong>. Butir diperiksa: <strong>'.$summary['item_examined'].' / '.$summary['item_applicable'].'</strong>. Tidak berlaku: <strong>'.$summary['item_na'].'</strong>.</p><p class="text-muted">Butir dihitung dari pemeriksaan terbentuk dan jadwal yang sudah jatuh tempo. Hanya hasil final dihitung diperiksa; Tidak diperiksa tetap masuk penyebut. Pemeriksaan insidental dilaporkan terpisah.</p><details><summary>Ruangan tanpa jadwal dalam periode</summary>';
        if (!$summary['missing_rooms']) echo '<p>Tidak ada.</p>';
        foreach ($summary['missing_rooms'] as $room) echo '<p>'.self::e($room['room_name']).' — '.self::e($room['location_name']??'Tidak ditentukan').'</p>';
        echo '</details></div>';
    }
}
