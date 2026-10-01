<?php

defined('INDEX_AUTH') || die('Direct access not allowed!');

// PDF must run through the admin container: PHP is disabled in plugins/.
if (isset($_GET['action']) && $_GET['action'] === 'print_pdf') {
    require __DIR__ . '/print.php';
    exit;
}
if (isset($_GET['action']) && $_GET['action'] === 'print_labels') {
    require __DIR__ . '/labels.php';
    exit;
}

require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-stocktake');
require SB . 'admin/default/session.inc.php';
require_once SB . 'admin/default/session_check.inc.php';

$canRead = utility::havePrivilege('stock_take', 'r');
$canWrite = utility::havePrivilege('stock_take', 'w');
if (!$canRead) {
    die('<div class="errorBox">' . __('You are not authorized to view this section') . '</div>');
}

$db = \SLiMS\DB::getInstance();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
require_once __DIR__ . '/src/ItemPhotos.php';
require_once __DIR__ . '/src/ItemCodes.php';
require_once __DIR__ . '/src/PhotoStorage.php';
$photoStorage = new \SLiMS\Plugins\Inventory\PhotoStorage();
$createdPhotos = [];
$removedPhotos = [];

if (($_GET['action'] ?? '') === 'item_photo') {
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    $photoId = filter_var($_GET['photo_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$photoId) {
        http_response_code(400);
        exit('Foto tidak valid.');
    }
    $statement = $db->prepare('SELECT filename FROM inventory_item_photos WHERE id = ?');
    $statement->execute([$photoId]);
    $filename = $statement->fetchColumn();
    $photo = $filename === false || $filename === null ? null : $photoStorage->read($filename);
    if ($photo === null) {
        http_response_code(404);
        exit('Foto tidak ditemukan.');
    }
    header('Content-Type: image/jpeg');
    header('Content-Disposition: inline; filename="foto-barang.jpg"');
    header('Content-Length: ' . strlen($photo));
    echo $photo;
    exit;
}


function inventory_e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function inventory_url(array $params = []): string
{
    $base = [];
    foreach (['mod', 'id'] as $key) {
        if (isset($_GET[$key])) {
            $base[$key] = (string) $_GET[$key];
        }
    }
    return $_SERVER['PHP_SELF'] . '?' . http_build_query(array_merge($base, $params));
}

function inventory_grid_text($db, array $row, int $column): string
{
    return inventory_e($row[$column]);
}

function inventory_item_name($db, array $row, int $column): string
{
    return '<a href="' . inventory_e(inventory_url(['action' => 'view_photos', 'record_id' => (int) $row[0]])) . '">' . inventory_e($row[$column]) . '</a>';
}

function inventory_photos(int $itemId, bool $editable = false): void
{
    $query = \SLiMS\DB::getInstance()->prepare('SELECT id, filename FROM inventory_item_photos WHERE item_id = ? ORDER BY id');
    $query->execute([$itemId]);
    $photos = $query->fetchAll(PDO::FETCH_ASSOC);
    if (!$photos) {
        echo '<p class="text-muted">Belum ada foto barang.</p>';
        return;
    }
    echo '<div class="inventory-photo-list" style="display:flex;flex-wrap:wrap;gap:1rem">';
    foreach ($photos as $photo) {
        $id = (int) $photo['id'];
        $url = inventory_e(inventory_url(['action' => 'item_photo', 'photo_id' => (int) $id]));
        echo '<div class="inventory-photo-card">';
        if ($photo['filename'] === null) {
            echo '<p class="text-warning">Foto lama tidak dapat dibaca.<br>Unggah ulang foto pengganti.</p>';
        } else {
            echo '<a class="notAJAX" target="_blank" rel="noopener" href="' . $url . '"><img src="' . $url . '" alt="Foto barang" loading="lazy" style="width:140px;height:110px;object-fit:contain"></a>';
        }
        if ($editable) {
            echo '<button type="button" class="btn btn-sm btn-danger d-block mt-2" onclick="inventoryDeletePhoto(this)" data-url="' . inventory_e(inventory_url(['photo_delete' => '1'])) . '" data-item-id="' . $itemId . '" data-photo-id="' . (int) $id . '" data-csrf="' . inventory_e($_SESSION['inventory_csrf']) . '">Hapus Foto</button><small class="inventory-photo-error text-danger" role="alert"></small>';
        }
        echo '</div>';
    }
    echo '</div>';
}

function inventory_grid_price($db, array $row, int $column): string
{
    return (float) $row[$column] > 0 ? 'Rp ' . inventory_e(number_format((float) $row[$column], 0, ',', '.')) : '';
}

function inventory_location_actions($db, array $row): string
{
    $location = ['id' => (int) $row[7]];
    $canWrite = utility::havePrivilege('stock_take', 'w');
    $csrf = (string) $_SESSION['inventory_csrf'];
    $printBase = AWB . 'plugin_container.php?' . http_build_query([
        'mod' => 'stock_take', 'id' => md5(realpath(__FILE__)), 'action' => 'print_pdf',
    ]);
    ob_start();
    ?><div class="inventory-actions">
            <a class="btn btn-sm btn-default" href="<?= inventory_e(inventory_url(['action' => 'view_location', 'location_id' => $location['id']])) ?>">Lihat Barang</a>
            <a class="btn btn-sm btn-success notAJAX" target="_blank" href="<?= inventory_e($printBase . '&' . http_build_query(['location_id' => $location['id']])) ?>">Cetak PDF</a>
            <?php if ($canWrite): ?><a class="btn btn-sm btn-info" href="<?= inventory_e(inventory_url(['action' => 'add_item', 'location_id' => $location['id']])) ?>">Tambah Barang</a><?php endif; ?>
    </div><?php
    return (string) ob_get_clean();
}

function inventory_datagrid(string $entity): simbio_datagrid
{
    require_once SIMBIO . 'simbio_GUI/table/simbio_table.inc.php';
    require_once SIMBIO . 'simbio_GUI/paging/simbio_paging.inc.php';
    require_once SIMBIO . 'simbio_DB/datagrid/simbio_dbgrid.inc.php';
    require_once __DIR__ . '/src/InventoryDatagrid.php';
    $grid = new \SLiMS\Plugins\Inventory\InventoryDatagrid();
    $grid->inventoryCsrf = (string) $_SESSION['inventory_csrf'];
    $grid->inventoryAction = 'delete_' . $entity;
    $grid->table_name = 'inventory_' . $entity . '_grid';
    $grid->chbox_form_URL = inventory_e(inventory_url($entity === 'item' ? [
        'action' => 'view_location', 'location_id' => (int) ($_GET['location_id'] ?? 0),
        'keywords' => (string) ($_GET['keywords'] ?? ''),
    ] : ['slims_location_id' => (string) ($_GET['slims_location_id'] ?? '')]));
    if ($entity === 'location') {
        $grid->chbox_confirm_msg = 'Hapus lokasi yang dipilih beserta seluruh barang di dalamnya?';
    }
    if (!utility::havePrivilege('stock_take', 'w')) {
        $grid->invisible_fields = [0];
    }
    $grid->table_attr = 'id="dataList" class="s-table table"';
    $grid->table_header_attr = 'class="dataListHeader" style="font-weight: bold;"';
    $grid->disableSort('Aksi');
    return $grid;
}

function inventory_post(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function inventory_log(string $recordId, string $message, string $action): void
{
    try {
        $suffix = $recordId === '' ? '' : ' Rekaman #' . $recordId . '.';
        writeLog('staff', (string) ($_SESSION['uid'] ?? '0'), 'Inventaris Barang', $message . $suffix, 'stock_take', $action);
    } catch (Throwable $exception) {
        error_log('Inventory audit log error: ' . $exception->getMessage());
    }
}

if (empty($_SESSION['inventory_csrf'])) {
    $_SESSION['inventory_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string) $_SESSION['inventory_csrf'];
require_once __DIR__ . '/src/Workspace.php';
require_once __DIR__ . '/src/WorkspaceRequests.php';
// A scanned item label (?qr=<item id>) opens that item in its room, as a standalone mobile page.
$scannedItem = filter_input(INPUT_GET, 'qr', FILTER_VALIDATE_INT);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $scannedItem) {
    $statement = $db->prepare('SELECT location_id FROM inventory_items WHERE id = ?');
    $statement->execute([$scannedItem]);
    $scannedRoom = $statement->fetchColumn();
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Inventaris Barang</title><style>body{margin:0;background:#fff;font-family:system-ui,sans-serif}</style></head><body>';
    if ($scannedRoom === false) {
        echo '<p style="padding:24px">Barang dengan label ini tidak ditemukan. Barang mungkin sudah dihapus dari inventaris.</p></body></html>';
        return;
    }
    $_GET = ['mod' => $_GET['mod'] ?? 'stock_take', 'id' => $_GET['id'] ?? '', 'action' => 'view_photos', 'record_id' => (string) $scannedItem, 'location_id' => (string) $scannedRoom];
    \SLiMS\Plugins\Inventory\Workspace::shell('inventory', $canWrite);
    echo '</body></html>';
    return;
}
if ($_SERVER['REQUEST_METHOD']==='GET' && ($_GET['legacy']??'')!=='1') {
    \SLiMS\Plugins\Inventory\Workspace::shell('inventory',$canWrite); return;
}
$isWorkspaceSave = ($_GET['workspace']??'')==='save';
$isCodeRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'reserve_item_code';
$reservedCode = null;
$message = '';
$messageType = 'success';
$locations = [];
$masterLocations = [];
$isPhotoSave = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['photo_save'] ?? '') === '1';
$isPhotoDelete = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['photo_delete'] ?? '') === '1';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['detail']) || isset($_POST['form_action']) || isset($_POST['itemAction']))) {
        if (!$canWrite) {
            inventory_log('', 'Upaya perubahan inventaris ditolak: hak tulis tidak tersedia.', 'Denied');
            throw new RuntimeException('Anda tidak memiliki hak untuk mengubah data inventaris.');
        }
        if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) {
            inventory_log('', 'Upaya perubahan inventaris ditolak: token CSRF tidak valid.', 'Denied');
            throw new RuntimeException('Token formulir tidak valid. Muat ulang halaman lalu coba lagi.');
        }

        if ($isWorkspaceSave && ($cached=\SLiMS\Plugins\Inventory\WorkspaceRequests::cached('inventory'))) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($cached); return; }
        $postAction = inventory_post('form_action');
        // Merge omitted fields only after authorization. A hidden section must never erase old data.
        if (in_array($postAction,['save_item','save_location'],true) && (int)($_POST['record_id']??0)>0) {
            $table=$postAction==='save_item'?'inventory_items':'inventory_locations';
            $existing=$db->prepare('SELECT * FROM '.$table.' WHERE id=?');
            $existing->execute([(int)$_POST['record_id']]);
            $old=$existing->fetch(PDO::FETCH_ASSOC);
            if (!$old) throw new RuntimeException('Data tidak ditemukan.');
            if ($isWorkspaceSave && isset($_POST['expected_updated_at']) && $_POST['expected_updated_at']!==$old['updated_at']) throw new RuntimeException('Data berubah pada sesi lain. Muat ulang sebelum melanjutkan.');
            $_POST=array_merge($old,$_POST);
        }

        $now = date('Y-m-d H:i:s');
        $uid = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;

        if ($postAction === 'suggest_location_code') {
            require_once __DIR__ . '/src/RoomCodes.php';
            $reservedCode = \SLiMS\Plugins\Inventory\RoomCodes::next($db, inventory_post('slims_location_id'));
            $message = 'Kode ruangan berhasil dibuat.';
        } elseif ($postAction === 'reserve_item_code') {
            $owner = \SLiMS\Plugins\Inventory\ItemCodes::owner(inventory_post('code_form_token'), session_id());
            $db->beginTransaction();
            $reservedCode = \SLiMS\Plugins\Inventory\ItemCodes::reserve($db, (int) ($_POST['location_id'] ?? 0), $owner);
            $db->commit();
            $message = 'Kode berhasil dibuat.';
        } elseif ($postAction === 'delete_photo') {
            $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $photoId = filter_var($_POST['photo_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$itemId || !$photoId) {
                throw new RuntimeException('Barang atau foto tidak valid.');
            }
            $db->beginTransaction();
            $filename = \SLiMS\Plugins\Inventory\ItemPhotos::deleteOne($db, $itemId, $photoId);
            $db->commit();
            $photoStorage->cleanup([$filename]);
            inventory_log((string) $itemId, 'Foto #' . $photoId . ' dihapus.', 'Delete');
            $message = 'Foto berhasil dihapus.';
        } elseif ($postAction === 'save_location') {
            $id = (int) ($_POST['record_id'] ?? 0);
            $roomName = inventory_post('room_name');
            if ($roomName === '') {
                throw new RuntimeException('Nama ruangan wajib diisi.');
            }
            $slimsLocationId = inventory_post('slims_location_id');
            if (strlen($slimsLocationId) > 3) {
                throw new RuntimeException('Kode lokasi Perpustakaan tidak valid.');
            }
            if ($slimsLocationId !== '') {
                $masterLocationStatement = $db->prepare('SELECT 1 FROM mst_location WHERE location_id = ? LIMIT 1');
                $masterLocationStatement->execute([$slimsLocationId]);
                if (!$masterLocationStatement->fetchColumn()) {
                    throw new RuntimeException('Lokasi Perpustakaan yang dipilih tidak ditemukan.');
                }
            }

            $values = [
                'slims_location_id' => $slimsLocationId === '' ? null : $slimsLocationId,
                'location_code' => inventory_post('location_code') ?: null,
                'room_name' => $roomName,
                'province' => inventory_post('province'),
                'regency_city' => inventory_post('regency_city'),
                'unit_name' => inventory_post('unit_name', 'PERPUSTAKAAN'),
                'work_unit' => inventory_post('work_unit'),
                'signature_city' => inventory_post('signature_city'),
                'knowing_title' => inventory_post('knowing_title'),
                'knowing_name' => inventory_post('knowing_name'),
                'knowing_identity' => inventory_post('knowing_identity'),
                'manager_title' => inventory_post('manager_title', 'Pengurus Barang Inventaris'),
                'manager_name' => inventory_post('manager_name'),
                'manager_identity' => inventory_post('manager_identity'),
                'updated_at' => $now,
            ];

            if ($id > 0) {
                $values['id'] = $id;
                $statement = $db->prepare(
                    'UPDATE inventory_locations SET slims_location_id=:slims_location_id, location_code=:location_code, room_name=:room_name, province=:province,
                     regency_city=:regency_city, unit_name=:unit_name, work_unit=:work_unit, signature_city=:signature_city,
                     knowing_title=:knowing_title, knowing_name=:knowing_name, knowing_identity=:knowing_identity,
                     manager_title=:manager_title, manager_name=:manager_name, manager_identity=:manager_identity,
                     updated_at=:updated_at WHERE id=:id'
                );
                $statement->execute($values);
                inventory_log((string) $id, 'Lokasi inventaris diperbarui.', 'Update');
                $message = 'Lokasi berhasil diperbarui.';
            } else {
                $values['created_by'] = $uid;
                $values['created_at'] = $now;
                $statement = $db->prepare(
                    'INSERT INTO inventory_locations
                     (slims_location_id, location_code, room_name, province, regency_city, unit_name, work_unit, signature_city,
                      knowing_title, knowing_name, knowing_identity, manager_title, manager_name, manager_identity,
                      created_by, created_at, updated_at)
                     VALUES (:slims_location_id, :location_code, :room_name, :province, :regency_city, :unit_name, :work_unit, :signature_city,
                      :knowing_title, :knowing_name, :knowing_identity, :manager_title, :manager_name, :manager_identity,
                      :created_by, :created_at, :updated_at)'
                );
                $statement->execute($values);
                $id = (int) $db->lastInsertId();
                inventory_log((string) $id, 'Lokasi inventaris ditambahkan.', 'Create');
                $message = 'Lokasi berhasil ditambahkan.';
            }
        } elseif ($postAction === 'save_item') {
            require_once __DIR__ . '/src/Inventory.php';
            $id = (int) ($_POST['record_id'] ?? 0);
            $locationId = (int) ($_POST['location_id'] ?? 0);
            $isNewItem = $id < 1;
            // Validate the fields before decoding any photo, as before the save moved into Inventory.
            \SLiMS\Plugins\Inventory\Inventory::values($_POST);
            $photos = \SLiMS\Plugins\Inventory\ItemPhotos::uploads($_FILES['item_photos'] ?? []);
            $codeToken = inventory_post('code_form_token');
            $codeOwner = $codeToken === '' ? '' : \SLiMS\Plugins\Inventory\ItemCodes::owner($codeToken, session_id());
            $id = \SLiMS\Plugins\Inventory\Inventory::saveItem($db, $photoStorage, $_POST, $id, $uid, $codeOwner, $photos);
            $message = $isNewItem ? 'Barang inventaris berhasil ditambahkan.' : 'Barang inventaris berhasil diperbarui.';
            inventory_log((string) $id, $isNewItem ? 'Barang inventaris ditambahkan pada lokasi #' . $locationId . '.' : 'Barang inventaris diperbarui.', $isNewItem ? 'Create' : 'Update');
            if ($photos) {
                inventory_log((string) $id, 'Foto barang diperbarui: ' . count($photos) . ' ditambahkan.', 'Update');
            }
            $_GET['action'] = 'view_location';
            $_GET['location_id'] = $locationId;
            unset($_GET['page']);
            $_SERVER['QUERY_STRING'] = http_build_query($_GET);
        } elseif (in_array($postAction, ['delete_item', 'delete_location'], true)) {
            $ids = $_POST['itemID'] ?? [$_POST['record_id'] ?? 0];
            if (!is_array($ids) || !$ids) {
                throw new RuntimeException('Pilih data yang akan dihapus.');
            }
            foreach ($ids as $id) {
                if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    throw new RuntimeException('Data yang dipilih tidak valid.');
                }
            }
            $isItem = $postAction === 'delete_item';
            $table = $isItem ? 'inventory_items' : 'inventory_locations';
            $sql = 'DELETE FROM ' . $table . ' WHERE id = ?';
            $locationId = (int) ($_GET['location_id'] ?? 0);
            if ($isItem) {
                if ($locationId < 1) {
                    throw new RuntimeException('Lokasi inventaris tidak valid.');
                }
                $sql .= ' AND location_id = ?';
            }
            $watchSchedulesAvailable = !$isItem && (bool) $db->query("SHOW TABLES LIKE 'inventory_watch_schedules'")->fetchColumn();
            $db->beginTransaction();
            $statement = $db->prepare($sql);
            $lock = $db->prepare('SELECT id FROM ' . $table . ' WHERE id = ?' . ($isItem ? ' AND location_id = ?' : '') . ' FOR UPDATE');
            $deletedIds = [];
            foreach (array_unique(array_map('intval', $ids)) as $id) {
                $args = $isItem ? [$id, $locationId] : [$id];
                $lock->execute($args);
                if (!$lock->fetchColumn()) continue;
                if (!$isItem) {
                    $children = $db->prepare('SELECT id FROM inventory_items WHERE location_id = ? FOR UPDATE');
                    $children->execute([$id]);
                    $children->fetchAll(PDO::FETCH_COLUMN);
                }
                $files = $db->prepare('SELECT p.filename FROM inventory_item_photos p JOIN inventory_items i ON i.id = p.item_id WHERE ' . ($isItem ? 'i.id' : 'i.location_id') . ' = ?');
                $files->execute([$id]);
                $removedPhotos = array_merge($removedPhotos, $files->fetchAll(PDO::FETCH_COLUMN));
                if (!$isItem && $watchSchedulesAvailable) {
                    $db->prepare('UPDATE inventory_watch_schedules SET active=0,version=version+1 WHERE location_id=?')->execute([$id]);
                }
                $statement->execute($args);
                $deletedIds[] = $id;
            }
            $db->commit();
            $photoStorage->cleanup($removedPhotos);
            $removedPhotos = [];
            foreach ($deletedIds as $id) {
                inventory_log((string) $id, $isItem ? 'Barang inventaris dihapus.' : 'Lokasi inventaris beserta barang terkait dihapus.', 'Delete');
            }
            $deleted = count($deletedIds);
            $message = $deleted . ($isItem ? ' barang berhasil dihapus.' : ' lokasi beserta barang di dalamnya berhasil dihapus.');
        } else {
            inventory_log('', 'Upaya perubahan inventaris ditolak: aksi tidak dikenal.', 'Denied');
            throw new RuntimeException('Aksi formulir tidak valid.');
        }
    }

    if (!$isPhotoSave && !$isPhotoDelete && !$isCodeRequest && !$isWorkspaceSave) {
    $masterLocations = $db->query(
        'SELECT location_id, location_name FROM mst_location ORDER BY location_name, location_id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $locations = $db->query(
        'SELECT l.*,
                (SELECT ml.location_name FROM mst_location ml WHERE ml.location_id = l.slims_location_id LIMIT 1) AS slims_location_name,
                COUNT(i.id) AS item_count
         FROM inventory_locations l LEFT JOIN inventory_items i ON i.location_id=l.id
         GROUP BY l.id ORDER BY l.room_name, l.location_code'
    )->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $exception) {
    if ($db->inTransaction()) { $db->rollBack(); }
    $photoStorage->cleanup($createdPhotos);
    error_log('Inventory database error: ' . $exception->getMessage());
    $locations = [];
    $masterLocations = [];
    $messageType = 'danger';
    $schemaError = in_array((int) ($exception->errorInfo[1] ?? 0), [1054, 1146, 1364], true) && str_contains($exception->getMessage(), 'filename');
    $codeSchemaError = str_contains($exception->getMessage(), 'inventory_item_code_');
    $message = $codeSchemaError ? 'Struktur kode barang belum tersedia. Jalankan migrasi plugin hingga versi 6 melalui System → Plugins.' : ($schemaError ? 'Struktur foto belum diperbarui. Jalankan migrasi plugin hingga versi 4 melalui System → Plugins.' : (str_contains(strtolower($exception->getMessage()), 'doesn\'t exist')
        ? 'Tabel inventaris belum tersedia. Aktifkan plugin Inventaris Barang dari menu System → Plugins.'
        : ((int) ($exception->errorInfo[1] ?? 0) === 1062 && str_contains($exception->getMessage(), 'inventory_locations_code_unique')
            ? 'Kode lokasi masih dibatasi unik oleh struktur database lama. Jalankan migrasi plugin hingga versi 5 melalui System → Plugins agar beberapa ruangan dapat memakai kode lokasi yang sama.'
            : 'Operasi database gagal. Periksa data yang dimasukkan dan log PHP.')));
} catch (RuntimeException $exception) {
    if ($db->inTransaction()) { $db->rollBack(); }
    $photoStorage->cleanup($createdPhotos);
    $locations = [];
    $masterLocations = [];
    $messageType = 'danger';
    $message = $exception->getMessage();
} catch (Throwable $exception) {
    if ($db->inTransaction()) { $db->rollBack(); }
    $photoStorage->cleanup($createdPhotos);
    error_log('Inventory application error: ' . $exception->getMessage());
    $locations = [];
    $masterLocations = [];
    $messageType = 'danger';
    $message = 'Terjadi kesalahan internal. Silakan coba lagi atau hubungi administrator.';
}

