<?php
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
}

include_once('./include/database.php');
include_once('./lib/fonnte.php');
include_once('./lib/billing_automation.php');

$customerMessage = '';
$customerError = '';

function customerListApiError($response) {
  if (!is_array($response)) return '';
  foreach (array('!trap', '!fatal') as $type) {
    if (isset($response[$type][0]['message'])) return $response[$type][0]['message'];
  }
  return '';
}

function customerListDueTimestamp($value) {
  $value = strtolower(trim((string) $value));
  if ($value === '') return 0;
  $months = array('jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12);
  if (preg_match('/^([a-z]{3})\/(\d{1,2})(?:\/(\d{4}))?(?:\s+(\d{1,2}:\d{2}:\d{2}))?$/', $value, $matches) && isset($months[$matches[1]])) {
    $year = !empty($matches[3]) ? (int) $matches[3] : (int) date('Y');
    $time = !empty($matches[4]) ? $matches[4] : '00:00:00';
    $timestamp = strtotime(sprintf('%04d-%02d-%02d %s', $year, $months[$matches[1]], (int) $matches[2], $time));
    if (empty($matches[3]) && $timestamp < time() - 86400) $timestamp = strtotime('+1 year', $timestamp);
    return $timestamp ?: 0;
  }
  $timestamp = strtotime($value);
  return $timestamp ?: 0;
}

function customerListServiceIsIsolated($service, $routerUsers) {
  $type = ($service['service'] ?? '') === 'pppoe' ? 'pppoe' : 'hotspot';
  $username = (string) ($service['username'] ?? '');
  if ($username === '' || !isset($routerUsers[$type][$username])) return null;
  $user = $routerUsers[$type][$username];
  $disabled = isset($user['disabled']) && in_array($user['disabled'], array('true', 'yes'), true);
  $limited = $type === 'hotspot' && isset($user['limit-uptime']) && $user['limit-uptime'] === '1s';
  return $disabled || $limited;
}

if (isset($_GET['created']) && $_GET['created'] === '1') {
  $customerMessage = 'Pelanggan dan user MikroTik berhasil dibuat.';
} elseif (isset($_GET['updated']) && $_GET['updated'] === '1') {
  $customerMessage = 'Data pelanggan dan user MikroTik berhasil diperbarui.';
} elseif (isset($_GET['service-added']) && $_GET['service-added'] === '1') {
  $customerMessage = 'Layanan berhasil dikaitkan ke pelanggan.';
} elseif (isset($_GET['service-updated']) && $_GET['service-updated'] === '1') {
  $customerMessage = 'Layanan pelanggan berhasil diperbarui.';
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
  } elseif ($action === 'delete' || $action === 'delete_identity') {
    if (!$actionCustomer || !mikhmonCanManageCustomer($actionCustomer)) {
      $customerError = 'Anda tidak berhak menghapus pelanggan ini.';
    } elseif (mikhmonDeleteCustomer($session, $actionCustomerId)) {
      $customerMessage = 'Identitas pelanggan berhasil dihapus. Layanan MikroTik tetap tersedia.';
    } else {
      $customerError = 'Data pelanggan tidak ditemukan.';
    }
  } elseif ($action === 'delete_service' || $action === 'delete_all') {
    $deleteId = $actionCustomerId;
    $deleteCustomer = $actionCustomer;
    $deleteServiceId = isset($_POST['service_id']) ? (string) $_POST['service_id'] : '';
    $deleteServices = mikhmonCustomerServices($deleteCustomer ?: array());
    if ($action === 'delete_service') {
      $selectedService = array();
      foreach ($deleteServices as $candidateService) if ((string) ($candidateService['id'] ?? '') === $deleteServiceId) { $selectedService = $candidateService; break; }
      $deleteServices = $selectedService ? array($selectedService) : array();
    }
    if (!$deleteCustomer) {
      $customerError = 'Data pelanggan tidak ditemukan.';
    } elseif (!mikhmonCanManageCustomer($deleteCustomer)) {
      $customerError = 'Anda tidak berhak menghapus pelanggan ini.';
    } elseif ($action === 'delete_service' && !$deleteServices) {
      $customerError = 'Layanan pelanggan tidak ditemukan.';
    } elseif (empty($routerConnected)) {
      $customerError = 'Router MikroTik tidak terhubung. Data tidak dihapus.';
    } else {
      $deleteOk = true;
      foreach ($deleteServices as $deleteService) {
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
        $saved = $action === 'delete_all'
          ? mikhmonDeleteCustomer($session, $deleteId)
          : mikhmonDeleteCustomerService($session, $deleteId, $deleteServiceId);
        if ($saved) {
          if ($action === 'delete_service') {
            $schedulerName = 'mikhmon-customer-' . substr(md5((string) $deleteId), 0, 12);
            $schedulerRows = $API->comm('/system/scheduler/print', array('?name' => $schedulerName));
            if (customerListApiError($schedulerRows) === '') foreach ((array) $schedulerRows as $schedulerRow) if (isset($schedulerRow['.id'])) $API->comm('/system/scheduler/remove', array('.id' => $schedulerRow['.id']));
          }
          $customerMessage = $action === 'delete_all' ? 'Identitas pelanggan dan seluruh layanan berhasil dihapus.' : 'Layanan pelanggan berhasil dihapus.';
        } else {
          $customerError = 'User MikroTik sudah dihapus, tetapi data pelanggan gagal diperbarui.';
        }
      }
    }
  }
}

