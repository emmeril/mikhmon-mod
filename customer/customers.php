<?php
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
}

include_once('./include/database.php');

$customerMessage = '';
$customerError = '';

function customerListApiError($response) {
  if (!is_array($response)) return '';
  foreach (array('!trap', '!fatal') as $type) {
    if (isset($response[$type][0]['message'])) return $response[$type][0]['message'];
  }
  return '';
}

if (isset($_GET['created']) && $_GET['created'] === '1') {
  $customerMessage = 'Pelanggan dan user MikroTik berhasil dibuat.';
} elseif (isset($_GET['updated']) && $_GET['updated'] === '1') {
  $customerMessage = 'Data pelanggan dan user MikroTik berhasil diperbarui.';
} elseif (isset($_GET['service-added']) && $_GET['service-added'] === '1') {
  $customerMessage = 'Layanan berhasil dikaitkan ke pelanggan.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = isset($_POST['customer_action']) ? $_POST['customer_action'] : '';
  $actionCustomerId = isset($_POST['customer_id']) ? (string) $_POST['customer_id'] : '';
  $actionCustomer = mikhmonFindCustomer($session, $actionCustomerId);
  if ($action === 'assign_mitra' && mikhmonIsAdmin()) {
    $mitraId = isset($_POST['mitra_id']) ? (string) $_POST['mitra_id'] : '';
    $mitra = $mitraId !== '' ? mikhmonFindUser($mitraId) : false;
    if ($mitraId !== '' && (!$mitra || $mitra['role'] !== 'mitra' || $mitra['session'] !== $session)) {
      $customerError = 'Mitra tidak valid untuk router ini.';
    } elseif (mikhmonAssignCustomer($session, $actionCustomerId, $mitraId)) {
      $customerMessage = $mitra ? 'Pelanggan berhasil ditetapkan ke ' . $mitra['name'] . '.' : 'Assignment mitra berhasil dilepas.';
    } else {
      $customerError = 'Assignment mitra gagal disimpan.';
    }
  } elseif ($action === 'delete') {
    if (!$actionCustomer || !mikhmonCanManageCustomer($actionCustomer)) {
      $customerError = 'Anda tidak berhak menghapus pelanggan ini.';
    } elseif (mikhmonDeleteCustomer($session, $actionCustomerId)) {
      $customerMessage = 'Data pelanggan berhasil dihapus.';
    } else {
      $customerError = 'Data pelanggan tidak ditemukan.';
    }
  } elseif ($action === 'delete_all') {
    $deleteId = $actionCustomerId;
    $deleteCustomer = $actionCustomer;
    if (!$deleteCustomer) {
      $customerError = 'Data pelanggan tidak ditemukan.';
    } elseif (!mikhmonCanManageCustomer($deleteCustomer)) {
      $customerError = 'Anda tidak berhak menghapus pelanggan ini.';
    } elseif (empty($routerConnected)) {
      $customerError = 'Router MikroTik tidak terhubung. Data tidak dihapus.';
    } else {
      $deleteOk = true;
      foreach (mikhmonCustomerServices($deleteCustomer) as $deleteService) {
        $deleteCommand = $deleteService['service'] === 'pppoe' ? '/ppp/secret' : '/ip/hotspot/user';
        $deleteUsername = (string) $deleteService['username'];
        $deleteRows = $deleteUsername !== '' ? $API->comm($deleteCommand . '/print', array('?name' => $deleteUsername)) : array();
        $deleteError = customerListApiError($deleteRows);
        if ($deleteError !== '') { $deleteOk = false; $customerError = 'Gagal membaca user MikroTik: ' . $deleteError; break; }
        if (isset($deleteRows[0]['.id'])) {
          $deleteResponse = $API->comm($deleteCommand . '/remove', array('.id' => $deleteRows[0]['.id']));
          $deleteResponseError = customerListApiError($deleteResponse);
          if ($deleteResponseError !== '') { $deleteOk = false; $customerError = 'Gagal menghapus user MikroTik: ' . $deleteResponseError; break; }
        }
        $cleanupNames = $deleteCommand === '/ppp/secret' ? array('mikhmon-pppoe-' . $deleteUsername, 'mikhmon-pppoe-sale-' . $deleteUsername) : array($deleteUsername);
        foreach ($cleanupNames as $cleanupName) foreach (array('/system/scheduler', '/system/script') as $cleanupCommand) {
          $cleanupRows = $API->comm($cleanupCommand . '/print', array('?name' => $cleanupName));
          if (isset($cleanupRows[0]['.id'])) $API->comm($cleanupCommand . '/remove', array('.id' => $cleanupRows[0]['.id']));
        }
      }
      if ($deleteOk) {
        if (mikhmonDeleteCustomer($session, $deleteId)) {
          $customerMessage = 'Pelanggan dan user MikroTik berhasil dihapus.';
        } else {
          $customerError = 'User MikroTik sudah dihapus, tetapi data pelanggan gagal dihapus.';
        }
      }
    }
  }
}

