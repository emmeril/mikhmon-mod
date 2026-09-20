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
  if ($_POST['database_action'] === 'router-database-store') {
    $recoveryPassword = isset($_POST['recovery_password']) ? (string) $_POST['recovery_password'] : '';
    $result = mikhmonStoreRouterDatabaseBackup($API, $session, $recoveryPassword);
    if (empty($result['status'])) {
      $backupError = $result['error'];
    } elseif (!mikhmonConfigureRouterDatabaseBackup($session, $recoveryPassword, isset($_POST['automatic_backup']), $result['payload_hash'])) {
      $backupError = 'Backup tersimpan di MikroTik, tetapi pengaturan backup otomatis gagal disimpan.';
    } else {
      $backupMessage = 'Database pelanggan tersimpan di MikroTik: ' . $result['customers'] . ' pelanggan, ' . $result['invoices'] . ' invoice, dan ' . $result['chunks'] . ' chunk terenkripsi.';
    }
  } elseif ($_POST['database_action'] === 'router-database-restore') {
    $recoveryPassword = isset($_POST['recovery_password']) ? (string) $_POST['recovery_password'] : '';
    $result = mikhmonRestoreRouterDatabaseBackup($API, $session, $recoveryPassword);
    if (empty($result['status'])) {
      $backupError = $result['error'];
    } else {
      mikhmonConfigureRouterDatabaseBackup($session, $recoveryPassword, isset($_POST['automatic_backup']));
      $backupMessage = 'Database berhasil dipulihkan: ' . $result['customers'] . ' pelanggan, ' . $result['invoices'] . ' invoice, dan ' . $result['users_added'] . ' akun staf baru.';
    }
  } elseif ($_POST['database_action'] === 'router-database-disable') {
    $settings = mikhmonReadRouterDatabaseBackupSettings();
    $storedPassword = isset($settings['sessions'][$session]['password']) ? mikhmonDecryptSecret($settings['sessions'][$session]['password']) : '';
    if ($storedPassword !== false && mikhmonConfigureRouterDatabaseBackup($session, $storedPassword, false)) $backupMessage = 'Backup database otomatis ke System Script dinonaktifkan. Backup yang sudah ada di MikroTik tetap dipertahankan.';
    else $backupError = 'Backup otomatis gagal dinonaktifkan.';
  } elseif ($_POST['database_action'] === 'backup') {
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
$routerDatabaseManifest = mikhmonRouterDatabaseManifest($API);
$routerDatabaseSettings = mikhmonReadRouterDatabaseBackupSettings();
$routerDatabaseAutomatic = !empty($routerDatabaseSettings['sessions'][$session]['enabled']);
?>
<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3><i class="fa fa-database"></i> Database Backup</h3></div>
  <div class="card-body">
    <?php if ($backupMessage !== ''): ?><div class="box bg-success"><?= htmlspecialchars($backupMessage, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($backupError !== ''): ?><div class="box bg-danger"><?= htmlspecialchars($backupError, ENT_QUOTES); ?></div><?php endif; ?>
    <p>Backup otomatis membaca data dari MikroTik satu kali setiap hari. Data tidak pernah dikembalikan ke router tanpa menekan tombol Restore.</p>
    <p>Masa simpan backup 7 hari. Snapshot yang berumur lebih dari 7 hari otomatis dihapus, termasuk backup terakhir yang sudah kedaluwarsa.</p>
    <p><strong>Backup terakhir:</strong> <?= htmlspecialchars($updatedAt, ENT_QUOTES); ?> &nbsp; | &nbsp; Hotspot: <?= count($snapshot['hotspot_users']); ?> &nbsp; | &nbsp; PPPoE: <?= count($snapshot['ppp_secrets']); ?> &nbsp; | &nbsp; <strong>Auto-backup harian:</strong> Aktif</p>
    <form method="post" style="display:inline-block;margin-right:8px"><?= mikhmonCsrfField(); ?><input type="hidden" name="database_action" value="backup"><button class="btn bg-primary" type="submit"><i class="fa fa-save"></i> Backup Sekarang</button></form>
    <form method="post" style="display:inline-block;margin-right:8px"><?= mikhmonCsrfField(); ?><input type="hidden" name="database_action" value="sync"><button class="btn bg-orange" type="submit"><i class="fa fa-refresh"></i> Sync Backup Sekarang</button></form>
    <form method="post" style="display:inline-block" onsubmit="return confirm('Restore akan menambahkan user yang belum ada di MikroTik. Lanjutkan?');"><?= mikhmonCsrfField(); ?><input type="hidden" name="database_action" value="restore"><select name="restore_type" class="pd-5"><option value="all">Semua</option><option value="hotspot">Hotspot</option><option value="pppoe">PPPoE</option></select> <select name="restore_version" class="pd-5"><option value="latest">Backup terbaru</option><?php foreach ($record['history'] as $historyIndex => $historySnapshot): ?><option value="history-<?= (int) $historyIndex; ?>"><?= date('Y-m-d H:i:s', (int) $historySnapshot['updated_at']); ?></option><?php endforeach; ?></select> <button class="btn bg-green" type="submit"><i class="fa fa-upload"></i> Restore</button></form>
  </div>
</div></div></div>

<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3><i class="fa fa-shield"></i> Backup Database Pelanggan di MikroTik</h3></div>
  <div class="card-body">
    <p>Simpan pelanggan, nomor HP, alamat, assignment mitra, dan histori tagihan sebagai System Script terenkripsi. Password pemulihan wajib disimpan sendiri dan diperlukan saat setup Mikhmon baru.</p>
    <p><strong>Status backup MikroTik:</strong>
      <?php if ($routerDatabaseManifest): ?>
        <span class="text-success">Tersedia</span> &middot; <?= htmlspecialchars(date('Y-m-d H:i:s', (int) ($routerDatabaseManifest['created_at'] ?? 0)), ENT_QUOTES); ?> &middot; <?= (int) ($routerDatabaseManifest['chunks'] ?? 0); ?> chunk &middot; session <?= htmlspecialchars((string) ($routerDatabaseManifest['session'] ?? ''), ENT_QUOTES); ?>
      <?php else: ?>
        <span class="text-danger">Belum tersedia</span>
      <?php endif; ?>
      &nbsp;|&nbsp; <strong>Otomatis:</strong> <?= $routerDatabaseAutomatic ? '<span class="text-success">Aktif</span>' : '<span class="text-grey">Nonaktif</span>'; ?>
    </p>
    <div class="row">
      <div class="col-6">
        <form method="post" autocomplete="off">
          <?= mikhmonCsrfField(); ?>
          <input type="hidden" name="database_action" value="router-database-store">
          <label>Password Pemulihan</label>
          <input class="form-control" type="password" name="recovery_password" minlength="8" required placeholder="Minimal 8 karakter">
          <label style="display:block;margin:10px 0"><input type="checkbox" name="automatic_backup" value="1" checked> Perbarui otomatis saat database berubah dan router terhubung</label>
          <button class="btn bg-primary" type="submit"><i class="fa fa-lock"></i> Simpan Database ke MikroTik</button>
        </form>
      </div>
      <div class="col-6">
        <form method="post" autocomplete="off" onsubmit="return confirm('Data backup akan digabungkan ke database lokal pada session ini. Lanjutkan?');">
          <?= mikhmonCsrfField(); ?>
          <input type="hidden" name="database_action" value="router-database-restore">
          <label>Password Pemulihan</label>
          <input class="form-control" type="password" name="recovery_password" minlength="8" required placeholder="Password saat backup dibuat">
          <label style="display:block;margin:10px 0"><input type="checkbox" name="automatic_backup" value="1" checked> Aktifkan backup otomatis setelah restore</label>
          <button class="btn bg-green" type="submit"<?= !$routerDatabaseManifest ? ' disabled' : ''; ?>><i class="fa fa-download"></i> Pulihkan Database dari MikroTik</button>
        </form>
        <?php if ($routerDatabaseAutomatic): ?>
          <form method="post" style="margin-top:10px" onsubmit="return confirm('Nonaktifkan backup otomatis? Backup di MikroTik tidak akan dihapus.');"><?= mikhmonCsrfField(); ?><input type="hidden" name="database_action" value="router-database-disable"><button class="btn bg-warning" type="submit"><i class="fa fa-pause"></i> Nonaktifkan Backup Otomatis</button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div></div></div>
