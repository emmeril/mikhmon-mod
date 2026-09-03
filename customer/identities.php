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
$identityMitraNames = array();
foreach (mikhmonGetUsers('mitra', $session) as $mitra) {
  if (isset($mitra['id'])) $identityMitraNames[(string) $mitra['id']] = (string) ($mitra['name'] ?? $mitra['username'] ?? '');
}
$identityServiceCounts = array();
$identityServiceFilterOptions = array();
foreach ($identities as $identity) {
  $identityId = isset($identity['id']) ? (string) $identity['id'] : '';
  $serviceCount = count(mikhmonCustomerServices($identity));
  $identityServiceCounts[$identityId] = $serviceCount;
  $identityServiceFilterOptions[$serviceCount] = $serviceCount;
}
ksort($identityServiceFilterOptions, SORT_NUMERIC);
?>
<style>
  .identity-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
  .identity-filter-controls{display:flex;align-items:center;gap:8px;flex:1}
  .identity-filter-controls input{max-width:360px;margin:0}
  .identity-filter-controls select{max-width:190px;margin:0}
  .identity-toolbar .btn{margin:0}
  .identity-table td,.identity-table th{vertical-align:middle}
  .identity-service-count{text-align:center;font-weight:bold}
  .identity-empty{padding:28px;text-align:center;color:#888}
  @media(max-width:767px){.identity-toolbar{align-items:stretch;flex-direction:column}.identity-filter-controls{align-items:stretch;flex-direction:column}.identity-filter-controls input,.identity-filter-controls select{max-width:none}.identity-filter-controls .btn,.identity-toolbar > .btn{width:100%;box-sizing:border-box;text-align:center}}
</style>
<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3><i class="fa fa-id-card"></i> Daftar Identitas Pelanggan <span style="font-size:14px">&nbsp;|&nbsp; <span id="identityVisibleCount"><?= count($identities); ?></span> identitas</span></h3></div>
  <div class="card-body">
    <?php if ($identityMessage !== ''): ?><div class="box bg-success"><i class="fa fa-check"></i> <?= htmlspecialchars($identityMessage, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($identityError !== ''): ?><div class="box bg-danger"><i class="fa fa-warning"></i> <?= htmlspecialchars($identityError, ENT_QUOTES); ?></div><?php endif; ?>
    <div class="identity-toolbar"><div class="identity-filter-controls"><input id="identitySearch" type="text" class="form-control" placeholder="Cari nama, nomor HP, alamat, atau mitra"><select id="identityServiceFilter" class="form-control"><option value="all">Jumlah Layanan: Semua</option><?php foreach ($identityServiceFilterOptions as $serviceCount): ?><option value="<?= $serviceCount; ?>"><?= $serviceCount; ?> Layanan</option><?php endforeach; ?></select><button id="identityReset" type="button" class="btn bg-secondary"><i class="fa fa-refresh"></i> Reset Filter</button></div><a class="btn bg-primary" href="./?customer=identity-add&session=<?= rawurlencode($session); ?>"><i class="fa fa-user-plus"></i> Tambah Identitas</a></div>
    <div class="overflow box-bordered"><table id="identityTable" class="table table-bordered table-hover identity-table"><thead><tr><th>No</th><th>Nama Pelanggan</th><th>Nomor HP</th><th>Alamat</th><th>Mitra</th><th>Jumlah Layanan</th><th>Aksi</th></tr></thead><tbody>
      <?php foreach ($identities as $index => $identity): $identityId = isset($identity['id']) ? (string) $identity['id'] : ''; $serviceCount = isset($identityServiceCounts[$identityId]) ? $identityServiceCounts[$identityId] : 0; $identityMitraId = (string) ($identity['mitra_id'] ?? ''); $identityMitraName = $identityMitraId !== '' && isset($identityMitraNames[$identityMitraId]) ? $identityMitraNames[$identityMitraId] : 'Belum ditetapkan'; ?>
        <tr class="identity-row" data-service-count="<?= $serviceCount; ?>"><td><?= $index + 1; ?></td><td><?= htmlspecialchars($identity['name'] ?? '', ENT_QUOTES); ?></td><td><?= htmlspecialchars($identity['phone'] ?? '', ENT_QUOTES); ?></td><td><?= htmlspecialchars($identity['address'] ?? '', ENT_QUOTES); ?></td><td><?= htmlspecialchars($identityMitraName, ENT_QUOTES); ?></td><td class="identity-service-count"><?= $serviceCount; ?></td><td><a class="btn bg-primary" href="./?customer=identity-edit&customer-id=<?= rawurlencode($identity['id']); ?>&session=<?= rawurlencode($session); ?>"><i class="fa fa-edit"></i> Edit</a> <a class="btn bg-secondary" href="./?customer=service-add&customer-id=<?= rawurlencode($identity['id']); ?>&session=<?= rawurlencode($session); ?>"><i class="fa fa-link"></i> Tambah Layanan</a> <form method="post" style="display:inline" onsubmit="return confirm('Hapus identitas pelanggan<?= $serviceCount > 0 ? ' beserta ' . $serviceCount . ' layanan MikroTik' : ''; ?>? Tindakan ini tidak dapat dibatalkan tanpa restore backup.');"><input type="hidden" name="identity_action" value="delete"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($identity['id'], ENT_QUOTES); ?>"><button class="btn bg-danger" type="submit"><i class="fa fa-trash"></i> Hapus</button></form></td></tr>
      <?php endforeach; ?>
      <?php if (!$identities): ?><tr><td colspan="7" class="identity-empty">Belum ada identitas pelanggan.</td></tr><?php endif; ?><tr id="identityNoResults" style="display:none"><td colspan="7" class="identity-empty">Identitas pelanggan tidak ditemukan.</td></tr>
    </tbody></table></div>
  </div>
</div></div></div>
<script>
$(function(){
  function filterIdentities(){
    var query=$('#identitySearch').val().toLowerCase(), serviceCount=$('#identityServiceFilter').val(), visible=0;
    $('.identity-row').each(function(){
      var row=$(this), matchesSearch=row.text().toLowerCase().indexOf(query)>-1, matchesCount=serviceCount==='all'||String(row.data('service-count'))===serviceCount, show=matchesSearch&&matchesCount;
      row.toggle(show);
      if(show) visible++;
    });
    $('#identityVisibleCount').text(visible);
    $('#identityNoResults').toggle(visible===0&&$('.identity-row').length>0);
  }
  $('#identitySearch').on('input',filterIdentities);
  $('#identityServiceFilter').on('change',filterIdentities);
  $('#identityReset').on('click',function(){ $('#identitySearch').val(''); $('#identityServiceFilter').val('all'); filterIdentities(); });
  filterIdentities();
});
</script>
