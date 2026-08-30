<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
if (!mikhmonIsAdmin() && !mikhmonIsBiller()) { header('Location:../admin.php?id=login'); exit; }

include_once('./include/database.php');
include_once('./ppp/profilemeta.php');

function billingApiError($response) {
  if (!is_array($response)) return '';
  foreach (array('!trap', '!fatal') as $type) if (isset($response[$type][0]['message'])) return $response[$type][0]['message'];
  return '';
}

function billingFindCustomer($customers, $id) {
  foreach ($customers as $customer) if (isset($customer['id']) && (string) $customer['id'] === (string) $id) return $customer;
  return array();
}

function billingLatestInvoice($invoices, $customerId) {
  $latest = array();
  foreach ($invoices as $invoice) {
    if (!isset($invoice['customer_id']) || (string) $invoice['customer_id'] !== (string) $customerId) continue;
    if (!$latest || (int) ($invoice['created_at'] ?? 0) > (int) ($latest['created_at'] ?? 0)) $latest = $invoice;
  }
  return $latest;
}

function billingProfilePrice($service, $profileName, $hotspotProfiles, $pppoeProfiles) {
  $rows = $service === 'pppoe' ? $pppoeProfiles : $hotspotProfiles;
  foreach ((array) $rows as $profile) {
    if (!isset($profile['name']) || (string) $profile['name'] !== (string) $profileName) continue;
    if ($service === 'pppoe') {
      $meta = pppProfileMetaDecode($profile['comment'] ?? '');
      return array('price' => $meta['price'], 'selling_price' => $meta['selling-price']);
    }
    $login = isset($profile['on-login']) ? (string) $profile['on-login'] : '';
    if (preg_match('/,([^,]*),([^,]*),([^,]*),([^,]*),/', $login, $matches)) return array('price' => $matches[2], 'selling_price' => $matches[4]);
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

function billingServiceDetails($customer, $customerUsers, $customerSchedulers, $hotspotProfiles, $pppoeProfiles) {
  $details = array();
  foreach (mikhmonCustomerServices($customer) as $serviceRow) {
    $service = $serviceRow['service'] === 'pppoe' ? 'pppoe' : 'hotspot';
    $username = (string) $serviceRow['username'];
    $user = isset($customerUsers[$service][$username]) ? $customerUsers[$service][$username] : array();
    $missing = $username === '' || !$user;
    $disabled = !$missing && isset($user['disabled']) && ($user['disabled'] === 'true' || $user['disabled'] === 'yes');
    $expired = $disabled || ($service === 'hotspot' && !$missing && isset($user['limit-uptime']) && $user['limit-uptime'] === '1s');
    $prices = billingProfilePrice($service, $serviceRow['profile'], $hotspotProfiles, $pppoeProfiles);
    $amount = (float) ($prices['selling_price'] !== '' ? $prices['selling_price'] : $prices['price']);
    $details[] = array(
      'id' => $serviceRow['id'], 'service' => $service, 'username' => $username,
      'profile' => $serviceRow['profile'], 'amount' => $amount,
      'due_date' => billingDueDate($service, $username, $user, $customerSchedulers),
      'status' => $missing ? 'missing' : ($expired ? 'expired' : 'active'),
      'status_text' => $missing ? 'Tidak ditemukan' : ($expired ? 'Expired' : 'Aktif'),
    );
  }
  return $details;
}

function billingInvoiceServices($invoice, $customer) {
  if (isset($invoice['services']) && is_array($invoice['services']) && $invoice['services']) return $invoice['services'];
  if (!empty($invoice['username'])) return array(array(
    'id' => $invoice['service_id'] ?? '', 'service' => $invoice['service'] ?? 'hotspot',
    'username' => $invoice['username'], 'profile' => $invoice['profile'] ?? '',
    'amount' => (float) ($invoice['amount'] ?? 0), 'due_date' => $invoice['due_date'] ?? '',
  ));
  return mikhmonCustomerServices($customer);
}

$customers = mikhmonGetCustomers($session);
$invoices = mikhmonGetInvoices($session);
$hotspotProfiles = array(); $pppoeProfiles = array();
$customerUsers = array('hotspot' => array(), 'pppoe' => array());
$customerSchedulers = array(); $customerError = ''; $customerMessage = '';

if (!empty($routerConnected)) {
  $hotspotProfiles = $API->comm('/ip/hotspot/user/profile/print');
  $pppoeProfiles = $API->comm('/ppp/profile/print');
  foreach (array('hotspot' => '/ip/hotspot/user/print', 'pppoe' => '/ppp/secret/print') as $service => $command) {
    $rows = $API->comm($command);
    if (is_array($rows) && billingApiError($rows) === '') foreach ($rows as $row) if (is_array($row) && isset($row['name'])) $customerUsers[$service][(string) $row['name']] = $row;
  }
  $schedulerRows = $API->comm('/system/scheduler/print');
  if (is_array($schedulerRows) && billingApiError($schedulerRows) === '') foreach ($schedulerRows as $row) if (is_array($row) && isset($row['name'])) $customerSchedulers[(string) $row['name']] = $row;
}
$hotspotProfiles = is_array($hotspotProfiles) ? $hotspotProfiles : array();
$pppoeProfiles = is_array($pppoeProfiles) ? $pppoeProfiles : array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = isset($_POST['billing_action']) ? $_POST['billing_action'] : '';
  $customer = billingFindCustomer($customers, $_POST['customer_id'] ?? '');
  if ($action === 'create_invoice') {
    $existingInvoice = $customer ? billingLatestInvoice($invoices, $customer['id']) : array();
    if (!$customer) $customerError = 'Pelanggan tidak ditemukan.';
    elseif (($existingInvoice['status'] ?? '') === 'unpaid') $customerError = 'Masih ada invoice yang belum dibayar untuk pelanggan ini.';
    elseif (empty($routerConnected)) $customerError = 'Router MikroTik tidak terhubung.';
    else {
      $invoiceServices = billingServiceDetails($customer, $customerUsers, $customerSchedulers, $hotspotProfiles, $pppoeProfiles);
      $amount = 0; $missingPrice = array(); $dueDates = array();
      foreach ($invoiceServices as $serviceDetail) {
        $amount += (float) $serviceDetail['amount'];
        if ((float) $serviceDetail['amount'] <= 0) $missingPrice[] = strtoupper($serviceDetail['service']) . ' ' . $serviceDetail['profile'];
        if ($serviceDetail['due_date'] !== '') $dueDates[] = $serviceDetail['due_date'];
      }
      if ($missingPrice) $customerError = 'Harga profile belum diatur: ' . implode(', ', $missingPrice) . '.';
      elseif (!$invoiceServices) $customerError = 'Pelanggan belum memiliki layanan.';
      else {
        $invoice = array(
          'id' => 'invoice-' . uniqid(), 'number' => 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
          'customer_id' => $customer['id'], 'customer_name' => $customer['name'] ?? '',
          'services' => $invoiceServices, 'service_count' => count($invoiceServices),
          'amount' => $amount, 'due_date' => $dueDates ? implode(' / ', array_unique($dueDates)) : '',
          'status' => 'unpaid', 'created_at' => time(),
        );
        if (mikhmonSaveInvoice($session, $invoice) === false) $customerError = 'Invoice gagal disimpan.';
        else $customerMessage = 'Invoice ' . $invoice['number'] . ' untuk ' . count($invoiceServices) . ' layanan berhasil dibuat.';
        $invoices = mikhmonGetInvoices($session);
      }
    }
  } elseif ($action === 'mark_paid') {
    $invoiceId = isset($_POST['invoice_id']) ? (string) $_POST['invoice_id'] : '';
    $invoiceIndex = -1;
    foreach ($invoices as $index => $invoiceRow) if (isset($invoiceRow['id']) && (string) $invoiceRow['id'] === $invoiceId) { $invoiceIndex = $index; break; }
    if ($invoiceIndex < 0 || !$customer || (string) ($invoices[$invoiceIndex]['customer_id'] ?? '') !== (string) ($customer['id'] ?? '')) $customerError = 'Invoice atau pelanggan tidak ditemukan.';
    elseif (($invoices[$invoiceIndex]['status'] ?? '') === 'paid') $customerError = 'Invoice ini sudah dibayar.';
    elseif (empty($routerConnected)) $customerError = 'Router MikroTik tidak terhubung.';
    else {
      $activationRows = array();
      foreach (billingInvoiceServices($invoices[$invoiceIndex], $customer) as $serviceRow) {
        $service = ($serviceRow['service'] ?? '') === 'pppoe' ? 'pppoe' : 'hotspot';
        $username = (string) ($serviceRow['username'] ?? '');
        $command = $service === 'pppoe' ? '/ppp/secret' : '/ip/hotspot/user';
        $rows = $username !== '' ? $API->comm($command . '/print', array('?name' => $username)) : array();
        if (billingApiError($rows) !== '' || !isset($rows[0]['.id'])) { $customerError = 'User MikroTik ' . $username . ' tidak ditemukan. Invoice belum ditandai lunas.'; break; }
        $activationRows[] = array('service' => $service, 'username' => $username, 'command' => $command, 'row' => $rows[0]);
      }
      if ($customerError === '') {
        $activated = 0;
        foreach ($activationRows as $activation) {
          $row = $activation['row']; $service = $activation['service'];
          $wasDisabled = isset($row['disabled']) && ($row['disabled'] === 'true' || $row['disabled'] === 'yes');
          $wasExpired = $service === 'hotspot' && isset($row['limit-uptime']) && $row['limit-uptime'] === '1s';
          $args = array('.id' => $row['.id'], 'disabled' => 'no');
          if ($service === 'hotspot' && ($wasDisabled || $wasExpired)) { $args['limit-uptime'] = '0'; $args['comment'] = 'up-' . ($customer['name'] ?? ''); }
          $response = $API->comm($activation['command'] . '/set', $args);
          if (billingApiError($response) !== '') { $customerError = 'Gagal mengaktifkan user ' . $activation['username'] . '. Invoice belum ditandai lunas.'; break; }
          if ($service === 'hotspot' && ($wasDisabled || $wasExpired)) $API->comm($activation['command'] . '/reset-counters', array('.id' => $row['.id']));
          $customerUsers[$service][$activation['username']]['disabled'] = 'false';
          if ($service === 'hotspot' && ($wasDisabled || $wasExpired)) {
            $customerUsers[$service][$activation['username']]['limit-uptime'] = '0';
            $customerUsers[$service][$activation['username']]['comment'] = 'up-' . ($customer['name'] ?? '');
          }
          $activated++;
        }
        if ($customerError === '') {
          $invoices[$invoiceIndex]['status'] = 'paid'; $invoices[$invoiceIndex]['paid_at'] = time();
          $invoices[$invoiceIndex]['paid_by_user_id'] = mikhmonIsBiller() ? mikhmonUserId() : '';
          $invoices[$invoiceIndex]['paid_by_name'] = mikhmonIsBiller() ? mikhmonUserName() : 'Administrator';
          $invoices[$invoiceIndex]['biller_commission'] = mikhmonIsBiller() ? mikhmonBillerCommissionAmount() : 0;
          if (mikhmonSaveInvoice($session, $invoices[$invoiceIndex]) === false) $customerError = 'User aktif, tetapi status invoice gagal disimpan.';
          else $customerMessage = 'Pembayaran diterima dan ' . $activated . ' layanan pelanggan berhasil diaktifkan.';
        }
      }
    }
  }
}

$latestInvoices = array();
foreach ($invoices as $invoice) if (isset($invoice['customer_id'])) {
  $key = (string) $invoice['customer_id'];
  if (!isset($latestInvoices[$key]) || (int) ($invoice['created_at'] ?? 0) > (int) ($latestInvoices[$key]['created_at'] ?? 0)) $latestInvoices[$key] = $invoice;
}
?>
<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3><i class="fa fa-money"></i> Billing <span style="font-size:14px"> &nbsp;|&nbsp; <span id="billingVisibleCount"><?= count($customers); ?></span> pelanggan</span></h3></div>
  <div class="card-body">
    <?php if ($customerMessage !== ''): ?><div class="box bg-success"><?= htmlspecialchars($customerMessage, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($customerError !== ''): ?><div class="box bg-danger"><?= htmlspecialchars($customerError, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if (empty($routerConnected)): ?><div class="box bg-warning">Router MikroTik tidak terhubung. Invoice baru dan aktivasi pembayaran dinonaktifkan.</div><?php endif; ?>
    <div class="row"><div class="col-6 pd-t-5 pd-b-5"><div class="input-group"><div class="input-group-6 col-box-6"><input id="billingSearch" type="text" class="group-item group-item-l" placeholder="<?= $_search; ?>"></div><div class="input-group-6 col-box-6"><select id="billingStatus" class="group-item group-item-r"><option value="all">Status: Semua</option><option value="unpaid">Belum Bayar / Belum Dibuat</option><option value="paid">Sudah Bayar</option></select></div></div></div></div>
    <style>.billing-service-select{min-width:110px}.billing-username{font-weight:bold;min-width:130px}.billing-profile{color:#777;font-size:12px;min-width:170px;white-space:normal}.billing-service-count{text-align:center;font-weight:bold}</style>
    <div class="overflow box-bordered" style="max-height:75vh"><table id="billingTable" class="table table-bordered table-hover text-nowrap"><thead><tr><th>No</th><th>Nama</th><th>HP</th><th>Jumlah Layanan</th><th>Layanan</th><th>Username</th><th>Profile</th><th>Status User</th><th>Jatuh Tempo</th><th>Invoice</th><th>Status Invoice</th><th>Total Tagihan</th><th>Diproses Oleh</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($customers as $index => $customer):
      $serviceDetails = billingServiceDetails($customer, $customerUsers, $customerSchedulers, $hotspotProfiles, $pppoeProfiles);
      $firstService = $serviceDetails[0] ?? array('username'=>'','profile'=>'','status_text'=>'-','status'=>'missing','due_date'=>'');
      $invoice = $latestInvoices[(string) $customer['id']] ?? array();
      $invoiceStatus = $invoice['status'] ?? 'none';
      $invoiceStatusText = $invoiceStatus === 'paid' ? 'Sudah Bayar' : ($invoiceStatus === 'unpaid' ? 'Belum Bayar' : 'Belum Dibuat');
      $invoiceStatusClass = $invoiceStatus === 'paid' ? 'text-success' : ($invoiceStatus === 'unpaid' ? 'text-danger' : 'text-secondary');
      $estimatedAmount = 0; foreach ($serviceDetails as $detail) $estimatedAmount += (float) $detail['amount'];
      $amount = isset($invoice['amount']) ? (float) $invoice['amount'] : $estimatedAmount;
      $serviceSearch = implode(' ', array_map(function($row){return $row['service'].' '.$row['username'].' '.$row['profile'];}, $serviceDetails));
      $phone = billingPhone($customer['phone'] ?? ''); $customerName = trim((string) ($customer['name'] ?? ''));
      $messageBrand = isset($brandname) && trim((string) $brandname) !== '' ? trim((string) $brandname) : 'MIKHMON';
      $messageServices = array(); foreach (billingInvoiceServices($invoice, $customer) as $row) $messageServices[] = '- ' . strtoupper($row['service'] ?? '') . ' / ' . ($row['username'] ?? '') . ' / ' . ($row['profile'] ?? '') . ' / ' . billingMessageAmount($row['amount'] ?? 0, $currency);
      $invoiceText = "Yth. Bapak/Ibu " . $customerName . ",\n\nDETAIL TAGIHAN " . $messageBrand . "\nNo. Invoice: " . ($invoice['number'] ?? 'baru') . "\nNama Pelanggan: " . $customerName . "\nLayanan:\n" . implode("\n", $messageServices) . "\n\nTotal Tagihan: " . billingMessageAmount($amount, $currency) . "\nJatuh Tempo: " . ($invoice['due_date'] ?? '-') . "\n\nMohon melakukan pembayaran sebelum jatuh tempo. Terima kasih.";
      $waUrl = $phone !== '' && $invoiceStatus !== 'none' ? 'https://wa.me/' . $phone . '?text=' . rawurlencode($invoiceText) : '';
    ?>
      <tr class="billing-row" data-search="<?= htmlspecialchars(strtolower($serviceSearch), ENT_QUOTES); ?>" data-status="<?= $invoiceStatus === 'paid' ? 'paid' : 'unpaid'; ?>"><td><?= $index + 1; ?></td><td><?= htmlspecialchars($customerName, ENT_QUOTES); ?></td><td><?= htmlspecialchars($customer['phone'] ?? '', ENT_QUOTES); ?></td><td class="billing-service-count"><?= count($serviceDetails); ?></td><td><select class="form-control billing-service-select"><?php foreach ($serviceDetails as $serviceIndex => $detail): ?><option value="<?= $serviceIndex; ?>" data-username="<?= htmlspecialchars($detail['username'], ENT_QUOTES); ?>" data-profile="<?= htmlspecialchars($detail['profile'], ENT_QUOTES); ?>" data-user-status="<?= htmlspecialchars($detail['status_text'], ENT_QUOTES); ?>" data-status-class="<?= $detail['status'] === 'active' ? 'text-success' : 'text-danger'; ?>" data-due-date="<?= htmlspecialchars($detail['due_date'], ENT_QUOTES); ?>"><?= strtoupper(htmlspecialchars($detail['service'], ENT_QUOTES)); ?></option><?php endforeach; ?></select></td><td class="billing-username"><?= htmlspecialchars($firstService['username'], ENT_QUOTES); ?></td><td class="billing-profile"><?= htmlspecialchars($firstService['profile'], ENT_QUOTES); ?></td><td class="billing-user-status <?= $firstService['status'] === 'active' ? 'text-success' : 'text-danger'; ?>"><strong><?= htmlspecialchars($firstService['status_text'], ENT_QUOTES); ?></strong></td><td class="billing-due-date"><?= htmlspecialchars($firstService['due_date'] !== '' ? $firstService['due_date'] : '-', ENT_QUOTES); ?></td><td><?= htmlspecialchars($invoice['number'] ?? '-', ENT_QUOTES); ?></td><td class="<?= $invoiceStatusClass; ?>"><strong><?= $invoiceStatusText; ?></strong></td><td><?= $amount > 0 ? htmlspecialchars($currency . ' ' . number_format($amount, 0, ',', '.'), ENT_QUOTES) : '-'; ?></td><td><?= $invoiceStatus === 'paid' ? htmlspecialchars($invoice['paid_by_name'] ?? 'Data lama', ENT_QUOTES) : '-'; ?></td><td><?php if ($invoiceStatus === 'paid'): ?><span class="text-success"><i class="fa fa-check"></i> Sudah Bayar</span> <form method="post" style="display:inline"><input type="hidden" name="billing_action" value="create_invoice"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($customer['id'], ENT_QUOTES); ?>"><button class="btn bg-primary" type="submit"><i class="fa fa-file-text"></i> Invoice Baru</button></form><?php else: ?><?php if ($invoiceStatus === 'none'): ?><form method="post" style="display:inline"><input type="hidden" name="billing_action" value="create_invoice"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($customer['id'], ENT_QUOTES); ?>"><button class="btn bg-primary" type="submit"><i class="fa fa-file-text"></i> Buat Invoice</button></form><?php endif; ?><?php if ($waUrl !== ''): ?><a class="btn bg-green" target="_blank" href="<?= htmlspecialchars($waUrl, ENT_QUOTES); ?>"><i class="fa fa-whatsapp"></i> Kirim</a><?php endif; ?><?php if ($invoiceStatus === 'unpaid'): ?><form method="post" style="display:inline"><input type="hidden" name="billing_action" value="mark_paid"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($customer['id'], ENT_QUOTES); ?>"><input type="hidden" name="invoice_id" value="<?= htmlspecialchars($invoice['id'], ENT_QUOTES); ?>"><button class="btn bg-success" type="submit" onclick="return confirm('Tandai invoice lunas dan aktifkan semua layanan pelanggan?');"><i class="fa fa-check"></i> Sudah Bayar</button></form><?php endif; ?><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$customers): ?><tr><td colspan="14" class="text-center">Belum ada data pelanggan.</td></tr><?php endif; ?><tr id="billingNoResults" style="display:none"><td colspan="14" class="text-center">Data billing tidak ditemukan.</td></tr></tbody></table></div>
  </div>
</div></div></div>
<script>
$(function(){
  function showBillingService(select){var option=$(select).find('option:selected'),row=$(select).closest('tr'),status=row.find('.billing-user-status');row.find('.billing-username').text(option.data('username')||'-');row.find('.billing-profile').text(option.data('profile')||'-');status.removeClass('text-success text-danger').addClass(option.data('status-class')).find('strong').text(option.data('user-status')||'-');row.find('.billing-due-date').text(option.data('due-date')||'-');}
  $('.billing-service-select').on('change',function(){showBillingService(this);});
  function filterBilling(){var search=$('#billingSearch').val().toLowerCase(),status=$('#billingStatus').val(),visible=0;$('.billing-row').each(function(){var row=$(this),text=row.text().toLowerCase()+' '+String(row.data('search')||''),show=text.indexOf(search)>-1&&(status==='all'||row.data('status')===status);row.toggle(show);if(show)visible++;});$('#billingVisibleCount').text(visible);$('#billingNoResults').toggle(visible===0&&$('.billing-row').length>0);}
  $('#billingSearch').on('input',filterBilling);$('#billingStatus').on('change',filterBilling);filterBilling();
});
</script>
