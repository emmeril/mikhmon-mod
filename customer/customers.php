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
  if (!is_array($response)) {
    return '';
  }
  foreach (array('!trap', '!fatal') as $errorType) {
    if (isset($response[$errorType][0]['message'])) {
      return $response[$errorType][0]['message'];
    }
  }
  return '';
}

if (isset($_GET['created']) && $_GET['created'] === '1') {
  $customerMessage = 'Pelanggan dan user MikroTik berhasil dibuat.';
} elseif (isset($_GET['updated']) && $_GET['updated'] === '1') {
  $customerMessage = 'Data pelanggan dan user MikroTik berhasil diperbarui.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = isset($_POST['customer_action']) ? $_POST['customer_action'] : '';
  if ($action === 'delete') {
    if (mikhmonDeleteCustomer($session, isset($_POST['customer_id']) ? $_POST['customer_id'] : '')) {
      $customerMessage = 'Data pelanggan berhasil dihapus.';
    } else {
      $customerError = 'Data pelanggan tidak ditemukan.';
    }
  } elseif ($action === 'enable') {
    $enableId = isset($_POST['customer_id']) ? (string) $_POST['customer_id'] : '';
    $enableCustomer = array();
    foreach (mikhmonGetCustomers($session) as $customerRow) {
      if (isset($customerRow['id']) && (string) $customerRow['id'] === $enableId) {
        $enableCustomer = $customerRow;
        break;
      }
    }
    if (empty($routerConnected)) {
      $customerError = 'Router MikroTik tidak terhubung.';
    } elseif (!$enableCustomer || empty($enableCustomer['username'])) {
      $customerError = 'User MikroTik pelanggan tidak ditemukan.';
    } else {
      $enableCommand = isset($enableCustomer['service']) && $enableCustomer['service'] === 'pppoe' ? '/ppp/secret' : '/ip/hotspot/user';
      $enableRows = $API->comm($enableCommand . '/print', array('?name' => $enableCustomer['username']));
      if (customerListApiError($enableRows) !== '') {
        $customerError = 'Gagal membaca user MikroTik.';
      } elseif (!isset($enableRows[0]['.id'])) {
        $customerError = 'User sudah tidak ada di MikroTik dan tidak dapat diaktifkan.';
      } else {
        if ($enableCommand === '/ip/hotspot/user') {
          $enableResponse = $API->comm($enableCommand . '/set', array(
            '.id' => $enableRows[0]['.id'],
            'disabled' => 'no',
            'limit-uptime' => '0',
            'comment' => 'up-' . (isset($enableCustomer['name']) ? $enableCustomer['name'] : ''),
          ));
          if (customerListApiError($enableResponse) === '') {
            $API->comm($enableCommand . '/reset-counters', array('.id' => $enableRows[0]['.id']));
          }
        } else {
          $enableResponse = $API->comm($enableCommand . '/set', array('.id' => $enableRows[0]['.id'], 'disabled' => 'no'));
        }
        $enableError = customerListApiError($enableResponse);
        if ($enableError !== '') {
          $customerError = 'Gagal mengaktifkan user: ' . $enableError;
        } else {
          $customerMessage = 'User pelanggan berhasil diaktifkan.';
        }
      }
    }
  }
}

$customerUsers = array('hotspot' => array(), 'pppoe' => array());
$customerSchedulers = array();
$routerStatusText = '';
if (!empty($routerConnected)) {
  foreach (array('hotspot' => '/ip/hotspot/user/print', 'pppoe' => '/ppp/secret/print') as $serviceKey => $userCommand) {
    $userRows = $API->comm($userCommand);
    if (is_array($userRows) && customerListApiError($userRows) === '') {
      foreach ($userRows as $userRow) {
        if (is_array($userRow) && isset($userRow['name'])) {
          $customerUsers[$serviceKey][(string) $userRow['name']] = $userRow;
        }
      }
    }
  }
  $schedulerRows = $API->comm('/system/scheduler/print');
  if (is_array($schedulerRows) && customerListApiError($schedulerRows) === '') {
    foreach ($schedulerRows as $schedulerRow) {
      if (is_array($schedulerRow) && isset($schedulerRow['name'])) {
        $customerSchedulers[(string) $schedulerRow['name']] = $schedulerRow;
      }
    }
  }
} else {
  $routerStatusText = 'Router tidak terhubung';
}

