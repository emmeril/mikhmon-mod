<?php
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
}
if (!mikhmonIsAdmin() && !mikhmonIsBiller()) {
  header('Location:../admin.php?id=login');
  exit;
}

include_once('./include/database.php');
include_once('./ppp/profilemeta.php');

function billingApiError($response) {
  if (!is_array($response)) return '';
  foreach (array('!trap', '!fatal') as $type) {
    if (isset($response[$type][0]['message'])) return $response[$type][0]['message'];
  }
  return '';
}

function billingFindService($customers, $customerId, $serviceId = '') {
  foreach ($customers as $customer) if (isset($customer['id']) && (string)$customer['id'] === (string)$customerId) {
    foreach (mikhmonCustomerServices($customer) as $service) if ($serviceId === '' || (string)$service['id'] === (string)$serviceId) {
      $customer['service_id'] = $service['id']; $customer['service'] = $service['service']; $customer['username'] = $service['username']; $customer['profile'] = $service['profile']; $customer['server'] = $service['server'] ?? 'all'; return $customer;
    }
  }
  return array();
}

function billingLatestInvoice($invoices, $customerId, $serviceId = '', $allowLegacy = false) {
  $latest = array();
  foreach ($invoices as $invoice) {
    if (!isset($invoice['customer_id']) || (string) $invoice['customer_id'] !== (string) $customerId) continue;
    if ($serviceId !== '' && !isset($invoice['service_id']) && !$allowLegacy) continue;
    if ($serviceId !== '' && isset($invoice['service_id']) && (string) $invoice['service_id'] !== (string) $serviceId) continue;
    if (!$latest || (int) $invoice['created_at'] > (int) $latest['created_at']) $latest = $invoice;
  }
  return $latest;
}

function billingProfilePrice($service, $profileName, $hotspotProfiles, $pppoeProfiles) {
  $rows = $service === 'pppoe' ? $pppoeProfiles : $hotspotProfiles;
  foreach ((array) $rows as $profile) {
    if (!isset($profile['name']) || (string) $profile['name'] !== (string) $profileName) continue;
    if ($service === 'pppoe') {
      $meta = pppProfileMetaDecode(isset($profile['comment']) ? $profile['comment'] : '');
      return array('price' => $meta['price'], 'selling_price' => $meta['selling-price']);
    }
    $login = isset($profile['on-login']) ? (string) $profile['on-login'] : '';
    if (preg_match('/,([^,]*),([^,]*),([^,]*),([^,]*),/', $login, $matches)) {
      return array('price' => $matches[2], 'selling_price' => $matches[4]);
    }
  }
  return array('price' => '', 'selling_price' => '');
}

function billingDueDate($service, $username, $user, $schedulers) {
  if (!$user) return '';
  if ($service === 'hotspot') {
    $comment = isset($user['comment']) ? (string) $user['comment'] : '';
    if ($comment !== '' && substr($comment, 0, 3) !== 'vc-' && substr($comment, 0, 3) !== 'up-') return $comment;
    return '';
  }
  $schedulerName = 'mikhmon-pppoe-' . $username;
  return isset($schedulers[$schedulerName]['next-run']) ? $schedulers[$schedulerName]['next-run'] : '';
}

function billingPhone($phone) {
  $phone = preg_replace('/[^0-9]/', '', (string) $phone);
  if (substr($phone, 0, 1) === '0') $phone = '62' . substr($phone, 1);
  return $phone;
}

function billingMessageAmount($amount, $currency) {
  $indoCurrency = isset($GLOBALS['cekindo']['indo']) && in_array($currency, $GLOBALS['cekindo']['indo']);
  return $currency . ' ' . number_format((float) $amount, $indoCurrency ? 0 : 2, $indoCurrency ? ',' : '.', $indoCurrency ? '.' : ',');
}