$customers = array_values(array_filter(mikhmonVisibleCustomers($session), function ($customer) { return count(mikhmonCustomerServices($customer)) > 0; }));
$mitras = mikhmonIsAdmin() ? mikhmonGetUsers('mitra', $session) : array();
?>
<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3><i class="fa fa-list"></i> Daftar Pelanggan <span style="font-size:14px">&nbsp;|&nbsp; <span id="customerVisibleCount"><?= count($customers); ?></span> pelanggan</span></h3></div>
  <div class="card-body">
    <?php if ($customerMessage !== ''): ?><div class="box bg-success"><?= htmlspecialchars($customerMessage, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($customerError !== ''): ?><div class="box bg-danger"><?= htmlspecialchars($customerError, ENT_QUOTES); ?></div><?php endif; ?>
    <div class="row">
      <div class="col-6 pd-t-5 pd-b-5"><div class="input-group">
        <div class="input-group-6 col-box-6"><input id="customerSearch" type="text" style="padding:5.8px" class="group-item group-item-l" placeholder="<?= $_search ?>"></div>
        <div class="input-group-6 col-box-6"><select id="customerServiceFilter" class="group-item group-item-r"><option value="all">Layanan: Semua</option><option value="hotspot">Hotspot</option><option value="pppoe">PPPoE</option></select></div>
      </div></div>
      <div class="col-6 text-right"><button id="customerReset" type="button" class="btn bg-secondary"><i class="fa fa-refresh"></i> Reset Filter</button><?php if (mikhmonIsAdmin() || mikhmonIsMitra()): ?><a class="btn bg-primary" href="./?customer=service-add&session=<?= rawurlencode($session); ?>"><i class="fa fa-link"></i> Tambah Layanan</a><?php endif; ?></div>
    </div>
    <style>
      #dataTable .customer-service-select { min-width:115px; }
      #dataTable .customer-service-total { text-align:center; font-weight:bold; }
      #dataTable .customer-username-cell { min-width:145px; font-weight:bold; }
      #dataTable .customer-profile-cell { min-width:180px; color:#888; font-size:12px; white-space:normal; }
    </style>
    <div class="overflow box-bordered" style="max-height:65vh"><table id="dataTable" class="table table-bordered table-hover text-nowrap">
      <thead><tr><th>No</th><th>Nama Pelanggan</th><th>Nomor HP</th><th>Alamat</th><th>Jumlah Layanan</th><th>Layanan</th><th>Username</th><th>Profile</th><th>Mitra</th><th>Aksi</th></tr></thead><tbody>
      <?php foreach ($customers as $customerIndex => $customerRow): ?>
        <?php
          $customerServices = mikhmonCustomerServices($customerRow);
          $customerUsername = implode(', ', array_map(function ($item) { return $item['username']; }, $customerServices));
          $customerSearchData = implode(' ', array_map(function ($item) { return $item['username'] . ' ' . $item['profile']; }, $customerServices));
          $firstCustomerService = isset($customerServices[0]) ? $customerServices[0] : array('username' => '', 'profile' => '');
        ?>
        <tr class="customer-row" data-search="<?= htmlspecialchars(strtolower($customerSearchData), ENT_QUOTES); ?>" data-service="<?= htmlspecialchars(strtolower(implode(',', array_map(function ($item) { return $item['service']; }, $customerServices))), ENT_QUOTES); ?>"><td><?= $customerIndex + 1; ?></td><td><?= htmlspecialchars(isset($customerRow['name']) ? $customerRow['name'] : '', ENT_QUOTES); ?></td><td><?= htmlspecialchars(isset($customerRow['phone']) ? $customerRow['phone'] : '', ENT_QUOTES); ?></td><td><?= htmlspecialchars(isset($customerRow['address']) ? $customerRow['address'] : '', ENT_QUOTES); ?></td><td class="customer-service-total"><?= count($customerServices); ?></td><td><select class="form-control customer-service-select"><?php foreach ($customerServices as $serviceIndex => $customerService): ?><option value="<?= $serviceIndex; ?>" data-type="<?= htmlspecialchars($customerService['service'], ENT_QUOTES); ?>" data-username="<?= htmlspecialchars($customerService['username'], ENT_QUOTES); ?>" data-profile="<?= htmlspecialchars($customerService['profile'], ENT_QUOTES); ?>"><?= strtoupper(htmlspecialchars($customerService['service'], ENT_QUOTES)); ?></option><?php endforeach; ?></select></td><td class="customer-username-cell"><?= htmlspecialchars($firstCustomerService['username'], ENT_QUOTES); ?></td><td class="customer-profile-cell"><?= htmlspecialchars($firstCustomerService['profile'] !== '' ? $firstCustomerService['profile'] : 'Profile belum diatur', ENT_QUOTES); ?></td><td><?php if (mikhmonIsAdmin()): ?><form method="post" style="min-width:160px"><input type="hidden" name="customer_action" value="assign_mitra"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($customerRow['id'], ENT_QUOTES); ?>"><select class="form-control" name="mitra_id" onchange="this.form.submit()"><option value="">Belum ditetapkan</option><?php foreach ($mitras as $mitra): ?><option value="<?= htmlspecialchars($mitra['id'], ENT_QUOTES); ?>"<?= isset($customerRow['mitra_id']) && $customerRow['mitra_id'] === $mitra['id'] ? ' selected' : ''; ?>><?= htmlspecialchars($mitra['name'], ENT_QUOTES); ?></option><?php endforeach; ?></select></form><?php else: ?><?= htmlspecialchars(mikhmonUserName(), ENT_QUOTES); ?><?php endif; ?></td>
        <td><a class="btn bg-primary" href="./?customer=identity-edit&customer-id=<?= rawurlencode($customerRow['id']); ?>&session=<?= rawurlencode($session); ?>"><i class="fa fa-edit"></i> Edit Identitas</a> <a class="btn bg-secondary" href="./?customer=service-add&customer-id=<?= rawurlencode($customerRow['id']); ?>&session=<?= rawurlencode($session); ?>"><i class="fa fa-plus"></i> Layanan</a> <button type="button" class="btn bg-danger customer-delete-button" data-customer-id="<?= htmlspecialchars($customerRow['id'], ENT_QUOTES); ?>" data-customer-name="<?= htmlspecialchars(isset($customerRow['name']) ? $customerRow['name'] : '', ENT_QUOTES); ?>" data-customer-username="<?= htmlspecialchars($customerUsername, ENT_QUOTES); ?>"><i class="fa fa-trash"></i> Hapus</button></td>
      </tr><?php endforeach; ?>
      <?php if (!$customers): ?><tr class="customer-info-row"><td colspan="10" class="text-center"><?= mikhmonIsMitra() ? 'Belum ada pelanggan yang ditetapkan kepada Anda.' : 'Belum ada data pelanggan.'; ?></td></tr><?php endif; ?>
      <tr id="customerNoResults" style="display:none"><td colspan="10" class="text-center">Data pelanggan tidak ditemukan.</td></tr>
      </tbody></table></div>
  </div>
</div></div></div>
<div id="customerDeleteModal" style="display:none;position:fixed;z-index:1000;inset:0;background:rgba(0,0,0,.45);padding:12% 20px 20px">
  <div class="card" style="max-width:460px;margin:auto">
    <div class="card-header"><h3><i class="fa fa-trash"></i> Hapus Pelanggan</h3></div>
    <div class="card-body">
      <p id="customerDeleteText">Pilih jenis penghapusan.</p>
      <form method="post" id="customerDeleteForm"><input type="hidden" name="customer_id" id="customerDeleteId"><input type="hidden" name="customer_action" id="customerDeleteAction" value="delete">
        <button type="submit" class="btn bg-warning" onclick="return customerDeleteChoice('delete');"><i class="fa fa-database"></i> Hapus Data Saja</button>
        <button type="submit" class="btn bg-danger" onclick="return customerDeleteChoice('delete_all');"><i class="fa fa-trash"></i> Hapus Semua</button>
        <button type="button" class="btn bg-secondary" onclick="closeCustomerDelete()">Batal</button>
      </form>
    </div>
  </div>
</div>
<script>
$(function() {
  function showSelectedCustomerAccount(select) {
    var option = $(select).find('option:selected');
    var row = $(select).closest('tr');
    row.find('.customer-username-cell').text(option.data('username') || '-');
    row.find('.customer-profile-cell').text(option.data('profile') || 'Profile belum diatur');
  }
  $('.customer-service-select').on('change', function() {
    showSelectedCustomerAccount(this);
  });
  function filterCustomers() {
    var search = $('#customerSearch').val().toLowerCase();
    var service = $('#customerServiceFilter').val();
    var visible = 0;
    $('.customer-row').each(function() {
      var row = $(this);
      var searchableText = row.text().toLowerCase() + ' ' + String(row.data('search') || '');
      var matchesSearch = searchableText.indexOf(search) > -1;
      var matchesService = service === 'all' || String(row.data('service')).split(',').indexOf(service) !== -1;
      if (matchesService && service !== 'all') {
        var serviceSelect = row.find('.customer-service-select');
        var matchingOption = serviceSelect.find('option[data-type="' + service + '"]').first();
        if (matchingOption.length) {
          serviceSelect.val(matchingOption.val());
          showSelectedCustomerAccount(serviceSelect[0]);
        }
      }
      var show = matchesSearch && matchesService;
      row.toggle(show);
      if (show) visible++;
    });
    $('#customerVisibleCount').text(visible);
    $('#customerNoResults').toggle(visible === 0 && $('.customer-row').length > 0);
  }
  $('#customerSearch').on('input', filterCustomers);
  $('#customerServiceFilter').on('change', filterCustomers);
  $('#customerReset').on('click', function() {
    $('#customerSearch').val('');
    $('#customerServiceFilter').val('all');
    filterCustomers();
  });
  filterCustomers();
  $('.customer-delete-button').on('click', function() {
    $('#customerDeleteId').val($(this).data('customer-id'));
    $('#customerDeleteText').text('Hapus data pelanggan "' + $(this).data('customer-name') + '" saja, atau sekaligus user MikroTik "' + ($(this).data('customer-username') || '-') + '"?');
    $('#customerDeleteModal').show();
  });
});
function closeCustomerDelete() { $('#customerDeleteModal').hide(); }
function customerDeleteChoice(action) {
  if (action === 'delete_all' && !confirm('Hapus data pelanggan dan user MikroTik sekaligus? Tindakan ini tidak dapat dibatalkan tanpa restore backup.')) return false;
  $('#customerDeleteAction').val(action);
  return true;
}
</script>