$customers = mikhmonGetCustomers($session);
?>
<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3><i class="fa fa-address-card"></i> Pelanggan <span style="font-size:14px">&nbsp;|&nbsp; <span id="customerVisibleCount"><?= count($customers); ?></span> pelanggan</span></h3></div>
  <div class="card-body">
    <?php if ($customerMessage !== ''): ?><div class="box bg-success"><?= htmlspecialchars($customerMessage, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($customerError !== ''): ?><div class="box bg-danger"><?= htmlspecialchars($customerError, ENT_QUOTES); ?></div><?php endif; ?>
    <div class="row">
      <div class="col-6 pd-t-5 pd-b-5"><div class="input-group">
        <div class="input-group-4 col-box-4"><input id="customerSearch" type="text" style="padding:5.8px" class="group-item group-item-l" placeholder="<?= $_search ?>"></div>
        <div class="input-group-4 col-box-4"><select id="customerServiceFilter" class="group-item group-item-m"><option value="all">Layanan: Semua</option><option value="hotspot">Hotspot</option><option value="pppoe">PPPoE</option></select></div>
        <div class="input-group-4 col-box-4"><select id="customerStatusFilter" class="group-item group-item-r"><option value="all">Status: Semua</option><option value="active">Aktif</option><option value="expired">Expired</option><option value="missing">Tidak ditemukan</option></select></div>
      </div></div>
      <div class="col-6 text-right"><button id="customerReset" type="button" class="btn bg-secondary"><i class="fa fa-refresh"></i> Reset Filter</button><a class="btn bg-primary" href="./?customer=add&session=<?= rawurlencode($session); ?>"><i class="fa fa-user-plus"></i> Tambah Pelanggan</a></div>
    </div>
    <p><small>Menghapus data pelanggan tidak menghapus user Hotspot/PPPoE di MikroTik.</small></p>
    <div class="overflow box-bordered" style="max-height:65vh"><table id="dataTable" class="table table-bordered table-hover text-nowrap">
      <thead><tr><th>No</th><th>Nama Pelanggan</th><th>Nomor HP</th><th>Alamat</th><th>Layanan</th><th>Username</th><th>Profile</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
      <?php if ($routerStatusText !== ''): ?><tr class="customer-info-row"><td colspan="9" class="text-center text-warning"><?= htmlspecialchars($routerStatusText, ENT_QUOTES); ?>; status user tidak dapat diperbarui.</td></tr><?php endif; ?>
      <?php foreach ($customers as $customerIndex => $customerRow): ?>
        <?php
          $customerService = isset($customerRow['service']) && $customerRow['service'] === 'pppoe' ? 'pppoe' : 'hotspot';
          $customerUsername = isset($customerRow['username']) ? (string) $customerRow['username'] : '';
          $linkedUser = isset($customerUsers[$customerService][$customerUsername]) ? $customerUsers[$customerService][$customerUsername] : array();
          $userMissing = $customerUsername === '' || empty($linkedUser);
          $isDisabled = !$userMissing && isset($linkedUser['disabled']) && $linkedUser['disabled'] === 'true';
          $hotspotExpired = $customerService === 'hotspot' && !$userMissing && isset($linkedUser['limit-uptime']) && $linkedUser['limit-uptime'] === '1s';
          $userExpired = $isDisabled || $hotspotExpired;
          $validityText = '';
          if (!$userMissing && !$userExpired && $customerService === 'hotspot' && !empty($linkedUser['comment']) && substr($linkedUser['comment'], 0, 3) !== 'vc-' && substr($linkedUser['comment'], 0, 3) !== 'up-') {
            $validityText = $linkedUser['comment'];
          } elseif (!$userMissing && !$userExpired && $customerService === 'pppoe') {
            $schedulerName = 'mikhmon-pppoe-' . $customerUsername;
            if (isset($customerSchedulers[$schedulerName]['next-run'])) {
              $validityText = $customerSchedulers[$schedulerName]['next-run'];
            }
          }
          $statusText = $userMissing ? 'Tidak ditemukan' : ($userExpired ? 'Expired' : 'Aktif');
          $statusClass = $userMissing || $userExpired ? 'text-danger' : 'text-success';
          $statusFilter = $userMissing ? 'missing' : ($userExpired ? 'expired' : 'active');
        ?>
        <tr class="customer-row" data-service="<?= htmlspecialchars($customerService, ENT_QUOTES); ?>" data-status="<?= $statusFilter; ?>"><td><?= $customerIndex + 1; ?></td><td><?= htmlspecialchars(isset($customerRow['name']) ? $customerRow['name'] : '', ENT_QUOTES); ?></td><td><?= htmlspecialchars(isset($customerRow['phone']) ? $customerRow['phone'] : '', ENT_QUOTES); ?></td><td><?= htmlspecialchars(isset($customerRow['address']) ? $customerRow['address'] : '', ENT_QUOTES); ?></td><td><?= strtoupper(htmlspecialchars($customerService, ENT_QUOTES)); ?></td><td><?= htmlspecialchars($customerUsername, ENT_QUOTES); ?></td><td><?= htmlspecialchars(isset($customerRow['profile']) ? $customerRow['profile'] : '', ENT_QUOTES); ?></td><td class="<?= $statusClass; ?>"><strong><?= $statusText; ?></strong><?php if ($validityText !== ''): ?><br><small>Validity: <?= htmlspecialchars($validityText, ENT_QUOTES); ?></small><?php endif; ?></td>
        <td><?php if ($userExpired): ?><form method="post" style="display:inline"><input type="hidden" name="customer_action" value="enable"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($customerRow['id'], ENT_QUOTES); ?>"><button class="btn bg-success" type="submit"><i class="fa fa-unlock"></i> Aktifkan</button></form><?php endif; ?> <a class="btn bg-primary" href="./?customer=edit&customer-id=<?= rawurlencode($customerRow['id']); ?>&session=<?= rawurlencode($session); ?>"><i class="fa fa-edit"></i> Edit</a> <form method="post" style="display:inline" onsubmit="return confirm('Hapus data pelanggan ini? User MikroTik tidak akan dihapus.');"><input type="hidden" name="customer_action" value="delete"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($customerRow['id'], ENT_QUOTES); ?>"><button class="btn bg-danger" type="submit"><i class="fa fa-trash"></i> Hapus Data</button></form></td>
      </tr><?php endforeach; ?>
      <?php if (!$customers): ?><tr class="customer-info-row"><td colspan="9" class="text-center">Belum ada data pelanggan.</td></tr><?php endif; ?>
      <tr id="customerNoResults" style="display:none"><td colspan="9" class="text-center">Data pelanggan tidak ditemukan.</td></tr>
      </tbody></table></div>
  </div>
</div></div></div>
<script>
$(function() {
  function filterCustomers() {
    var search = $('#customerSearch').val().toLowerCase();
    var service = $('#customerServiceFilter').val();
    var status = $('#customerStatusFilter').val();
    var visible = 0;
    $('.customer-row').each(function() {
      var row = $(this);
      var matchesSearch = row.text().toLowerCase().indexOf(search) > -1;
      var matchesService = service === 'all' || row.data('service') === service;
      var matchesStatus = status === 'all' || row.data('status') === status;
      var show = matchesSearch && matchesService && matchesStatus;
      row.toggle(show);
      if (show) visible++;
    });
    $('#customerVisibleCount').text(visible);
    $('#customerNoResults').toggle(visible === 0 && $('.customer-row').length > 0);
  }
  $('#customerSearch').on('input', filterCustomers);
  $('#customerServiceFilter, #customerStatusFilter').on('change', filterCustomers);
  $('#customerReset').on('click', function() {
    $('#customerSearch').val('');
    $('#customerServiceFilter, #customerStatusFilter').val('all');
    filterCustomers();
  });
  filterCustomers();
});
</script>