$customers = mikhmonGetCustomers($session);
$serviceCount = 0; foreach ($customers as $customerRow) $serviceCount += count(mikhmonCustomerServices($customerRow));
$invoices = mikhmonGetInvoices($session);
$hotspotProfiles = array();
$pppoeProfiles = array();
$customerUsers = array('hotspot' => array(), 'pppoe' => array());
$customerSchedulers = array();
$customerError = '';
$customerMessage = '';

if (!empty($routerConnected)) {
  $hotspotProfiles = $API->comm('/ip/hotspot/user/profile/print');
  $pppoeProfiles = $API->comm('/ppp/profile/print');
  foreach (array('hotspot' => '/ip/hotspot/user/print', 'pppoe' => '/ppp/secret/print') as $service => $command) {
    $rows = $API->comm($command);
    if (is_array($rows) && billingApiError($rows) === '') {
      foreach ($rows as $row) {
        if (is_array($row) && isset($row['name'])) $customerUsers[$service][(string) $row['name']] = $row;
      }
    }
  }
  $schedulerRows = $API->comm('/system/scheduler/print');
  if (is_array($schedulerRows) && billingApiError($schedulerRows) === '') {
    foreach ($schedulerRows as $row) {
      if (is_array($row) && isset($row['name'])) $customerSchedulers[(string) $row['name']] = $row;
    }
  }
}
$hotspotProfiles = is_array($hotspotProfiles) ? $hotspotProfiles : array();
$pppoeProfiles = is_array($pppoeProfiles) ? $pppoeProfiles : array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = isset($_POST['billing_action']) ? $_POST['billing_action'] : '';
  $customer = billingFindService($customers, isset($_POST['customer_id']) ? $_POST['customer_id'] : '', isset($_POST['service_id']) ? $_POST['service_id'] : '');
  if ($action === 'create_invoice') {
    $allowLegacyInvoice = $customer && isset($customer['services'][0]['id']) && (string)$customer['services'][0]['id'] === (string)$customer['service_id'];
    $existingInvoice = $customer ? billingLatestInvoice($invoices, $customer['id'], $customer['service_id'], $allowLegacyInvoice) : array();
    if (!$customer) {
      $customerError = 'Pelanggan tidak ditemukan.';
    } elseif (isset($existingInvoice['status']) && $existingInvoice['status'] === 'unpaid') {
      $customerError = 'Masih ada invoice yang belum dibayar untuk pelanggan ini.';
    } elseif (empty($routerConnected)) {
      $customerError = 'Router MikroTik tidak terhubung.';
    } else {
      $service = isset($customer['service']) && $customer['service'] === 'pppoe' ? 'pppoe' : 'hotspot';
      $prices = billingProfilePrice($service, isset($customer['profile']) ? $customer['profile'] : '', $hotspotProfiles, $pppoeProfiles);
      $amount = isset($_POST['amount']) && is_numeric($_POST['amount']) ? (float) $_POST['amount'] : (float) ($prices['selling_price'] !== '' ? $prices['selling_price'] : $prices['price']);
      $user = isset($customerUsers[$service][$customer['username']]) ? $customerUsers[$service][$customer['username']] : array();
      if ($amount <= 0) {
        $customerError = 'Harga profile belum diatur. Isi Price atau Selling Price terlebih dahulu.';
      } else {
        $invoice = array(
          'id' => 'invoice-' . uniqid(),
          'number' => 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
          'customer_id' => $customer['id'], 'service_id' => $customer['service_id'],
          'customer_name' => isset($customer['name']) ? $customer['name'] : '',
          'username' => isset($customer['username']) ? $customer['username'] : '',
          'service' => $service,
          'profile' => isset($customer['profile']) ? $customer['profile'] : '',
          'amount' => $amount,
          'due_date' => billingDueDate($service, isset($customer['username']) ? $customer['username'] : '', $user, $customerSchedulers),
          'status' => 'unpaid',
          'created_at' => time(),
        );
        if (mikhmonSaveInvoice($session, $invoice) === false) $customerError = 'Invoice gagal disimpan.';
        else $customerMessage = 'Invoice ' . $invoice['number'] . ' berhasil dibuat.';
        $invoices = mikhmonGetInvoices($session);
      }
    }
  } elseif ($action === 'mark_paid') {
    $invoiceId = isset($_POST['invoice_id']) ? (string) $_POST['invoice_id'] : '';
    $invoiceIndex = -1;
    foreach ($invoices as $index => $invoiceRow) {
      if (isset($invoiceRow['id']) && (string) $invoiceRow['id'] === $invoiceId) { $invoiceIndex = $index; break; }
    }
    if ($invoiceIndex < 0 || !$customer || !isset($invoices[$invoiceIndex]['customer_id']) || (string) $invoices[$invoiceIndex]['customer_id'] !== (string) $customer['id'] || ((string)($invoices[$invoiceIndex]['service_id'] ?? '') !== '' && (string)($invoices[$invoiceIndex]['service_id'] ?? '') !== (string)$customer['service_id'])) {
      $customerError = 'Invoice atau pelanggan tidak ditemukan.';
    } elseif (isset($invoices[$invoiceIndex]['status']) && $invoices[$invoiceIndex]['status'] === 'paid') {
      $customerError = 'Invoice ini sudah dibayar.';
    } elseif (empty($routerConnected)) {
      $customerError = 'Router MikroTik tidak terhubung.';
    } else {
      $service = isset($customer['service']) && $customer['service'] === 'pppoe' ? 'pppoe' : 'hotspot';
      $username = isset($customer['username']) ? (string) $customer['username'] : '';
      $command = $service === 'pppoe' ? '/ppp/secret' : '/ip/hotspot/user';
      $rows = $username !== '' ? $API->comm($command . '/print', array('?name' => $username)) : array();
      if (billingApiError($rows) !== '' || !isset($rows[0]['.id'])) {
        $customerError = 'User MikroTik tidak ditemukan, invoice belum ditandai lunas.';
      } else {
        $wasDisabled = isset($rows[0]['disabled']) && ($rows[0]['disabled'] === 'true' || $rows[0]['disabled'] === 'yes');
        $wasHotspotExpired = $service === 'hotspot' && isset($rows[0]['limit-uptime']) && $rows[0]['limit-uptime'] === '1s';
        $args = array('.id' => $rows[0]['.id'], 'disabled' => 'no');
        if ($service === 'hotspot' && ($wasDisabled || $wasHotspotExpired)) {
          $args['limit-uptime'] = '0';
          $args['comment'] = 'up-' . (isset($customer['name']) ? $customer['name'] : '');
        }
        $response = $API->comm($command . '/set', $args);
        if (billingApiError($response) !== '') {
          $customerError = 'User gagal diaktifkan, invoice belum ditandai lunas.';
        } else {
          if ($service === 'hotspot' && ($wasDisabled || $wasHotspotExpired)) {
            $API->comm($command . '/reset-counters', array('.id' => $rows[0]['.id']));
          }
          $customerUsers[$service][$username]['disabled'] = 'false';
          if ($service === 'hotspot' && ($wasDisabled || $wasHotspotExpired)) {
            $customerUsers[$service][$username]['limit-uptime'] = '0';
            $customerUsers[$service][$username]['comment'] = 'up-' . (isset($customer['name']) ? $customer['name'] : '');
          }
          $invoices[$invoiceIndex]['status'] = 'paid';
          $invoices[$invoiceIndex]['paid_at'] = time();
          $invoices[$invoiceIndex]['paid_by_user_id'] = mikhmonIsBiller() ? mikhmonUserId() : '';
          $invoices[$invoiceIndex]['paid_by_name'] = mikhmonIsBiller() ? mikhmonUserName() : 'Administrator';
          $invoices[$invoiceIndex]['biller_commission'] = mikhmonIsBiller() ? mikhmonBillerCommissionAmount() : 0;
          if (mikhmonSaveInvoice($session, $invoices[$invoiceIndex]) === false) {
            $customerError = 'User aktif, tetapi status invoice gagal disimpan.';
          } else {
            $customerMessage = $wasDisabled || $wasHotspotExpired
              ? 'Pembayaran diterima dan user berhasil diaktifkan.'
              : 'Pembayaran diterima. User sudah dalam keadaan aktif.';
          }
        }
      }
    }
  }
}

