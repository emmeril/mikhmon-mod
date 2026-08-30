<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
include_once('./include/database.php');
$identityMessage = isset($_GET['saved']) ? 'Identitas pelanggan berhasil disimpan.' : '';
if (isset($_GET['deleted'])) $identityMessage = 'Identitas pelanggan berhasil dihapus.';
$identityError = '';
function identityListApiError($response) { if (!is_array($response)) return ''; foreach (array('!trap','!fatal') as $type) if (isset($response[$type][0]['message'])) return $response[$type][0]['message']; return ''; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['identity_action'] ?? '') === 'delete') {
  $id = (string) ($_POST['customer_id'] ?? '');
  $customer = mikhmonFindCustomer($session, $id);
  if (!$customer) $identityError = 'Identitas pelanggan tidak ditemukan.';
  elseif (!mikhmonCanManageCustomer($customer)) $identityError = 'Anda tidak berhak menghapus identitas ini.';
  elseif (mikhmonCustomerServices($customer) && empty($routerConnected)) $identityError = 'Router MikroTik tidak terhubung. Identitas dengan layanan belum dapat dihapus.';
  else {
    $deleteOk = true;
    foreach (mikhmonCustomerServices($customer) as $service) {
      $command = $service['service'] === 'pppoe' ? '/ppp/secret' : '/ip/hotspot/user';
      $rows = $API->comm($command . '/print', array('?name' => $service['username']));
      if (identityListApiError($rows) !== '') { $identityError = 'Gagal membaca layanan ' . $service['username'] . ': ' . identityListApiError($rows); $deleteOk = false; break; }
      if (isset($rows[0]['.id'])) {
        $response = $API->comm($command . '/remove', array('.id' => $rows[0]['.id']));
        if (identityListApiError($response) !== '') { $identityError = 'Gagal menghapus layanan ' . $service['username'] . ': ' . identityListApiError($response); $deleteOk = false; break; }
      }
      $legacyNames = $service['service'] === 'pppoe' ? array('mikhmon-pppoe-' . $service['username'], 'mikhmon-pppoe-sale-' . $service['username']) : array($service['username']);
      foreach ($legacyNames as $legacyName) foreach (array('/system/scheduler','/system/script') as $cleanupCommand) {
        $cleanupRows = $API->comm($cleanupCommand . '/print', array('?name' => $legacyName));
        if (isset($cleanupRows[0]['.id'])) $API->comm($cleanupCommand . '/remove', array('.id' => $cleanupRows[0]['.id']));
      }
    }
    if ($deleteOk && !empty($routerConnected)) {
      $schedulerName = 'mikhmon-customer-' . substr(md5((string) $id), 0, 12);
      $schedulerRows = $API->comm('/system/scheduler/print', array('?name' => $schedulerName));
      if (isset($schedulerRows[0]['.id'])) $API->comm('/system/scheduler/remove', array('.id' => $schedulerRows[0]['.id']));
    }
    if ($deleteOk && mikhmonDeleteCustomer($session, $id)) {
      $query = './?customer=identity-list&session=' . rawurlencode($session) . '&deleted=1';
      echo "<script>window.location=" . json_encode($query) . "</script>"; exit;
    } elseif ($deleteOk) $identityError = 'Identitas pelanggan gagal dihapus.';
  }
}

$identities = mikhmonVisibleCustomers($session);
?>
<style>
  .identity-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
  .identity-toolbar input{max-width:360px}
  .identity-table td,.identity-table th{vertical-align:middle}
  .identity-service-count{text-align:center;font-weight:bold}
  .identity-empty{padding:28px;text-align:center;color:#888}
  @media(max-width:767px){.identity-toolbar{align-items:stretch;flex-direction:column}.identity-toolbar input{max-width:none}.identity-toolbar .btn{width:100%;text-align:center}}
</style>
<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3><i class="fa fa-id-card"></i> Daftar Identitas Pelanggan <span style="font-size:14px">&nbsp;|&nbsp; <?= count($identities); ?> identitas</span></h3></div>
  <div class="card-body">
    <?php if ($identityMessage !== ''): ?><div class="box bg-success"><i class="fa fa-check"></i> <?= htmlspecialchars($identityMessage, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($identityError !== ''): ?><div class="box bg-danger"><i class="fa fa-warning"></i> <?= htmlspecialchars($identityError, ENT_QUOTES); ?></div><?php endif; ?>
    <div class="identity-toolbar"><input id="identitySearch" type="text" class="form-control" placeholder="Cari nama, nomor HP, atau alamat"><a class="btn bg-primary" href="./?customer=identity-add&session=<?= rawurlencode($session); ?>"><i class="fa fa-user-plus"></i> Tambah Identitas</a></div>
    <div class="overflow box-bordered"><table id="identityTable" class="table table-bordered table-hover identity-table"><thead><tr><th>No</th><th>Nama Pelanggan</th><th>Nomor HP</th><th>Alamat</th><th>Jumlah Layanan</th><th>Aksi</th></tr></thead><tbody>
      <?php foreach ($identities as $index => $identity): $serviceCount = count(mikhmonCustomerServices($identity)); ?>
        <tr class="identity-row"><td><?= $index + 1; ?></td><td><?= htmlspecialchars($identity['name'] ?? '', ENT_QUOTES); ?></td><td><?= htmlspecialchars($identity['phone'] ?? '', ENT_QUOTES); ?></td><td><?= htmlspecialchars($identity['address'] ?? '', ENT_QUOTES); ?></td><td class="identity-service-count"><?= $serviceCount; ?></td><td><a class="btn bg-primary" href="./?customer=identity-edit&customer-id=<?= rawurlencode($identity['id']); ?>&session=<?= rawurlencode($session); ?>"><i class="fa fa-edit"></i> Edit</a> <a class="btn bg-secondary" href="./?customer=service-add&customer-id=<?= rawurlencode($identity['id']); ?>&session=<?= rawurlencode($session); ?>"><i class="fa fa-link"></i> Tambah Layanan</a> <form method="post" style="display:inline" onsubmit="return confirm('Hapus identitas pelanggan<?= $serviceCount > 0 ? ' beserta ' . $serviceCount . ' layanan MikroTik' : ''; ?>? Tindakan ini tidak dapat dibatalkan tanpa restore backup.');"><input type="hidden" name="identity_action" value="delete"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($identity['id'], ENT_QUOTES); ?>"><button class="btn bg-danger" type="submit"><i class="fa fa-trash"></i> Hapus</button></form></td></tr>
      <?php endforeach; ?>
      <?php if (!$identities): ?><tr><td colspan="6" class="identity-empty">Belum ada identitas pelanggan.</td></tr><?php endif; ?><tr id="identityNoResults" style="display:none"><td colspan="6" class="identity-empty">Identitas pelanggan tidak ditemukan.</td></tr>
    </tbody></table></div>
  </div>
</div></div></div>
<script>
$(function(){ $('#identitySearch').on('input',function(){var query=$(this).val().toLowerCase(), visible=0;$('.identity-row').each(function(){var show=$(this).text().toLowerCase().indexOf(query)>-1;$(this).toggle(show);if(show)visible++;});$('#identityNoResults').toggle(visible===0&&$('.identity-row').length>0);}); });
</script>
