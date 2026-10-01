<?php
namespace SLiMS\Plugins\Inventory;

final class InventoryUi
{
    public const MENUS = [
        'inventory' => ['index.php', 'Klaras Inven', 'Kelola ruangan, barang, dan kartu inventaris.'],
        'setup' => ['checklist-and-schedule.php', 'Checklist & Jadwal', 'Siapkan checklist dan atur pemeriksaan rutin ruangan.'],
        'inspections' => ['inspection.php', 'Pemeriksaan', 'Catat hasil pemeriksaan dan lengkapi bukti setiap butir.'],
        'findings' => ['findings-and-follow-up.php', 'Temuan & Tindak Lanjut', 'Pantau pekerjaan hingga hasilnya selesai diverifikasi.'],
        'reports' => ['report.php', 'Laporan', 'Tinjau capaian pengawasan dan cetak laporan periode.'],
    ];

    public static function section(string $tab): string
    {
        if (in_array($tab, ['setup', 'template', 'schedule'], true)) return 'setup';
        if (in_array($tab, ['inspection', 'inspections', 'new', 'photo'], true)) return 'inspections';
        if (in_array($tab, ['finding', 'findings'], true)) return 'findings';
        return $tab === 'inventory' ? 'inventory' : 'reports';
    }

    public static function url(array $params = []): string
    {
        unset($params['mod'], $params['id']);
        $tab = (string) ($params['tab'] ?? 'reports');
        if ($tab === 'dashboard') $params['tab'] = 'reports';
        $path = dirname(__DIR__) . '/' . self::MENUS[self::section($tab)][0];
        return AWB . 'plugin_container.php?' . http_build_query(array_merge([
            'mod' => 'stock_take', 'id' => md5(realpath($path)),
        ], $params));
    }

    public static function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function json($value): string
    {
        return self::e(json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
    }

    public static function assets(): void
    {
        $base = SWB . 'plugins/inventaris-barang/assets/';
        echo '<link rel="stylesheet" href="' . self::e($base . 'inventory.css?v=2') . '">';
        // AJAX fragments execute this small loader; the runtime and providers load only once.
        echo '<script>(function(){if(window.inventoryUiLoading)return;window.inventoryUiLoading=true;var s=document.createElement("script");s.src=' . json_encode($base . 'inventory.js?v=2') . ';s.dataset.alpine=' . json_encode($base . 'alpine-3.14.9.min.js') . ';s.onerror=function(){window.inventoryUiLoading=false;};document.head.appendChild(s);})();</script>';
    }

    public static function header(string $section): void
    {
        $menu = self::MENUS[$section];
        echo '<header class="inv-header"><div class="inv-eyebrow">INVENTARIS PERPUSTAKAAN</div><h2>' . self::e($menu[1]) . '</h2><p>' . self::e($menu[2]) . '</p></header>';
    }
}