$latestInvoices = array();
foreach ($invoices as $invoice) {
  if (!isset($invoice['customer_id'])) continue;
  $invoiceKey = (string)$invoice['customer_id'] . '|' . (string)($invoice['service_id'] ?? '');
  if (!isset($latestInvoices[$invoiceKey]) || (int) $invoice['created_at'] > (int) $latestInvoices[$invoiceKey]['created_at']) {
    $latestInvoices[$invoiceKey] = $invoice;
  }
}
?>
<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3><i class="fa fa-money"></i> Billing <span style="font-size:14px"> &nbsp;|&nbsp; <span id="billingVisibleCount"><?= $serviceCount; ?></span> layanan</span></h3></div>
  <div class="card-body">
    <?php if ($customerMessage !== ''): ?><div class="box bg-success"><?= htmlspecialchars($customerMessage, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($customerError !== ''): ?><div class="box bg-danger"><?= htmlspecialchars($customerError, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if (empty($routerConnected)): ?><div class="box bg-warning">Router MikroTik tidak terhubung. Status user tidak dapat diperbarui, invoice baru dan aktivasi pembayaran dinonaktifkan.</div><?php endif; ?>
    <div class="row"><div class="col-6 pd-t-5 pd-b-5"><div class="input-group">
      <div class="input-group-6 col-box-6"><input id="billingSearch" type="text" class="group-item group-item-l" placeholder="<?= $_search; ?>"></div>
      <div class="input-group-6 col-box-6"><select id="billingStatus" class="group-item group-item-r"><option value="all">Status: Semua</option><option value="unpaid">Belum Bayar / Belum Dibuat</option><option value="paid">Sudah Bayar</option></select></div>
    </div></div></div>
    <div class="overflow box-bordered" style="max-height:75vh"><table id="billingTable" class="table table-bordered table-hover text-nowrap">
      <thead><tr><th>No</th><th>Nama</th><th>HP</th><th>Layanan</th><th>Username</th><th>Profile</th><th>Status User</th><th>Jatuh Tempo</th><th>Invoice</th><th>Status Invoice</th><th>Jumlah</th><th>Diproses Oleh</th><th>Aksi</th></tr></thead><tbody>
      <?php $billingIndex = 0; foreach ($customers as $baseCustomer): foreach (mikhmonCustomerServices($baseCustomer) as $serviceRow): $billingIndex++; $customer = $baseCustomer; $customer['service_id'] = $serviceRow['id']; $customer['service'] = $serviceRow['service']; $customer['username'] = $serviceRow['username']; $customer['profile'] = $serviceRow['profile']; ?>
        <?php
          $service = isset($customer['service']) && $customer['service'] === 'pppoe' ? 'pppoe' : 'hotspot';
          $username = isset($customer['username']) ? (string) $customer['username'] : '';
          $user = isset($customerUsers[$service][$username]) ? $customerUsers[$service][$username] : array();
          $missing = $username === '' || empty($user);
          $disabled = !$missing && isset($user['disabled']) && ($user['disabled'] === 'true' || $user['disabled'] === 'yes');
          $expired = $disabled || ($service === 'hotspot' && !$missing && isset($user['limit-uptime']) && $user['limit-uptime'] === '1s');
          $userStatus = $missing ? 'Tidak ditemukan' : ($expired ? 'Expired' : 'Aktif');
          $statusFilter = $missing ? 'missing' : ($expired ? 'expired' : 'active');
          $dueDate = billingDueDate($service, $username, $user, $customerSchedulers);
          $invoice = isset($latestInvoices[$customer['id'] . '|' . $customer['service_id']]) ? $latestInvoices[$customer['id'] . '|' . $customer['service_id']] : array();
          if (!$invoice && !empty($baseCustomer['services'][0]['id']) && (string)$baseCustomer['services'][0]['id'] === (string)$customer['service_id'] && isset($latestInvoices[$customer['id'] . '|'])) $invoice = $latestInvoices[$customer['id'] . '|'];
          $invoiceStatus = isset($invoice['status']) ? $invoice['status'] : 'none';
          if ($dueDate === '' && $invoiceStatus !== 'paid' && !empty($invoice['due_date'])) $dueDate = $invoice['due_date'];
          $dueDateDisplay = $dueDate !== '' ? $dueDate : ($invoiceStatus === 'paid' && !$missing ? 'Menunggu login' : '-');
          $invoiceStatusText = $invoiceStatus === 'paid' ? 'Sudah Bayar' : ($invoiceStatus === 'unpaid' ? 'Belum Bayar' : 'Belum Dibuat');
          $invoiceStatusClass = $invoiceStatus === 'paid' ? 'text-success' : ($invoiceStatus === 'unpaid' ? 'text-danger' : 'text-secondary');
          $prices = billingProfilePrice($service, isset($customer['profile']) ? $customer['profile'] : '', $hotspotProfiles, $pppoeProfiles);
          $amount = isset($invoice['amount']) ? (float) $invoice['amount'] : (float) ($prices['selling_price'] !== '' ? $prices['selling_price'] : $prices['price']);
          $phone = billingPhone(isset($customer['phone']) ? $customer['phone'] : '');
          $messageBrand = isset($brandname) && trim((string) $brandname) !== '' ? trim((string) $brandname) : 'MIKHMON';
          $invoiceNumber = isset($invoice['number']) ? $invoice['number'] : 'baru';
          $customerName = isset($customer['name']) ? trim((string) $customer['name']) : '';
          $invoiceText = "Yth. Bapak/Ibu " . $customerName . ",\n\n"
            . "Dengan hormat,\n"
            . "Berikut kami sampaikan tagihan layanan internet dari " . $messageBrand . ".\n\n"
            . "DETAIL TAGIHAN\n"
            . "No. Invoice: " . $invoiceNumber . "\n"
            . "Nama Pelanggan: " . $customerName . "\n"
            . "Layanan: " . strtoupper($service) . "\n"
            . "Username: " . $username . "\n"
            . "Jumlah Tagihan: " . billingMessageAmount($amount, $currency) . "\n"
            . "Tanggal Jatuh Tempo: " . ($dueDate !== '' ? $dueDate : '-') . "\n\n"
            . "Mohon melakukan pembayaran sebelum tanggal jatuh tempo melalui metode pembayaran yang telah tersedia."
            . " Apabila pembayaran telah dilakukan, silakan konfirmasi kepada admin untuk proses verifikasi dan aktivasi layanan.\n\n"
            . "Terima kasih atas kepercayaan Anda menggunakan layanan kami.\n\n"
            . "Hormat kami,\n"
            . $messageBrand;
          $waUrl = $phone !== '' ? 'https://wa.me/' . $phone . '?text=' . rawurlencode($invoiceText) : '';
        ?>
        <tr class="billing-row" data-status="<?= $invoiceStatus === 'paid' ? 'paid' : 'unpaid'; ?>"><td><?= $billingIndex; ?></td><td><?= htmlspecialchars(isset($customer['name']) ? $customer['name'] : '', ENT_QUOTES); ?></td><td><?= htmlspecialchars(isset($customer['phone']) ? $customer['phone'] : '', ENT_QUOTES); ?></td><td><?= strtoupper($service); ?></td><td><?= htmlspecialchars($username, ENT_QUOTES); ?></td><td><?= htmlspecialchars(isset($customer['profile']) ? $customer['profile'] : '', ENT_QUOTES); ?></td><td class="<?= $expired || $missing ? 'text-danger' : 'text-success'; ?>"><strong><?= $userStatus; ?></strong></td><td><?= htmlspecialchars($dueDateDisplay, ENT_QUOTES); ?></td><td><?= isset($invoice['number']) ? htmlspecialchars($invoice['number'], ENT_QUOTES) : '-'; ?></td><td class="<?= $invoiceStatusClass; ?>"><strong><?= $invoiceStatusText; ?></strong></td><td><?= $amount > 0 ? htmlspecialchars($currency . ' ' . number_format($amount, 0, ',', '.'), ENT_QUOTES) : '-'; ?></td><td><?= $invoiceStatus === 'paid' ? htmlspecialchars(isset($invoice['paid_by_name']) ? $invoice['paid_by_name'] : 'Data lama', ENT_QUOTES) : '-'; ?></td>
          <td><?php if ($invoiceStatus === 'paid'): ?><span class="text-success"><i class="fa fa-check"></i> Sudah Bayar</span> <form method="post" style="display:inline"><input type="hidden" name="billing_action" value="create_invoice"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($customer['id'], ENT_QUOTES); ?>"><input type="hidden" name="service_id" value="<?= htmlspecialchars($customer['service_id'], ENT_QUOTES); ?>"><button class="btn bg-primary" type="submit"><i class="fa fa-file-text"></i> Invoice Baru</button></form><?php else: ?><?php if ($invoiceStatus === 'none'): ?><form method="post" style="display:inline"><input type="hidden" name="billing_action" value="create_invoice"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($customer['id'], ENT_QUOTES); ?>"><input type="hidden" name="service_id" value="<?= htmlspecialchars($customer['service_id'], ENT_QUOTES); ?>"><button class="btn bg-primary" type="submit"><i class="fa fa-file-text"></i> Buat Invoice</button></form><?php endif; ?><?php if ($invoiceStatus !== 'none' && $waUrl !== ''): ?><a class="btn bg-green" target="_blank" href="<?= htmlspecialchars($waUrl, ENT_QUOTES); ?>"><i class="fa fa-whatsapp"></i> Kirim</a><?php endif; ?><?php if ($invoiceStatus !== 'none'): ?><form method="post" style="display:inline"><input type="hidden" name="billing_action" value="mark_paid"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($customer['id'], ENT_QUOTES); ?>"><input type="hidden" name="service_id" value="<?= htmlspecialchars($customer['service_id'], ENT_QUOTES); ?>"><input type="hidden" name="invoice_id" value="<?= htmlspecialchars($invoice['id'], ENT_QUOTES); ?>"><button class="btn bg-success" type="submit" onclick="return confirm('Tandai invoice sudah dibayar dan aktifkan user?');"><i class="fa fa-check"></i> Sudah Bayar</button></form><?php endif; ?><?php endif; ?></td>
        </tr>
      <?php endforeach; endforeach; ?>
      <?php if (!$customers): ?><tr class="billing-info-row"><td colspan="13" class="text-center">Belum ada data pelanggan.</td></tr><?php endif; ?><tr id="billingNoResults" style="display:none"><td colspan="13" class="text-center">Data billing tidak ditemukan.</td></tr>
      </tbody></table></div>
  </div>
</div></div></div>
<script>
$(function() {
  function filterBilling() {
    var search = $('#billingSearch').val().toLowerCase();
    var status = $('#billingStatus').val();
    var visible = 0;
    $('.billing-row').each(function() {
      var row = $(this);
      var show = row.text().toLowerCase().indexOf(search) > -1 && (status === 'all' || row.data('status') === status);
      row.toggle(show);
      if (show) visible++;
    });
    $('#billingVisibleCount').text(visible);
    $('#billingNoResults').toggle(visible === 0 && $('.billing-row').length > 0);
  }
  $('#billingSearch').on('input', filterBilling);
  $('#billingStatus').on('change', filterBilling);
  filterBilling();
});
</script>