$customers = array_values(array_filter(mikhmonVisibleCustomers($session), function ($customer) { return count(mikhmonCustomerServices($customer)) > 0; }));
$mitras = mikhmonIsAdmin() ? mikhmonGetUsers('mitra', $session) : array();
$customerInvoiceCandidates = array();
foreach (mikhmonGetInvoices($session) as $invoice) {
  $customerId = (string) ($invoice['customer_id'] ?? '');
  $status = (string) ($invoice['status'] ?? '');
  if ($customerId === '' || ($status !== 'paid' && $status !== 'unpaid')) continue;
  if (!isset($customerInvoiceCandidates[$customerId])) $customerInvoiceCandidates[$customerId] = array('paid' => array(), 'unpaid' => array());
  $sortAt = $status === 'paid' ? (int) ($invoice['paid_at'] ?? $invoice['created_at'] ?? 0) : (int) ($invoice['created_at'] ?? 0);
  $current = $customerInvoiceCandidates[$customerId][$status];
  $currentSortAt = $status === 'paid' ? (int) ($current['paid_at'] ?? $current['created_at'] ?? 0) : (int) ($current['created_at'] ?? 0);
  if (!$current || $sortAt >= $currentSortAt) $customerInvoiceCandidates[$customerId][$status] = $invoice;
}
$customerInvoices = array();
foreach ($customerInvoiceCandidates as $customerId => $candidates) {
  $paid = $candidates['paid']; $unpaid = $candidates['unpaid'];
  if (!$unpaid) { if ($paid) $customerInvoices[$customerId] = $paid; continue; }
  $unpaidDue = customerListDueTimestamp($unpaid['due_date'] ?? '');
  $unpaidIsDue = $unpaidDue <= 0 || $unpaidDue <= time();
  $customerInvoices[$customerId] = $paid && !$unpaidIsDue ? $paid : $unpaid;
}
$customerFonnteConfig = mikhmonFonnteReadConfig();
$customerRouterUsers = array('hotspot' => array(), 'pppoe' => array());
if (!empty($routerConnected)) {
  foreach (array('hotspot' => '/ip/hotspot/user/print', 'pppoe' => '/ppp/secret/print') as $serviceType => $command) {
    $rows = $API->comm($command);
    if (is_array($rows) && customerListApiError($rows) === '') foreach ($rows as $row) {
      if (is_array($row) && isset($row['name'])) $customerRouterUsers[$serviceType][(string) $row['name']] = $row;
    }
  }
}
?>
<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3><i class="fa fa-list"></i> Daftar Pelanggan <span style="font-size:14px">&nbsp;|&nbsp; <span id="customerVisibleCount"><?= count($customers); ?></span> pelanggan</span></h3></div>
  <div class="card-body">
    <?php if ($customerMessage !== ''): ?><div class="box bg-success"><?= htmlspecialchars($customerMessage, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($customerError !== ''): ?><div class="box bg-danger"><?= htmlspecialchars($customerError, ENT_QUOTES); ?></div><?php endif; ?>
    <style>
      .customer-toolbar { display:flex; align-items:stretch; justify-content:space-between; gap:10px; margin:5px 0 10px; }
      .customer-filter-controls { display:flex; align-items:stretch; flex:1; gap:8px; min-width:0; }
      .customer-toolbar-actions { display:flex; align-items:stretch; justify-content:flex-end; gap:8px; }
      .customer-toolbar-control { height:34px; min-height:34px; margin:0; box-sizing:border-box; }
      #customerSearch { flex:1; min-width:220px; }
      #customerServiceFilter, #customerStatusFilter { width:155px; }
      .customer-toolbar .btn { display:inline-flex; align-items:center; justify-content:center; gap:5px; min-height:34px; margin:0; box-sizing:border-box; }
      #dataTable .customer-service-select { min-width:115px; }
      #dataTable .customer-service-total { text-align:center; font-weight:bold; }
      #dataTable .customer-username-cell { min-width:145px; font-weight:bold; }
      #dataTable .customer-password-cell { min-width:105px; text-align:center; font-weight:bold; cursor:pointer; user-select:none; }
      #dataTable .customer-password-cell i { margin-left:5px; color:#888; }
      #dataTable .customer-profile-cell { min-width:180px; color:#888; font-size:12px; white-space:normal; }
      #dataTable .customer-isolation-date { min-width:135px; text-align:center; }
      #dataTable .customer-status { min-width:85px; text-align:center; font-weight:bold; }
      @media(max-width:900px) {
        .customer-toolbar { flex-direction:column; }
        .customer-toolbar-actions { justify-content:stretch; }
        .customer-toolbar-actions .btn { flex:1; }
      }
      @media(max-width:600px) {
        .customer-filter-controls, .customer-toolbar-actions { flex-direction:column; }
        #customerSearch, #customerServiceFilter, #customerStatusFilter { width:100%; min-width:0; }
      }
    </style>
    <div class="customer-toolbar">
      <div class="customer-filter-controls">
        <input id="customerSearch" type="text" class="form-control customer-toolbar-control" placeholder="<?= $_search ?>">
        <select id="customerServiceFilter" class="form-control customer-toolbar-control"><option value="all">Layanan: Semua</option><option value="hotspot">Hotspot</option><option value="pppoe">PPPoE</option></select>
        <select id="customerStatusFilter" class="form-control customer-toolbar-control"><option value="all">Status: Semua</option><option value="active">Aktif</option><option value="isolir">Isolir</option></select>
      </div>
      <div class="customer-toolbar-actions"><button id="customerReset" type="button" class="btn bg-secondary"><i class="fa fa-refresh"></i> Reset Filter</button><?php if (mikhmonIsAdmin() || mikhmonIsMitra()): ?><a class="btn bg-primary" href="./?customer=service-add&session=<?= rawurlencode($session); ?>"><i class="fa fa-link"></i> Tambah Layanan</a><?php endif; ?></div>
    </div>
    <div class="overflow box-bordered" style="max-height:65vh"><table id="dataTable" class="table table-bordered table-hover text-nowrap">
      <thead><tr><th>No</th><th>Nama Pelanggan</th><th>Nomor HP</th><th>Alamat</th><th>Jumlah Layanan</th><th>Layanan</th><th>Username</th><th>Password</th><th>Profile</th><th>Tanggal Isolir</th><th>Status</th><th>Mitra</th><th>Aksi</th></tr></thead><tbody>
      <?php foreach ($customers as $customerIndex => $customerRow): ?>
        <?php
          $customerServices = mikhmonCustomerServices($customerRow);
          $customerUsername = implode(', ', array_map(function ($item) { return $item['username']; }, $customerServices));
          $customerSearchData = implode(' ', array_map(function ($item) { return $item['username'] . ' ' . $item['profile']; }, $customerServices));
          $firstCustomerService = isset($customerServices[0]) ? $customerServices[0] : array('username' => '', 'profile' => '');
          $customerServicePasswords = array();
          foreach ($customerServices as $serviceIndex => $customerService) {
            $serviceType = ($customerService['service'] ?? '') === 'pppoe' ? 'pppoe' : 'hotspot';
            $serviceUsername = (string) ($customerService['username'] ?? '');
            $customerServicePasswords[$serviceIndex] = isset($customerRouterUsers[$serviceType][$serviceUsername]['password']) ? (string) $customerRouterUsers[$serviceType][$serviceUsername]['password'] : '';
          }
          $firstCustomerPassword = isset($customerServicePasswords[0]) ? $customerServicePasswords[0] : '';
          $customerId = (string) ($customerRow['id'] ?? '');
          $customerInvoice = isset($customerInvoices[$customerId]) ? $customerInvoices[$customerId] : array();
          $isolatedAt = (int) ($customerInvoice['automation']['isolated_at'] ?? 0);
          $isolationTimestamp = $isolatedAt;
          if ($isolationTimestamp <= 0) {
            $billingDueDate = ($customerInvoice['status'] ?? '') === 'paid' && !empty($customerInvoice['next_due_date'])
              ? $customerInvoice['next_due_date']
              : ($customerInvoice['due_date'] ?? '');
            if ($billingDueDate === '') $billingDueDate = date('Y-m-d H:i:s', mikhmonBillingAutomationUpcomingDueTimestamp());
            $dueTimestamp = customerListDueTimestamp($billingDueDate);
            if ($dueTimestamp > 0 && (empty($customerFonnteConfig['automation_enabled']) || !empty($customerFonnteConfig['isolation_enabled']))) {
              $graceDays = !empty($customerFonnteConfig['automation_enabled']) ? (int) ($customerFonnteConfig['grace_days'] ?? 0) : 0;
              $isolationTimestamp = $dueTimestamp + ($graceDays * 86400);
            }
          }
          $customerIsIsolated = $isolatedAt > 0;
          if (!empty($routerConnected)) foreach ($customerServices as $customerService) {
            $serviceIsIsolated = customerListServiceIsIsolated($customerService, $customerRouterUsers);
            if ($serviceIsIsolated === null) continue;
            if ($serviceIsIsolated) { $customerIsIsolated = true; break; }
            $customerIsIsolated = false;
          }
          $customerStatusText = $customerIsIsolated ? 'Isolir' : 'Aktif';
          $customerStatusClass = $customerIsIsolated ? 'text-danger' : 'text-success';
        ?>
        <tr class="customer-row" data-search="<?= htmlspecialchars(strtolower($customerSearchData), ENT_QUOTES); ?>" data-service="<?= htmlspecialchars(strtolower(implode(',', array_map(function ($item) { return $item['service']; }, $customerServices))), ENT_QUOTES); ?>" data-status="<?= $customerIsIsolated ? 'isolir' : 'active'; ?>"><td><?= $customerIndex + 1; ?></td><td><?= htmlspecialchars(isset($customerRow['name']) ? $customerRow['name'] : '', ENT_QUOTES); ?></td><td><?= htmlspecialchars(isset($customerRow['phone']) ? $customerRow['phone'] : '', ENT_QUOTES); ?></td><td><?= htmlspecialchars(isset($customerRow['address']) ? $customerRow['address'] : '', ENT_QUOTES); ?></td><td class="customer-service-total"><?= count($customerServices); ?></td><td><select class="form-control customer-service-select"><?php foreach ($customerServices as $serviceIndex => $customerService): ?><option value="<?= $serviceIndex; ?>" data-service-id="<?= htmlspecialchars($customerService['id'], ENT_QUOTES); ?>" data-type="<?= htmlspecialchars($customerService['service'], ENT_QUOTES); ?>" data-username="<?= htmlspecialchars($customerService['username'], ENT_QUOTES); ?>" data-password="<?= htmlspecialchars(isset($customerServicePasswords[$serviceIndex]) ? $customerServicePasswords[$serviceIndex] : '', ENT_QUOTES); ?>" data-profile="<?= htmlspecialchars($customerService['profile'], ENT_QUOTES); ?>"><?= strtoupper(htmlspecialchars($customerService['service'], ENT_QUOTES)); ?></option><?php endforeach; ?></select></td><td class="customer-username-cell"><?= htmlspecialchars($firstCustomerService['username'], ENT_QUOTES); ?></td><td class="customer-password-cell" data-password="<?= htmlspecialchars($firstCustomerPassword, ENT_QUOTES); ?>" data-pinned="false" role="button" tabindex="0" aria-label="Tampilkan password" aria-pressed="false" title="Arahkan kursor atau klik untuk melihat password"><span class="customer-password-value">******</span><i class="fa fa-eye"></i></td><td class="customer-profile-cell"><?= htmlspecialchars($firstCustomerService['profile'] !== '' ? $firstCustomerService['profile'] : 'Profile belum diatur', ENT_QUOTES); ?></td><td class="customer-isolation-date"><?= $isolationTimestamp > 0 ? htmlspecialchars(date('d-m-Y H:i', $isolationTimestamp), ENT_QUOTES) : '-'; ?></td><td class="customer-status <?= $customerStatusClass; ?>"><i class="fa <?= $customerIsIsolated ? 'fa-ban' : 'fa-check-circle'; ?>"></i> <?= $customerStatusText; ?></td><td><?php if (mikhmonIsAdmin()): ?><form method="post" style="min-width:160px"><input type="hidden" name="customer_action" value="assign_mitra"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($customerRow['id'], ENT_QUOTES); ?>"><select class="form-control" name="mitra_id" onchange="this.form.submit()"><option value="">Belum ditetapkan</option><?php foreach ($mitras as $mitra): ?><option value="<?= htmlspecialchars($mitra['id'], ENT_QUOTES); ?>"<?= isset($customerRow['mitra_id']) && $customerRow['mitra_id'] === $mitra['id'] ? ' selected' : ''; ?>><?= htmlspecialchars($mitra['name'], ENT_QUOTES); ?></option><?php endforeach; ?></select></form><?php else: ?><?= htmlspecialchars(mikhmonUserName(), ENT_QUOTES); ?><?php endif; ?></td>
        <td><a class="btn bg-primary" href="./?customer=identity-edit&customer-id=<?= rawurlencode($customerRow['id']); ?>&session=<?= rawurlencode($session); ?>"><i class="fa fa-edit"></i> Edit Identitas</a> <a class="btn bg-warning customer-service-edit-link" data-customer-id="<?= htmlspecialchars($customerRow['id'], ENT_QUOTES); ?>" href="./?customer=service-edit&customer-id=<?= rawurlencode($customerRow['id']); ?>&service-id=<?= rawurlencode($firstCustomerService['id'] ?? ''); ?>&session=<?= rawurlencode($session); ?>"><i class="fa fa-pencil"></i> Edit Layanan</a> <a class="btn bg-secondary" href="./?customer=service-add&customer-id=<?= rawurlencode($customerRow['id']); ?>&session=<?= rawurlencode($session); ?>"><i class="fa fa-plus"></i> Layanan</a> <button type="button" class="btn bg-danger customer-delete-button" data-customer-id="<?= htmlspecialchars($customerRow['id'], ENT_QUOTES); ?>" data-customer-name="<?= htmlspecialchars(isset($customerRow['name']) ? $customerRow['name'] : '', ENT_QUOTES); ?>" data-customer-username="<?= htmlspecialchars($customerUsername, ENT_QUOTES); ?>"><i class="fa fa-trash"></i> Hapus</button></td>
      </tr><?php endforeach; ?>
      <?php if (!$customers): ?><tr class="customer-info-row"><td colspan="13" class="text-center"><?= mikhmonIsMitra() ? 'Belum ada pelanggan yang ditetapkan kepada Anda.' : 'Belum ada data pelanggan.'; ?></td></tr><?php endif; ?>
      <tr id="customerNoResults" style="display:none"><td colspan="13" class="text-center">Data pelanggan tidak ditemukan.</td></tr>
      </tbody></table></div>
  </div>
</div></div></div>
<div id="customerDeleteModal" style="display:none;position:fixed;z-index:1000;inset:0;background:rgba(0,0,0,.45);padding:12% 20px 20px">
  <div class="card" style="max-width:520px;margin:auto">
    <div class="card-header"><h3><i class="fa fa-trash"></i> Hapus Pelanggan</h3></div>
    <div class="card-body">
      <p id="customerDeleteText">Pilih jenis penghapusan.</p>
      <form method="post" id="customerDeleteForm"><input type="hidden" name="customer_id" id="customerDeleteId"><input type="hidden" name="service_id" id="customerDeleteServiceId"><input type="hidden" name="customer_action" id="customerDeleteAction" value="delete_identity">
        <button type="submit" class="btn bg-warning" onclick="return customerDeleteChoice('delete_identity');"><i class="fa fa-user-times"></i> Hapus Identitas Pelanggan</button>
        <button type="submit" class="btn bg-danger" onclick="return customerDeleteChoice('delete_service');"><i class="fa fa-link"></i> Hapus Layanan</button>
        <button type="submit" class="btn bg-danger" onclick="return customerDeleteChoice('delete_all');"><i class="fa fa-trash"></i> Hapus Semua</button>
        <button type="button" class="btn bg-secondary" onclick="closeCustomerDelete()">Batal</button>
      </form>
    </div>
  </div>
</div>
<script>
$(function() {
  function setCustomerPasswordVisibility(cell, visible) {
    var password = cell.attr('data-password') || '';
    cell.find('.customer-password-value').text(visible ? (password || '-') : '******');
    cell.find('i').toggleClass('fa-eye', !visible).toggleClass('fa-eye-slash', visible);
  }
  function showSelectedCustomerAccount(select) {
    var option = $(select).find('option:selected');
    var row = $(select).closest('tr');
    var passwordCell = row.find('.customer-password-cell');
    row.find('.customer-username-cell').text(option.data('username') || '-');
    row.find('.customer-profile-cell').text(option.data('profile') || 'Profile belum diatur');
    row.find('.customer-service-edit-link').attr('href', './?customer=service-edit&customer-id=' + encodeURIComponent(row.find('.customer-service-edit-link').data('customer-id')) + '&service-id=' + encodeURIComponent(option.attr('data-service-id') || '') + '&session=<?= rawurlencode($session); ?>');
    passwordCell.attr('data-password', option.attr('data-password') || '').attr('data-pinned', 'false').attr('aria-pressed', 'false');
    setCustomerPasswordVisibility(passwordCell, false);
  }
  $('.customer-service-select').on('change', function() {
    showSelectedCustomerAccount(this);
  });
  $('.customer-password-cell')
    .on('mouseenter focus', function() { setCustomerPasswordVisibility($(this), true); })
    .on('mouseleave', function() { if ($(this).attr('data-pinned') !== 'true') setCustomerPasswordVisibility($(this), false); })
    .on('blur', function() { $(this).attr('data-pinned', 'false').attr('aria-pressed', 'false'); setCustomerPasswordVisibility($(this), false); })
    .on('click', function() {
      var cell = $(this), pinned = cell.attr('data-pinned') !== 'true';
      cell.attr('data-pinned', pinned ? 'true' : 'false').attr('aria-pressed', pinned ? 'true' : 'false');
      setCustomerPasswordVisibility(cell, pinned);
    })
    .on('keydown', function(event) { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); $(this).trigger('click'); } });
  function filterCustomers() {
    var search = $('#customerSearch').val().toLowerCase();
    var service = $('#customerServiceFilter').val();
    var status = $('#customerStatusFilter').val();
    var visible = 0;
    $('.customer-row').each(function() {
      var row = $(this);
      var searchableText = row.text().toLowerCase() + ' ' + String(row.data('search') || '');
      var matchesSearch = searchableText.indexOf(search) > -1;
      var matchesService = service === 'all' || String(row.data('service')).split(',').indexOf(service) !== -1;
      var matchesStatus = status === 'all' || String(row.data('status')) === status;
      if (matchesService && service !== 'all') {
        var serviceSelect = row.find('.customer-service-select');
        var matchingOption = serviceSelect.find('option[data-type="' + service + '"]').first();
        if (matchingOption.length) {
          serviceSelect.val(matchingOption.val());
          showSelectedCustomerAccount(serviceSelect[0]);
        }
      }
      var show = matchesSearch && matchesService && matchesStatus;
      row.toggle(show);
      if (show) visible++;
    });
    $('#customerVisibleCount').text(visible);
    $('#customerNoResults').toggle(visible === 0 && $('.customer-row').length > 0);
  }
  $('#customerSearch').on('input', filterCustomers);
  $('#customerServiceFilter').on('change', filterCustomers);
  $('#customerStatusFilter').on('change', filterCustomers);
  $('#customerReset').on('click', function() {
    $('#customerSearch').val('');
    $('#customerServiceFilter').val('all');
    $('#customerStatusFilter').val('all');
    filterCustomers();
  });
  filterCustomers();
  $('.customer-delete-button').on('click', function() {
    var button = $(this), row = button.closest('tr'), option = row.find('.customer-service-select option:selected');
    $('#customerDeleteId').val(button.data('customer-id'));
    $('#customerDeleteServiceId').val(option.attr('data-service-id') || '');
    $('#customerDeleteText').html('Pilih tindakan untuk pelanggan <strong>"' + $('<div>').text(button.data('customer-name')).html() + '"</strong>.<br>Layanan terpilih: <strong>' + $('<div>').text(option.data('type') ? option.data('type').toUpperCase() + ' / ' + (option.data('username') || '-') : '-').html() + '</strong>');
    $('#customerDeleteModal').show();
  });
});
function closeCustomerDelete() { $('#customerDeleteModal').hide(); }
function customerDeleteChoice(action) {
  var message = action === 'delete_identity'
    ? 'Hapus identitas pelanggan dari database? Layanan MikroTik tetap tersedia.'
    : (action === 'delete_service' ? 'Hapus layanan terpilih dari database dan MikroTik?' : 'Hapus identitas pelanggan beserta seluruh layanan MikroTik? Tindakan ini tidak dapat dibatalkan tanpa restore backup.');
  if (!confirm(message)) return false;
  $('#customerDeleteAction').val(action);
  return true;
}
</script>