if ($isWorkspaceSave) {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store');
    if ($messageType==='danger') http_response_code(str_contains($message,'sesi lain')?409:422);
    echo json_encode(\SLiMS\Plugins\Inventory\WorkspaceRequests::remember('inventory',['ok'=>$messageType!=='danger','message'=>$message,'record'=>$id??null,'location_id'=>$_GET['location_id']??($_POST['location_id']??null),'code'=>$reservedCode,'errors'=>$messageType==='danger'?\SLiMS\Plugins\Inventory\WorkspaceRequests::errors($message):new stdClass()])); return;
}

if ($isCodeRequest) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode(['ok' => $messageType !== 'danger', 'message' => $message, 'code' => $messageType === 'danger' ? null : $reservedCode], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

if ($isPhotoSave || $isPhotoDelete) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'ok' => $messageType !== 'danger',
        'message' => $message,
        'url' => inventory_url(['action' => 'view_location', 'location_id' => (int) ($_GET['location_id'] ?? 0), 'saved' => '1']),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

// Native datagrid deletion submits into SLiMS' hidden iframe.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['itemAction'])) {
    utility::jsToastr('Inventaris Barang', $message, $messageType === 'danger' ? 'error' : 'success');
    $returnParams = ($_GET['action'] ?? '') === 'view_location'
        ? ['action' => 'view_location', 'location_id' => (int) ($_GET['location_id'] ?? 0), 'keywords' => (string) ($_GET['keywords'] ?? '')]
        : ['slims_location_id' => (string) ($_GET['slims_location_id'] ?? '')];
    echo '<script>parent.jQuery("#mainContent").simbioAJAX(' . json_encode(inventory_url($returnParams), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');</script>';
    exit;
}

$action = (string) ($_GET['action'] ?? 'list');
if (isset($_POST['detail']) || isset($_GET['detail'])) {
    $action = $action === 'view_location' ? 'edit_item' : 'edit_location';
    $_GET['record_id'] = (int) ($_POST['itemID'] ?? $_GET['itemID'] ?? 0);
}
$locationForm = [];
$itemForm = [];
if ($action === 'edit_location' && isset($_GET['record_id'])) {
    $statement = $db->prepare('SELECT * FROM inventory_locations WHERE id=?');
    $statement->execute([(int) $_GET['record_id']]);
    $locationForm = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
}
if (in_array($action, ['edit_item', 'view_photos'], true) && isset($_GET['record_id'])) {
    $statement = $db->prepare('SELECT * FROM inventory_items WHERE id=?');
    $statement->execute([(int) $_GET['record_id']]);
    $itemForm = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
}
if ($action === 'add_item' && isset($_GET['location_id'])) {
    $itemForm['location_id'] = (int) $_GET['location_id'];
}

$printBase = AWB . 'plugin_container.php?' . http_build_query([
    'mod' => 'stock_take',
    'id' => md5(realpath(__FILE__)),
    'action' => 'print_pdf',
]);
?>

<script>
window.inventoryMakeCode = async function(button) {
    const form = button.form;
    if (form.dataset.saving === '1' || form.dataset.deleting === '1' || form.dataset.generatingCode === '1') return;
    const field = form.elements.item_code;
    const location = form.elements.location_id;
    const errorBox = form.querySelector('.inventory-code-error');
    errorBox.textContent = '';
    if (!location.value) { errorBox.textContent = 'Pilih lokasi/ruangan terlebih dahulu.'; return; }
    if (field.value.trim() && !window.confirm('Ganti kode yang sudah terisi dengan nomor baru? Nomor yang sudah dipesan tidak digunakan ulang.')) return;
    const data = new FormData();
    data.set('form_action', 'reserve_item_code');
    data.set('csrf_token', form.elements.csrf_token.value);
    data.set('code_form_token', form.elements.code_form_token.value);
    data.set('location_id', location.value);
    const submit = form.querySelector('button[type="submit"]');
    form.dataset.generatingCode = '1';
    button.disabled = true;
    submit.disabled = true;
    field.readOnly = true;
    location.disabled = true;
    try {
        const response = await fetch(button.dataset.url, {method: 'POST', body: data, credentials: 'same-origin'});
        const result = await response.json();
        if (!result.ok || typeof result.code !== 'string') throw new Error(result.message || 'Kode gagal dibuat.');
        field.value = result.code;
    } catch (error) {
        errorBox.textContent = error instanceof SyntaxError ? 'Kode belum diterima. Periksa sesi login dan coba lagi.' : (error.message || 'Kode gagal dibuat.');
    } finally {
        delete form.dataset.generatingCode;
        button.disabled = false;
        submit.disabled = false;
        field.readOnly = false;
        location.disabled = false;
    }
};
window.inventoryDeletePhoto = function(button) {
    const form = button.form;
    if (form && (form.dataset.saving === '1' || form.dataset.deleting === '1' || form.dataset.generatingCode === '1')) return;
    if (!window.confirm('Hapus foto ini?')) return;
    const list = button.closest('.inventory-photo-list');
    const card = button.closest('.inventory-photo-card');
    const errorBox = card.querySelector('.inventory-photo-error');
    errorBox.textContent = '';
    list.querySelectorAll('button').forEach(control => { control.disabled = true; });
    if (form) form.dataset.deleting = '1';
    const data = new FormData();
    data.set('form_action', 'delete_photo');
    data.set('csrf_token', button.dataset.csrf);
    data.set('item_id', button.dataset.itemId);
    data.set('photo_id', button.dataset.photoId);
    fetch(button.dataset.url, {method: 'POST', body: data, credentials: 'same-origin'})
        .then(response => response.json())
        .then(result => {
            if (!result.ok) throw new Error(result.message);
            card.remove();
            if (!list.querySelector('.inventory-photo-card')) list.textContent = 'Belum ada foto barang.';
        })
        .catch(error => {
            errorBox.textContent = error instanceof SyntaxError ? 'Foto belum dihapus. Periksa sesi login dan coba lagi.' : (error.message || 'Foto gagal dihapus.');
        })
        .finally(() => {
            list.querySelectorAll('button').forEach(control => { control.disabled = false; });
            if (form) delete form.dataset.deleting;
        });
};
window.inventoryPreviewPhotos = function(input) {
    const preview = input.form.querySelector('.inventory-photo-preview');
    preview.replaceChildren();
    const files = Array.from(input.files);
    if (files.length > 5) {
        input.setCustomValidity('Pilih maksimal 5 foto.');
        input.reportValidity();
        return;
    }
    input.setCustomValidity('');
    files.forEach(file => {
        const figure = document.createElement('figure');
        const caption = document.createElement('figcaption');
        caption.textContent = file.name;
        figure.style.maxWidth = '160px';
        figure.style.overflowWrap = 'anywhere';
        if (file.size > 2097152 || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            caption.textContent += ' — format tidak didukung atau ukuran melebihi 2 MB';
            input.setCustomValidity('Periksa format dan ukuran foto yang dipilih.');
        } else {
            const img = document.createElement('img');
            img.alt = 'Pratinjau ' + file.name;
            img.style.cssText = 'width:140px;height:110px;object-fit:contain';
            const url = URL.createObjectURL(file);
            img.onload = () => URL.revokeObjectURL(url);
            img.onerror = () => { URL.revokeObjectURL(url); img.remove(); caption.textContent += ' — gambar tidak dapat ditampilkan'; };
            img.src = url;
            figure.append(img);
        }
        figure.append(caption);
        preview.append(figure);
    });
};
window.inventorySaveWithPhotos = function(event, form) {
    event.preventDefault();
    event.stopImmediatePropagation();
    if (form.dataset.saving === '1' || form.dataset.deleting === '1' || form.dataset.generatingCode === '1') return false;
    const errorBox = form.querySelector('.inventory-save-error');
    errorBox.hidden = true;
    const data = new FormData(form);
    const button = form.querySelector('button[type="submit"]');
    form.dataset.saving = '1';
    button.disabled = true;
    fetch(form.action, {method: 'POST', body: data, credentials: 'same-origin'})
        .then(response => response.json())
        .then(result => {
            if (!result.ok) throw new Error(result.message);
            (window.InventoryUI && window.InventoryUI.clean(), jQuery('#mainContent').simbioAJAX(result.url));
        })
        .catch(error => {
            errorBox.textContent = error instanceof SyntaxError ? 'Sesi berakhir atau unggahan melebihi batas server. Muat ulang halaman dan periksa ukuran foto.' : (error.message || 'Penyimpanan gagal. Silakan coba lagi.');
            errorBox.hidden = false;
        })
        .finally(() => { delete form.dataset.saving; button.disabled = false; });
    return false;
};
</script>

<?php require_once __DIR__ . '/src/InventoryUi.php'; \SLiMS\Plugins\Inventory\InventoryUi::assets(); ?>
<div class="inventory-ui" x-data="inventoryPage" @input="if(['save_item','save_location'].includes($event.target.form?.elements.namedItem('form_action')?.value)) dirty=true" @change="if(['save_item','save_location'].includes($event.target.form?.elements.namedItem('form_action')?.value)) dirty=true">
<?php \SLiMS\Plugins\Inventory\InventoryUi::header('inventory'); ?>
<div class="inv-breadcrumb"><a href="<?= inventory_e(inventory_url()) ?>">Ruangan</a><?php if ($action !== 'list'): ?><span> / <?= inventory_e(['view_location'=>'Daftar barang','add_item'=>'Tambah barang','edit_item'=>'Ubah barang','add_location'=>'Tambah ruangan','edit_location'=>'Ubah ruangan','view_photos'=>'Foto barang'][$action] ?? 'Daftar') ?></span><?php endif; ?></div>
<?php if ($canWrite && !in_array($action, ['view_location','add_item','edit_item','add_location','edit_location','view_photos'],true)): ?><a class="btn btn-primary" href="<?= inventory_e(inventory_url(['action'=>'add_location'])) ?>">+ Tambah Ruangan</a><?php endif; ?>

<?php if ($message !== ''): ?><div class="alert alert-<?= inventory_e($messageType) ?> m-3"><?= inventory_e($message) ?></div><?php endif; ?>

<?php if ($action === 'view_photos'): ?>
    <div class="inventory-card"><div class="inventory-card-header">Foto Barang: <?= inventory_e($itemForm['item_name'] ?? '') ?></div><div class="inventory-card-body">
        <?php if ($itemForm): ?>
            <?php inventory_photos((int) $itemForm['id'], $canWrite); ?>
            <div class="inventory-actions mt-3">
                <a class="btn btn-default" href="<?= inventory_e(inventory_url(['action' => 'view_location', 'location_id' => (int) $itemForm['location_id']])) ?>">Kembali ke Daftar Barang</a>
                <?php if ($canWrite): ?><a class="btn btn-primary" href="<?= inventory_e(inventory_url(['action' => 'edit_item', 'record_id' => (int) $itemForm['id']])) ?>">Ubah Barang dan Foto</a><?php endif; ?>
            </div>
        <?php else: ?><p>Barang tidak ditemukan.</p><?php endif; ?>
    </div></div>
<?php elseif (in_array($action, ['add_location', 'edit_location'], true) && $canWrite): ?>
    <?php
    $defaults = array_merge([
        'id' => 0, 'slims_location_id' => '', 'location_code' => '', 'room_name' => '', 'province' => 'JAWA TENGAH',
        'regency_city' => 'SEMARANG', 'unit_name' => 'PERPUSTAKAAN', 'work_unit' => '',
        'signature_city' => 'Semarang', 'knowing_title' => '', 'knowing_name' => '', 'knowing_identity' => '',
        'manager_title' => 'Pengurus Barang Inventaris', 'manager_name' => '', 'manager_identity' => '',
    ], $locationForm);
    ?>
    <div class="inventory-card"><div class="inventory-card-header"><?= $defaults['id'] ? 'Ubah Lokasi' : 'Tambah Lokasi' ?></div><div class="inventory-card-body">
        <form class="submitViaAJAX" method="post" action="<?= inventory_e(inventory_url()) ?>">
            <input type="hidden" name="csrf_token" value="<?= inventory_e($csrf) ?>"><input type="hidden" name="form_action" value="save_location"><input type="hidden" name="record_id" value="<?= (int) $defaults['id'] ?>">
            <div class="inventory-grid">
                <div class="form-group"><label>Lokasi Perpustakaan</label><select class="form-control" name="slims_location_id"><option value="">Tidak ditentukan</option><?php foreach ($masterLocations as $masterLocation): ?><option value="<?= inventory_e($masterLocation['location_id']) ?>" <?= (string) $defaults['slims_location_id'] === (string) $masterLocation['location_id'] ? 'selected' : '' ?>><?= inventory_e($masterLocation['location_name'] . ' (' . $masterLocation['location_id'] . ')') ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>No. Kode Lokasi Kartu</label><input class="form-control" name="location_code" maxlength="100" value="<?= inventory_e($defaults['location_code']) ?>"><small class="form-text text-muted">Kode lokasi boleh sama untuk beberapa ruangan.</small></div>
                <div class="form-group"><label>Ruangan <span class="text-danger">*</span></label><input class="form-control" required name="room_name" maxlength="255" value="<?= inventory_e($defaults['room_name']) ?>"></div>
                </div><details><summary>Informasi administratif & penandatangan kartu</summary><div class="inventory-grid"><div class="form-group"><label>Provinsi</label><input class="form-control" name="province" maxlength="150" value="<?= inventory_e($defaults['province']) ?>"></div>
                <div class="form-group"><label>Kabupaten/Kota</label><input class="form-control" name="regency_city" maxlength="150" value="<?= inventory_e($defaults['regency_city']) ?>"></div>
                <div class="form-group"><label>Unit</label><input class="form-control" name="unit_name" maxlength="255" value="<?= inventory_e($defaults['unit_name']) ?>"></div>
                <div class="form-group"><label>Satuan Kerja</label><input class="form-control" name="work_unit" maxlength="255" value="<?= inventory_e($defaults['work_unit']) ?>"></div>
                <div class="form-group"><label>Kota Penandatanganan</label><input class="form-control" name="signature_city" maxlength="150" value="<?= inventory_e($defaults['signature_city']) ?>"></div>
                <div class="form-group"><label>Jabatan Mengetahui</label><input class="form-control" name="knowing_title" maxlength="255" value="<?= inventory_e($defaults['knowing_title']) ?>"></div>
                <div class="form-group"><label>Jabatan Pengurus</label><input class="form-control" name="manager_title" maxlength="255" value="<?= inventory_e($defaults['manager_title']) ?>"></div>
                <div class="form-group"><label>Nama Pejabat Mengetahui</label><input class="form-control" name="knowing_name" maxlength="255" value="<?= inventory_e($defaults['knowing_name']) ?>"></div>
                <div class="form-group"><label>Nama Pengurus</label><input class="form-control" name="manager_name" maxlength="255" value="<?= inventory_e($defaults['manager_name']) ?>"></div>
                <div class="form-group"><label>NIP/Identitas Pejabat</label><input class="form-control" name="knowing_identity" maxlength="100" value="<?= inventory_e($defaults['knowing_identity']) ?>"></div>
                <div class="form-group"><label>NIP/Identitas Pengurus</label><input class="form-control" name="manager_identity" maxlength="100" value="<?= inventory_e($defaults['manager_identity']) ?>"></div>
            </div>
            </details><button class="btn btn-primary" type="submit">Simpan Lokasi</button> <a class="btn btn-default" href="<?= inventory_e(inventory_url()) ?>">Batal</a>
        </form>
    </div></div>

<?php elseif (in_array($action, ['add_item', 'edit_item'], true) && $canWrite): ?>
    <?php
    $defaults = array_merge([
        'id' => 0, 'location_id' => '', 'item_name' => '', 'brand_model' => '', 'serial_number' => '',
        'item_size' => '', 'material' => '', 'acquisition_year' => '', 'item_code' => '', 'quantity_register' => '',
        'acquisition_price' => 0, 'item_condition' => 'B', 'notes' => '',
    ], $itemForm);
    ?>
    <div class="inventory-card"><div class="inventory-card-header"><?= $defaults['id'] ? 'Ubah Barang' : 'Tambah Barang' ?></div><div class="inventory-card-body">
        <?php if (!$locations): ?><div class="alert alert-warning">Tambahkan lokasi terlebih dahulu sebelum mencatat barang.</div><?php else: ?>
        <form class="submitViaAJAX" method="post" enctype="multipart/form-data" onsubmit="return inventorySaveWithPhotos(event, this)" action="<?= inventory_e(inventory_url(['action' => 'view_location', 'location_id' => (int) $defaults['location_id'], 'photo_save' => '1'])) ?>">
            <input type="hidden" name="csrf_token" value="<?= inventory_e($csrf) ?>"><input type="hidden" name="form_action" value="save_item"><input type="hidden" name="code_form_token" value="<?= inventory_e(bin2hex(random_bytes(32))) ?>"><input type="hidden" name="record_id" value="<?= (int) $defaults['id'] ?>">
            <div class="inventory-grid">
                <h4 class="inv-section-title">Identitas barang</h4><div class="form-group"><label>Lokasi/Ruangan <span class="text-danger">*</span></label><select class="form-control" name="location_id" required><option value="">Pilih lokasi</option><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>" <?= (int) $defaults['location_id'] === (int) $location['id'] ? 'selected' : '' ?>><?= inventory_e(($location['location_code'] ? $location['location_code'] . ' — ' : '') . $location['room_name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Jenis/Nama Barang <span class="text-danger">*</span></label><input class="form-control" required name="item_name" maxlength="255" value="<?= inventory_e($defaults['item_name']) ?>"></div>
                <div class="form-group"><label>Merk/Model</label><input class="form-control" name="brand_model" maxlength="255" value="<?= inventory_e($defaults['brand_model']) ?>"></div>
                <div class="form-group"><label>No. Seri Pabrik</label><input class="form-control" name="serial_number" maxlength="255" value="<?= inventory_e($defaults['serial_number']) ?>"></div>
                <h4 class="inv-section-title">Detail inventaris</h4><div class="form-group"><label>Ukuran</label><input class="form-control" name="item_size" maxlength="150" value="<?= inventory_e($defaults['item_size']) ?>"></div>
                <div class="form-group"><label>Bahan</label><input class="form-control" name="material" maxlength="150" value="<?= inventory_e($defaults['material']) ?>"></div>
                <div class="form-group"><label>Tahun Pembuatan/Pembelian</label><input class="form-control" type="number" min="1000" max="<?= (int) date('Y') + 1 ?>" name="acquisition_year" value="<?= inventory_e($defaults['acquisition_year']) ?>"></div>
                <div class="form-group"><label for="inventory-item-code">No. Kode Barang</label>
                    <div class="input-group"><input id="inventory-item-code" class="form-control" name="item_code" maxlength="150" value="<?= inventory_e($defaults['item_code']) ?>" aria-describedby="inventory-code-help">
                    <div class="input-group-append"><button type="button" class="btn btn-default" onclick="inventoryMakeCode(this)" data-url="<?= inventory_e(inventory_url()) ?>">Buat Kode</button></div></div>
                    <small id="inventory-code-help" class="form-text text-muted">Opsional: isi manual atau klik Buat Kode. Pola: kode master perpustakaan-INV-000001. Nomor yang dipesan tidak digunakan ulang meskipun form dibatalkan; urutan dapat berlubang. Perubahan ruangan tidak mengubah kode.</small>
                    <div class="text-danger inventory-code-error" role="alert"></div>
                </div>
                <div class="form-group"><label>Jumlah Barang/Register</label><input class="form-control" name="quantity_register" maxlength="150" value="<?= inventory_e($defaults['quantity_register']) ?>" placeholder="Contoh: 1 / 001"></div>
                <div class="form-group"><label>Harga Beli/Perolehan (Rp)</label><input class="form-control" type="number" min="0" step="0.01" name="acquisition_price" value="<?= inventory_e($defaults['acquisition_price']) ?>"></div>
                <h4 class="inv-section-title">Kondisi & keterangan</h4><div class="form-group"><label>Keadaan Barang</label><select class="form-control" name="item_condition"><option value="B" <?= $defaults['item_condition'] === 'B' ? 'selected' : '' ?>>Baik (B)</option><option value="KB" <?= $defaults['item_condition'] === 'KB' ? 'selected' : '' ?>>Kurang Baik (KB)</option><option value="RB" <?= $defaults['item_condition'] === 'RB' ? 'selected' : '' ?>>Rusak Berat (RB)</option></select></div>
                <div class="form-group wide"><label>Keterangan</label><textarea class="form-control" name="notes" rows="3"><?= inventory_e($defaults['notes']) ?></textarea></div>
            </div>
            <fieldset class="form-group" x-data="inventoryUpload">
                <legend style="font-size:1rem">Foto barang</legend>
                <?php if ($defaults['id']): inventory_photos((int) $defaults['id'], true); endif; ?>
                <label for="inventory-item-photos">Tambah foto</label>
                <input @change="change($event)" id="inventory-item-photos" class="form-control" type="file" name="item_photos[]" accept="image/jpeg,image/png,image/webp" multiple aria-describedby="inventory-photo-help">
                <small id="inventory-photo-help" class="form-text text-muted">Maksimal 5 foto per barang, masing-masing 2 MB. JPEG, PNG, atau WebP; maksimal 8 megapiksel dan 4096 piksel per sisi. Foto disimpan saat Simpan Barang diklik.</small>
                <div class="inventory-photo-preview watch-photos" aria-live="polite"><template x-for="preview in previews" :key="preview"><img :src="preview" alt="Pratinjau foto barang"></template></div>
            </fieldset>
            <div class="alert alert-danger inventory-save-error" role="alert" hidden></div>
            <div class="inv-footer"><button class="btn btn-primary" type="submit">Simpan Barang</button> <a class="btn btn-default" href="<?= inventory_e(inventory_url(['action' => 'view_location', 'location_id' => (int) $defaults['location_id']])) ?>">Batal</a></div>
        </form>
        <?php endif; ?>
    </div></div>

<?php else: ?>
    <?php
    $selectedLocation = isset($_GET['location_id']) ? (int) $_GET['location_id'] : 0;
    $selectedSlimsLocation = trim((string) ($_GET['slims_location_id'] ?? ''));
    $availableMasterLocationIds = array_map('strval', array_column($masterLocations, 'location_id'));
    if ($selectedSlimsLocation !== '' && !in_array($selectedSlimsLocation, $availableMasterLocationIds, true)) {
        $selectedSlimsLocation = '';
    }
    $keyword = trim((string) ($_GET['keywords'] ?? ''));
    $selectedLocationData = null;
    foreach ($locations as $location) {
        if ((int) $location['id'] === $selectedLocation) {
            $selectedLocationData = $location;
            break;
        }
    }
    $isLocationView = $action === 'view_location' && $selectedLocationData !== null;
    if ($action === 'view_location' && $selectedLocationData === null) {
        $selectedLocation = 0;
    }
    ?>
    <?php if ($isLocationView): ?>
    <div class="inventory-card inventory-location">
        <div class="inventory-location-top">
            <div class="inventory-location-heading">
                <h3 class="inventory-location-title"><?= inventory_e($selectedLocationData['room_name']) ?></h3>
                <div class="inventory-location-meta">
                    <span>Kode: <?= inventory_e($selectedLocationData['location_code'] ?: '-') ?></span>
                    <span>Lokasi: <?= inventory_e($selectedLocationData['slims_location_name'] ?: '-') ?><?= $selectedLocationData['slims_location_id'] ? ' (' . inventory_e($selectedLocationData['slims_location_id']) . ')' : '' ?></span>
                    <span><?= (int) $selectedLocationData['item_count'] ?> barang</span>
                </div>
            </div>
            <div class="inventory-actions">
                <a class="btn btn-sm btn-success notAJAX" target="_blank" href="<?= inventory_e($printBase . '&' . http_build_query(['location_id' => $selectedLocation])) ?>">Cetak PDF</a>
                <?php if ($canWrite): ?><a class="btn btn-sm btn-primary" href="<?= inventory_e(inventory_url(['action' => 'add_item', 'location_id' => $selectedLocation])) ?>">Tambah Barang</a><?php endif; ?>
            </div>
        </div>
        <details class="inventory-location-details">
            <summary>Detail lokasi</summary>
            <dl>
                <div><dt>Wilayah:</dt><dd><?= inventory_e(trim($selectedLocationData['regency_city'] . ', ' . $selectedLocationData['province'], ', ') ?: '-') ?></dd></div>
                <div><dt>Unit:</dt><dd><?= inventory_e($selectedLocationData['unit_name'] ?: '-') ?></dd></div>
                <div><dt>Satuan kerja:</dt><dd><?= inventory_e($selectedLocationData['work_unit'] ?: '-') ?></dd></div>
            </dl>
        </details>
    </div>
    <?php else: ?>
    <?php if ($action === 'view_location'): ?><div class="alert alert-warning m-3">Lokasi yang dipilih tidak ditemukan.</div><?php endif; ?>
    <div class="inventory-card p-0">
        <div class="inventory-card-body">
            <form class="form-inline mb-3 submitViaAJAX" method="get" action="<?= inventory_e($_SERVER['PHP_SELF']) ?>"><?php foreach (['mod', 'id'] as $key): if (isset($_GET[$key])): ?><input type="hidden" name="<?= $key ?>" value="<?= inventory_e($_GET[$key]) ?>"><?php endif; endforeach; ?>
                <select class="form-control mr-2" name="slims_location_id" title="Lokasi Perpustakaan"><option value="">Semua lokasi Perpustakaan</option><?php foreach ($masterLocations as $masterLocation): ?><option value="<?= inventory_e($masterLocation['location_id']) ?>" <?= $selectedSlimsLocation === (string) $masterLocation['location_id'] ? 'selected' : '' ?>><?= inventory_e($masterLocation['location_name'] . ' (' . $masterLocation['location_id'] . ')') ?></option><?php endforeach; ?></select><button class="btn btn-default" type="submit">Filter Lokasi</button>
            </form>
            <p class="text-muted">Pilih Lihat Barang pada lokasi/ruangan untuk menampilkan daftar barangnya.</p>
            <?php
            $grid = inventory_datagrid('location');
            $grid->setSQLColumn(
                "l.id",
                "COALESCE(ml.location_name, '-') AS 'Lokasi Perpustakaan'",
                "l.location_code AS 'Kode Kartu'",
                "l.room_name AS 'Ruangan'",
                "CONCAT_WS(', ', NULLIF(l.regency_city, ''), NULLIF(l.province, '')) AS 'Wilayah'",
                "CONCAT_WS(' — ', l.unit_name, NULLIF(l.work_unit, '')) AS 'Unit/Satuan Kerja'",
                "(SELECT COUNT(*) FROM inventory_items i WHERE i.location_id = l.id) AS 'Barang'",
                "l.id AS 'Aksi'"
            );
            $grid->setSQLorder('l.room_name, l.location_code, l.id');
            if ($selectedSlimsLocation !== '') {
                $grid->setSQLCriteria("l.slims_location_id = '" . $dbs->escape_string($selectedSlimsLocation) . "'");
            }
            foreach (range(1, 6) as $column) {
                $grid->modifyColumnContent($column, 'callback{inventory_grid_text}');
            }
            $grid->modifyColumnContent(7, 'callback{inventory_location_actions}');
            $gridHtml = $grid->createDataGrid($dbs, 'inventory_locations l LEFT JOIN mst_location ml ON ml.location_id = l.slims_location_id', 20, $canWrite);
            echo '<div class="table-wrap">'.$gridHtml.'</div>';
            if (!$grid->num_rows) {
                echo '<div class="inventory-empty">Belum ada lokasi inventaris yang sesuai filter.</div>';
            }
            ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isLocationView): ?>
    <?php if (($_GET['saved'] ?? '') === '1'): ?><div class="alert alert-success" role="status">Barang berhasil disimpan.</div><?php endif; ?>
    <div class="inventory-card"><div class="inventory-card-header">Barang di <?= inventory_e($selectedLocationData['room_name']) ?></div><div class="inventory-card-body">
        <form class="form-inline mb-3 submitViaAJAX" method="get" action="<?= inventory_e($_SERVER['PHP_SELF']) ?>"><?php foreach (['mod', 'id'] as $key): if (isset($_GET[$key])): ?><input type="hidden" name="<?= $key ?>" value="<?= inventory_e($_GET[$key]) ?>"><?php endif; endforeach; ?>
            <input type="hidden" name="action" value="view_location"><input type="hidden" name="location_id" value="<?= $selectedLocation ?>">
            <input class="form-control mr-2" name="keywords" value="<?= inventory_e($keyword) ?>" placeholder="Nama, kode, atau merk"><button class="btn btn-default" type="submit">Filter</button>
        </form>
        <?php
        $grid = inventory_datagrid('item');
        $grid->setSQLColumn(
            "i.id",
            "l.room_name AS 'Lokasi'",
            "i.item_name AS 'Nama Barang'",
            "i.brand_model AS 'Merk/Model'",
            "i.item_code AS 'Kode'",
            "i.quantity_register AS 'Register'",
            "i.acquisition_price AS 'Harga'",
            "i.item_condition AS 'Kondisi'"
        );
        $criteria = 'i.location_id = ' . (int) $selectedLocation;
        if ($keyword !== '') {
            $search = $dbs->escape_string('%' . $keyword . '%');
            $criteria .= " AND (i.item_name LIKE '$search' OR i.item_code LIKE '$search' OR i.brand_model LIKE '$search')";
        }
        $grid->setSQLCriteria($criteria);
        $grid->setSQLorder('i.item_name, i.id');
        foreach ([1, 3, 4, 5, 7] as $column) {
            $grid->modifyColumnContent($column, 'callback{inventory_grid_text}');
        }
        $grid->modifyColumnContent(2, 'callback{inventory_item_name}');
        $grid->modifyColumnContent(6, 'callback{inventory_grid_price}');
        $gridHtml = $grid->createDataGrid($dbs, 'inventory_items i JOIN inventory_locations l ON l.id = i.location_id', 20, $canWrite);
        echo '<div class="table-wrap">'.$gridHtml.'</div>';
        if (!$grid->num_rows) {
            echo '<div class="inventory-empty">Belum ada barang yang sesuai filter.</div>';
        }
        ?>
    </div></div>
    <?php endif; ?>
<?php endif; ?>

</div>
