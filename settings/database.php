<?php
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
}
include_once('./include/database.php');

$backupMessage = '';
$backupError = '';
$syncResult = array();
if (isset($_POST['database_action'])) {
  if ($_POST['database_action'] === 'backup') {
    $snapshot = mikhmonBackupRouterData($API, $session, true);
    $backupMessage = 'Backup berhasil disimpan: ' . count($snapshot['hotspot_users']) . ' user Hotspot dan ' . count($snapshot['ppp_secrets']) . ' user PPPoE.';
  } elseif ($_POST['database_action'] === 'sync') {
    $syncResult = mikhmonSynchronizeRouterData($API, $session, true);
    if ($syncResult['status'] === 'router-error') {
      $backupError = 'Router tidak dapat dibaca. Backup lama tetap dipertahankan.';
    } else {
      $backupMessage = 'Sinkronisasi selesai dan backup terbaru tersimpan.';
    }
  } elseif ($_POST['database_action'] === 'restore') {
    $restoreType = isset($_POST['restore_type']) ? $_POST['restore_type'] : 'all';
    $restoreVersion = isset($_POST['restore_version']) ? $_POST['restore_version'] : 'latest';
    $result = mikhmonRestoreRouterData($API, $session, $restoreType, $restoreVersion);
    if (isset($result['error'])) {
      $backupError = $result['error'];
    } else {
      $backupMessage = 'Restore selesai: ' . $result['users'] . ' user dan ' . $result['profiles'] . ' profile ditambahkan. User yang sudah ada dilewati.';
    }
  }
}
$database = mikhmonReadDatabase();
$record = mikhmonGetRouterRecord($database, $session);
$snapshot = $record['latest'];
$updatedAt = !empty($snapshot['updated_at']) ? date('Y-m-d H:i:s', (int) $snapshot['updated_at']) : '-';
?>
<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3><i class="fa fa-database"></i> Database Backup</h3></div>
  <div class="card-body">
    <?php if ($backupMessage !== ''): ?><div class="box bg-success"><?= htmlspecialchars($backupMessage, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($backupError !== ''): ?><div class="box bg-danger"><?= htmlspecialchars($backupError, ENT_QUOTES); ?></div><?php endif; ?>
    <p>Backup otomatis membaca data dari MikroTik satu kali setiap hari. Data tidak pernah dikembalikan ke router tanpa menekan tombol Restore.</p>
    <p>Masa simpan backup 7 hari. Snapshot yang berumur lebih dari 7 hari otomatis dihapus, termasuk backup terakhir yang sudah kedaluwarsa.</p>
    <p><strong>Backup terakhir:</strong> <?= htmlspecialchars($updatedAt, ENT_QUOTES); ?> &nbsp; | &nbsp; Hotspot: <?= count($snapshot['hotspot_users']); ?> &nbsp; | &nbsp; PPPoE: <?= count($snapshot['ppp_secrets']); ?> &nbsp; | &nbsp; <strong>Auto-backup harian:</strong> Aktif</p>
    <form method="post" style="display:inline-block;margin-right:8px"><input type="hidden" name="database_action" value="backup"><button class="btn bg-primary" type="submit"><i class="fa fa-save"></i> Backup Sekarang</button></form>
    <form method="post" style="display:inline-block;margin-right:8px"><input type="hidden" name="database_action" value="sync"><button class="btn bg-orange" type="submit"><i class="fa fa-refresh"></i> Sync Backup Sekarang</button></form>
    <form method="post" style="display:inline-block" onsubmit="return confirm('Restore akan menambahkan user yang belum ada di MikroTik. Lanjutkan?');"><input type="hidden" name="database_action" value="restore"><select name="restore_type" class="pd-5"><option value="all">Semua</option><option value="hotspot">Hotspot</option><option value="pppoe">PPPoE</option></select> <select name="restore_version" class="pd-5"><option value="latest">Backup terbaru</option><?php foreach ($record['history'] as $historyIndex => $historySnapshot): ?><option value="history-<?= (int) $historyIndex; ?>"><?= date('Y-m-d H:i:s', (int) $historySnapshot['updated_at']); ?></option><?php endforeach; ?></select> <button class="btn bg-green" type="submit"><i class="fa fa-upload"></i> Restore</button></form>
  </div>
</div></div></div>
