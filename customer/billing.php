<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
if (!mikhmonIsAdmin() && !mikhmonIsBiller() && !mikhmonIsMitra()) { header('Location:../admin.php?id=login'); exit; }

include_once('./include/database.php');
include_once('./ppp/profilemeta.php');
include_once('./lib/fonnte.php');
include_once('./lib/payment_gateway.php');
include_once('./lib/payment_activation.php');
include_once('./lib/billing_automation.php');
include_once('./lib/billing_profile.php');

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
      return array('price' => $meta['price'], 'selling_price' => $meta['selling-price'], 'validity' => $meta['validity']);
    }
    $login = isset($profile['on-login']) ? (string) $profile['on-login'] : '';
    if (preg_match('/,([^,]*),([^,]*),([^,]*),([^,]*),/', $login, $matches)) return array('price' => $matches[2], 'selling_price' => $matches[4], 'validity' => $matches[3]);
  }
  return array('price' => '', 'selling_price' => '', 'validity' => '');
}

function billingProfileRow($service, $profileName, $hotspotProfiles, $pppoeProfiles) {
  $rows = $service === 'pppoe' ? $pppoeProfiles : $hotspotProfiles;
  foreach ((array) $rows as $profile) if (isset($profile['name']) && (string) $profile['name'] === (string) $profileName) return $profile;
  return array();
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

function billingInvoiceMessage($customer, $invoice, $dueDate, $currency, $brand) {
  $customerName = trim((string) ($customer['name'] ?? ''));
  $services = array();
  foreach (billingInvoiceServices($invoice, $customer) as $row) {
    $services[] = '- ' . strtoupper($row['service'] ?? '') . ' / ' . ($row['username'] ?? '') . ' / ' . ($row['profile'] ?? '') . ' / ' . billingMessageAmount($row['amount'] ?? 0, $currency);
  }
  $amount = isset($invoice['amount']) ? (float) $invoice['amount'] : 0;
  return "Yth. Bapak/Ibu " . $customerName . ",\n\nDETAIL TAGIHAN " . $brand . "\nNo. Invoice: " . ($invoice['number'] ?? 'baru') . "\nNama Pelanggan: " . $customerName . "\nLayanan:\n" . implode("\n", $services) . "\n\nTotal Tagihan: " . billingMessageAmount($amount, $currency) . "\nJatuh Tempo: " . ($invoice['due_date'] ?? $dueDate ?: '-') . "\n\nMohon melakukan pembayaran sebelum jatuh tempo. Terima kasih.";
}

function billingDueTimestamp($value) {
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

function billingValiditySeconds($value) {
  $seconds = 0;
  if (preg_match_all('/(\d+)([wdhms])/i', (string) $value, $matches, PREG_SET_ORDER)) foreach ($matches as $match) {
    $multipliers = array('w'=>604800,'d'=>86400,'h'=>3600,'m'=>60,'s'=>1);
    $seconds += (int) $match[1] * $multipliers[strtolower($match[2])];
  }
  return $seconds;
}

function billingCustomerInterval($serviceDetails) {
  foreach ((array) $serviceDetails as $service) {
    $seconds = billingValiditySeconds($service['validity'] ?? '');
    if ($seconds > 0) return $seconds;
  }
  return 30 * 86400;
}

function billingExistingDueTimestamp($customer, $serviceDetails) {
  // Billing uses one fixed due day for every customer: the 5th of the
  // upcoming month. Existing router comments are no longer used as dates.
  return mikhmonBillingAutomationUpcomingDueTimestamp();
}

function billingCustomerDueTimestamp($customer, $serviceDetails) {
  return billingExistingDueTimestamp($customer, $serviceDetails);
}

function billingCustomerDueDate($customer, $serviceDetails) {
  $timestamp = billingCustomerDueTimestamp($customer, $serviceDetails);
  return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
}

function billingCustomerSchedulerName($customerId) {
  return 'mikhmon-customer-' . substr(md5((string) $customerId), 0, 12);
}

function billingRouterString($value) {
  return str_replace(array('\\', '"', '$', "\r", "\n"), array('\\\\', '\\"', '\\$', ' ', ' '), (string) $value);
}

function billingCustomerDisableScript($customer) {
  $script = '';
  foreach (mikhmonCustomerServices($customer) as $service) {
    $username = billingRouterString($service['username']);
    if ($service['service'] === 'pppoe') $script .= '/ppp active remove [find where name="' . $username . '"]; /ppp secret set [find where name="' . $username . '"] disabled=yes; ';
    else $script .= '/ip hotspot active remove [find where user="' . $username . '"]; /ip hotspot user set [find where name="' . $username . '"] disabled=yes; ';
  }
  $schedulerName = billingRouterString(billingCustomerSchedulerName($customer['id'] ?? ''));
  return $script . '/system scheduler remove [find where name="' . $schedulerName . '"];';
}

function billingRemoveScheduler($API, $name) {
  $rows = $API->comm('/system/scheduler/print', array('?name' => $name));
  if (billingApiError($rows) !== '') return false;
  foreach ((array) $rows as $row) if (isset($row['.id'])) $API->comm('/system/scheduler/remove', array('.id' => $row['.id']));
  return true;
}

function billingRemoveLegacySchedulers($API, $customer) {
  foreach (mikhmonCustomerServices($customer) as $service) {
    if ($service['service'] === 'pppoe') billingRemoveScheduler($API, 'mikhmon-pppoe-' . $service['username']);
    else billingRemoveScheduler($API, $service['username']);
  }
}

function billingDisableCustomerNow($API, $customer) {
  $disabledRows = array();
  foreach (mikhmonCustomerServices($customer) as $service) {
    $command = $service['service'] === 'pppoe' ? '/ppp/secret' : '/ip/hotspot/user';
    $rows = $API->comm($command . '/print', array('?name' => $service['username']));
    if (!isset($rows[0]['.id'])) {
      foreach ($disabledRows as $rollback) $API->comm($rollback['command'] . '/set', array('.id' => $rollback['id'], 'disabled' => 'no'));
      return false;
    }
    if ($service['service'] === 'pppoe') $API->comm('/ppp/active/remove', array('?name' => $service['username']));
    else $API->comm('/ip/hotspot/active/remove', array('?user' => $service['username']));
    $response = $API->comm($command . '/set', array('.id' => $rows[0]['.id'], 'disabled' => 'yes'));
    if (billingApiError($response) !== '') {
      foreach ($disabledRows as $rollback) $API->comm($rollback['command'] . '/set', array('.id' => $rollback['id'], 'disabled' => 'no'));
      return false;
    }
    $disabledRows[] = array('command' => $command, 'id' => $rows[0]['.id']);
  }
  return true;
}

function billingInstallCustomerScheduler($API, $customer, $dueAt) {
  global $fonnteConfig;
  if (!isset($fonnteConfig) || !is_array($fonnteConfig)) $fonnteConfig = mikhmonFonnteReadConfig();
  $schedulerName = billingCustomerSchedulerName($customer['id'] ?? '');
  billingRemoveScheduler($API, $schedulerName);
  if (!empty($fonnteConfig['automation_enabled'])) {
    // The CLI worker owns isolation while automation is enabled.
    billingRemoveLegacySchedulers($API, $customer);
    return true;
  }
  if ($dueAt <= time() + 5) {
    $disabled = billingDisableCustomerNow($API, $customer);
    if ($disabled) billingRemoveLegacySchedulers($API, $customer);
    return $disabled;
  }
  $response = $API->comm('/system/scheduler/add', array(
    'name' => $schedulerName, 'start-date' => strtolower(date('M/d/Y', $dueAt)),
    'start-time' => date('H:i:s', $dueAt), 'interval' => '0s',
    'on-event' => billingCustomerDisableScript($customer), 'disabled' => 'no',
    'comment' => 'Mikhmon customer due: ' . ($customer['name'] ?? ''),
  ));
  if (billingApiError($response) !== '') return false;
  billingRemoveLegacySchedulers($API, $customer);
  return true;
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
    $profileRow = billingProfileRow($service, $serviceRow['profile'], $hotspotProfiles, $pppoeProfiles);
    $profileAllowed = $profileRow && mikhmonBillingProfileCanManage($service, $profileRow);
    $amount = (float) ($prices['selling_price'] !== '' ? $prices['selling_price'] : $prices['price']);
    $details[] = array(
      'id' => $serviceRow['id'], 'service' => $service, 'username' => $username,
      'profile' => $serviceRow['profile'], 'amount' => $amount, 'validity' => $prices['validity'] ?? '',
      'due_date' => billingDueDate($service, $username, $user, $customerSchedulers),
      'profile_expired_mode' => $profileRow ? mikhmonBillingProfileExpiredMode($service, $profileRow) : '',
      'status' => $missing ? 'missing' : (!$profileAllowed ? 'invalid-profile' : ($expired ? 'expired' : 'active')),
      'status_text' => $missing ? 'Tidak ditemukan' : (!$profileAllowed ? 'Profile harus None' : ($expired ? 'Expired' : 'Aktif')),
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

function billingUnpaidInvoiceIndex($invoices, $customerId) {
  $found = -1; $createdAt = -1;
  foreach ((array) $invoices as $index => $invoice) {
    if (($invoice['status'] ?? '') !== 'unpaid' || (string) ($invoice['customer_id'] ?? '') !== (string) $customerId) continue;
    $invoiceCreatedAt = (int) ($invoice['created_at'] ?? 0);
    if ($found < 0 || $invoiceCreatedAt >= $createdAt) { $found = $index; $createdAt = $invoiceCreatedAt; }
  }
  return $found;
}

function billingSyncUnpaidInvoice($session, &$invoices, $customer, $customerUsers, $customerSchedulers, $hotspotProfiles, $pppoeProfiles, $API, &$schedulerMap) {
  global $fonnteConfig;
  $invoiceIndex = billingUnpaidInvoiceIndex($invoices, $customer['id'] ?? '');
  if ($invoiceIndex < 0) return false;
  $serviceDetails = billingServiceDetails($customer, $customerUsers, $customerSchedulers, $hotspotProfiles, $pppoeProfiles);
  if (!$serviceDetails) return false;

  $amount = 0;
  foreach ($serviceDetails as $service) $amount += (float) ($service['amount'] ?? 0);
  $existingInvoiceDue = billingDueTimestamp($invoices[$invoiceIndex]['due_date'] ?? '');
  if ($existingInvoiceDue > 0) {
    // Preserve the invoice month while normalizing its due day to the 5th.
    $dueTimestamp = mktime(0, 0, 0, (int) date('n', $existingInvoiceDue), 5, (int) date('Y', $existingInvoiceDue));
  } else $dueTimestamp = billingCustomerDueTimestamp($customer, $serviceDetails);
  $dueDate = $dueTimestamp > 0 ? date('Y-m-d H:i:s', $dueTimestamp) : '';
  $invoice = $invoices[$invoiceIndex];
  $oldServices = billingInvoiceServices($invoice, $customer);
  $oldSignature = json_encode(array_map(function ($service) {
    return array('id' => $service['id'] ?? '', 'service' => $service['service'] ?? '', 'username' => $service['username'] ?? '', 'profile' => $service['profile'] ?? '', 'amount' => (float) ($service['amount'] ?? 0));
  }, $oldServices));
  $newSignature = json_encode(array_map(function ($service) {
    return array('id' => $service['id'] ?? '', 'service' => $service['service'] ?? '', 'username' => $service['username'] ?? '', 'profile' => $service['profile'] ?? '', 'amount' => (float) ($service['amount'] ?? 0));
  }, $serviceDetails));
  $changed = $oldSignature !== $newSignature || (float) ($invoice['amount'] ?? 0) !== (float) $amount || (int) ($invoice['service_count'] ?? 0) !== count($serviceDetails) || (string) ($invoice['due_date'] ?? '') !== $dueDate || (string) ($invoice['customer_name'] ?? '') !== (string) ($customer['name'] ?? '');
  if ($changed) {
    $invoice['customer_name'] = $customer['name'] ?? '';
    $invoice['services'] = $serviceDetails;
    $invoice['service_count'] = count($serviceDetails);
    $invoice['amount'] = $amount;
    $invoice['due_date'] = $dueDate;
    if (mikhmonSaveInvoice($session, $invoice) === false) return false;
    $invoices[$invoiceIndex] = $invoice;
  }
  if ($dueDate !== '' && (string) ($customer['due_date'] ?? '') !== $dueDate) {
    mikhmonSetCustomerDueDate($session, $customer['id'], $dueDate);
    $customer['due_date'] = $dueDate;
  }
  // The CLI worker owns scheduler management while automation is enabled.
  if (count($serviceDetails) >= 1 && empty($fonnteConfig['automation_enabled'])) {
    $schedulerName = billingCustomerSchedulerName($customer['id']);
    if ($changed || !isset($schedulerMap[$schedulerName])) {
      if (billingInstallCustomerScheduler($API, $customer, $dueTimestamp)) {
        $schedulerMap[$schedulerName] = array('name' => $schedulerName, 'next-run' => date('M/d/Y H:i:s', $dueTimestamp));
      }
    }
  }
  return $changed;
}

$customers = array_values(array_filter(mikhmonVisibleCustomers($session), function ($customer) { return count(mikhmonCustomerServices($customer)) > 0; }));
$invoices = mikhmonVisibleInvoices($session);
$hotspotProfiles = array(); $pppoeProfiles = array();
$customerUsers = array('hotspot' => array(), 'pppoe' => array());
$customerSchedulers = array(); $customerError = ''; $customerMessage = '';
$fonnteConfig = mikhmonFonnteReadConfig();
$paymentGatewayConfig = mikhmonPaymentGatewayReadConfig();

// Webhooks can be delayed or blocked by a firewall. Reconcile recent Midtrans
// invoices directly so a successful payment is still visible to the operator.
if (!empty($paymentGatewayConfig['enabled']) && !empty($paymentGatewayConfig['midtrans']['enabled'])) {
  $midtransReconciled = 0;
  foreach ($invoices as $invoiceIndex => $invoiceRow) {
    if ($midtransReconciled >= 20) break;
    if (($invoiceRow['status'] ?? '') !== 'unpaid' || !empty($invoiceRow['gateway_payment_received']) || ($invoiceRow['payment_gateway'] ?? '') !== 'midtrans' || empty($invoiceRow['payment_order_id'])) continue;
    if (!empty($invoiceRow['payment_environment']) && $invoiceRow['payment_environment'] !== $paymentGatewayConfig['midtrans']['environment']) continue;
    if (!empty($invoiceRow['payment_created_at']) && (int) $invoiceRow['payment_created_at'] + (int) $paymentGatewayConfig['invoice_duration'] < time()) continue;
    $gatewayStatus = mikhmonPaymentGatewayGetMidtransStatus($invoiceRow['payment_order_id'], $paymentGatewayConfig);
    if (empty($gatewayStatus['success'])) continue;
    if (!empty($gatewayStatus['paid']) && (int) round((float) $gatewayStatus['amount']) !== (int) round((float) ($invoiceRow['amount'] ?? 0))) continue;
    $midtransReconciled++;
    $changed = false;
    if (($invoiceRow['gateway_status'] ?? '') !== $gatewayStatus['status']) { $invoiceRow['gateway_status'] = $gatewayStatus['status']; $changed = true; }
    if (!empty($gatewayStatus['reference']) && ($invoiceRow['payment_reference'] ?? '') !== $gatewayStatus['reference']) { $invoiceRow['payment_reference'] = $gatewayStatus['reference']; $changed = true; }
    if (!empty($gatewayStatus['paid'])) {
      $invoiceRow['gateway_payment_received'] = true;
      $invoiceRow['gateway_paid_at'] = $invoiceRow['gateway_paid_at'] ?? time();
      $changed = true;
    }
    if ($changed && mikhmonSaveInvoice($session, $invoiceRow) !== false) $invoices[$invoiceIndex] = $invoiceRow;
  }
}

// Try activation once when a valid gateway payment is first observed. Failed
// attempts remain visible and are retried only when the operator presses the
// activation button, preventing repeated router writes on every page load.
foreach ($invoices as $invoiceRow) {
  if (($invoiceRow['status'] ?? '') !== 'unpaid' || empty($invoiceRow['gateway_payment_received']) || !empty($invoiceRow['activation_status'])) continue;
  $automaticActivation = mikhmonPaymentActivationProcess($session, $invoiceRow['id'] ?? '', !empty($routerConnected) ? $API : null, array(
    'actor_name' => 'Otomatis ' . strtoupper((string) ($invoiceRow['payment_gateway'] ?? 'Gateway')),
  ));
  if (!empty($automaticActivation['success'])) $customerMessage = $automaticActivation['message'] ?? 'Pembayaran dan aktivasi otomatis berhasil.';
  break;
}
if (isset($automaticActivation)) {
  $invoices = mikhmonVisibleInvoices($session);
  $customers = array_values(array_filter(mikhmonVisibleCustomers($session), function ($customer) { return count(mikhmonCustomerServices($customer)) > 0; }));
}

if (!empty($routerConnected)) {
  $hotspotProfiles = $API->comm('/ip/hotspot/user/profile/print');
  $pppoeProfiles = $API->comm('/ppp/profile/print');
  foreach (array('hotspot' => '/ip/hotspot/user/print', 'pppoe' => '/ppp/secret/print') as $service => $command) {
    $rows = $API->comm($command);
    if (is_array($rows) && billingApiError($rows) === '') foreach ($rows as $row) if (is_array($row) && isset($row['name'])) $customerUsers[$service][(string) $row['name']] = $row;
  }
  // Scheduler state is maintained by the billing worker when automation is on.
  if (empty($fonnteConfig['automation_enabled'])) {
    $schedulerRows = $API->comm('/system/scheduler/print');
    if (is_array($schedulerRows) && billingApiError($schedulerRows) === '') foreach ($schedulerRows as $row) if (is_array($row) && isset($row['name'])) $customerSchedulers[(string) $row['name']] = $row;
  }
}
$hotspotProfiles = is_array($hotspotProfiles) ? $hotspotProfiles : array();
$pppoeProfiles = is_array($pppoeProfiles) ? $pppoeProfiles : array();

if (!empty($routerConnected)) {
  // Keep an unpaid invoice aligned when services are added or removed later.
  foreach ($customers as $customerIndex => $customer) {
    if (billingSyncUnpaidInvoice($session, $invoices, $customer, $customerUsers, $customerSchedulers, $hotspotProfiles, $pppoeProfiles, $API, $customerSchedulers)) {
      $updatedCustomer = mikhmonFindCustomer($session, $customer['id']);
      if ($updatedCustomer) $customers[$customerIndex] = $updatedCustomer;
    }
  }
  if (empty($fonnteConfig['automation_enabled'])) {
    foreach ($customers as $customerIndex => $customer) {
      if (count(mikhmonCustomerServices($customer)) < 2) continue;
      $schedulerName = billingCustomerSchedulerName($customer['id']);
      if (isset($customerSchedulers[$schedulerName])) continue;
      $serviceDetails = billingServiceDetails($customer, $customerUsers, $customerSchedulers, $hotspotProfiles, $pppoeProfiles);
      $dueTimestamp = billingExistingDueTimestamp($customer, $serviceDetails);
      if ($dueTimestamp <= time() + 5 || !billingInstallCustomerScheduler($API, $customer, $dueTimestamp)) continue;
      $dueDate = date('Y-m-d H:i:s', $dueTimestamp);
      if ((string) ($customer['due_date'] ?? '') !== $dueDate) mikhmonSetCustomerDueDate($session, $customer['id'], $dueDate);
      $customers[$customerIndex]['due_date'] = $dueDate;
      $customerSchedulers[$schedulerName] = array('name' => $schedulerName, 'next-run' => date('M/d/Y H:i:s', $dueTimestamp));
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = isset($_POST['billing_action']) ? $_POST['billing_action'] : '';
  $customer = billingFindCustomer($customers, $_POST['customer_id'] ?? '');
  if ($action === 'create_invoice') {
    $existingInvoice = $customer ? billingLatestInvoice($invoices, $customer['id']) : array();
    $unpaidInvoiceIndex = $customer ? billingUnpaidInvoiceIndex($invoices, $customer['id']) : -1;
    if (!$customer) $customerError = 'Pelanggan tidak ditemukan.';
    elseif ($unpaidInvoiceIndex >= 0) $customerError = 'Masih ada invoice yang belum dibayar untuk pelanggan ini.';
    elseif (empty($routerConnected)) $customerError = 'Router MikroTik tidak terhubung.';
    else {
      $invoiceServices = billingServiceDetails($customer, $customerUsers, $customerSchedulers, $hotspotProfiles, $pppoeProfiles);
      $amount = 0; $missingPrice = array();
      $invalidProfiles = array();
      foreach ($invoiceServices as $serviceDetail) {
        $amount += (float) $serviceDetail['amount'];
        if ((float) $serviceDetail['amount'] <= 0) $missingPrice[] = strtoupper($serviceDetail['service']) . ' ' . $serviceDetail['profile'];
        if (($serviceDetail['status'] ?? '') === 'invalid-profile') $invalidProfiles[] = strtoupper($serviceDetail['service']) . ' ' . $serviceDetail['profile'];
      }
      $customerDueTimestamp = billingCustomerDueTimestamp($customer, $invoiceServices);
      $customerDueDate = date('Y-m-d H:i:s', $customerDueTimestamp);
      if ($invalidProfiles) $customerError = 'Profile layanan berikut harus Expired Mode = None untuk dikelola Billing: ' . implode(', ', $invalidProfiles) . '.';
      elseif ($missingPrice) $customerError = 'Harga profile belum diatur: ' . implode(', ', $missingPrice) . '.';
      elseif (!$invoiceServices) $customerError = 'Pelanggan belum memiliki layanan.';
      else {
        $invoice = array(
          'id' => 'invoice-' . uniqid(), 'number' => 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
          'customer_id' => $customer['id'], 'customer_name' => $customer['name'] ?? '',
          'services' => $invoiceServices, 'service_count' => count($invoiceServices),
          'amount' => $amount, 'due_date' => $customerDueDate,
          'status' => 'unpaid', 'created_at' => time(),
        );
        if (mikhmonSaveInvoice($session, $invoice) === false) $customerError = 'Invoice gagal disimpan.';
        else {
          if ($customerDueDate !== '') {
            mikhmonSetCustomerDueDate($session, $customer['id'], $customerDueDate);
            if (!billingInstallCustomerScheduler($API, $customer, $customerDueTimestamp)) $customerMessage .= ' Scheduler jatuh tempo gagal dipasang.';
          }
          $customerMessage = 'Invoice ' . $invoice['number'] . ' untuk ' . count($invoiceServices) . ' layanan berhasil dibuat.';
        }
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
      $gatewayConfirmed = !empty($invoices[$invoiceIndex]['gateway_payment_received']);
      $actorName = $gatewayConfirmed
        ? 'Retry ' . strtoupper((string) ($invoices[$invoiceIndex]['payment_gateway'] ?? 'Gateway'))
        : (mikhmonIsAdmin() ? 'Administrator' : mikhmonUserName());
      $activationResult = mikhmonPaymentActivationProcess($session, $invoiceId, $API, array(
        'allow_manual' => !$gatewayConfirmed,
        'actor_name' => $actorName,
        'paid_by_user_id' => $gatewayConfirmed || mikhmonIsAdmin() ? '' : mikhmonUserId(),
        'biller_commission' => !$gatewayConfirmed && mikhmonIsBiller() ? mikhmonBillerCommissionAmount() : 0,
      ));
      if (empty($activationResult['success'])) $customerError = $activationResult['message'] ?? 'Aktivasi layanan gagal.';
      else {
        $customerMessage = ($activationResult['message'] ?? 'Layanan berhasil diaktifkan.') . ' Jatuh tempo berikutnya: ' . ($activationResult['invoice']['next_due_date'] ?? '-') . '.';
        if (empty($activationResult['scheduler_installed'])) $customerMessage .= ' Scheduler jatuh tempo gagal dipasang.';
        $invoices = mikhmonGetInvoices($session);
        foreach (mikhmonPaymentActivationInvoiceServices($activationResult['invoice'] ?? array(), $customer) as $serviceRow) {
          $service = ($serviceRow['service'] ?? '') === 'pppoe' ? 'pppoe' : 'hotspot';
          $username = (string) ($serviceRow['username'] ?? '');
          if (isset($customerUsers[$service][$username])) {
            $customerUsers[$service][$username]['disabled'] = 'false';
            if ($service === 'hotspot') $customerUsers[$service][$username]['limit-uptime'] = '0';
          }
        }
      }
    }
  } elseif ($action === 'send_fonnte') {
    $invoiceId = isset($_POST['invoice_id']) ? (string) $_POST['invoice_id'] : '';
    $invoiceToSend = array();
    foreach ($invoices as $invoiceRow) if (isset($invoiceRow['id']) && (string) $invoiceRow['id'] === $invoiceId) { $invoiceToSend = $invoiceRow; break; }
    if (!mikhmonFonnteValidCsrf($_POST['fonnte_csrf'] ?? '')) $customerError = 'Sesi pengiriman WhatsApp tidak valid. Muat ulang halaman lalu coba lagi.';
    elseif (!$customer || !$invoiceToSend || (string) ($invoiceToSend['customer_id'] ?? '') !== (string) ($customer['id'] ?? '')) $customerError = 'Invoice atau pelanggan tidak ditemukan.';
    elseif (!mikhmonBillingAutomationIsWorkHour()) $customerError = 'Pengiriman invoice melalui Fonnte hanya dapat dilakukan pada jam kerja (08.00-17.00).';
    else {
      $brand = isset($brandname) && trim((string) $brandname) !== '' ? trim((string) $brandname) : 'MIKHMON';
      $message = billingInvoiceMessage($customer, $invoiceToSend, $invoiceToSend['due_date'] ?? '', $currency, $brand);
      $result = mikhmonFonnteSend($customer['phone'] ?? '', $message, $fonnteConfig);
      if (!empty($result['status'])) $customerMessage = 'Pesan invoice ' . ($invoiceToSend['number'] ?? '') . ' berhasil dimasukkan ke antrean Fonnte.';
      else $customerError = (string) ($result['reason'] ?? 'Pesan invoice gagal dikirim melalui Fonnte.');
    }
  } elseif ($action === 'create_payment') {
    $invoiceId = isset($_POST['invoice_id']) ? (string) $_POST['invoice_id'] : '';
    $invoiceIndex = -1;
    foreach ($invoices as $index => $invoiceRow) if (isset($invoiceRow['id']) && (string) $invoiceRow['id'] === $invoiceId) { $invoiceIndex = $index; break; }
    $provider = isset($_POST['payment_provider']) ? (string) $_POST['payment_provider'] : '';
    if (!mikhmonPaymentGatewayValidCsrf($_POST['payment_gateway_csrf'] ?? '')) $customerError = 'Sesi pembayaran tidak valid. Muat ulang halaman lalu coba lagi.';
    elseif (!$customer || $invoiceIndex < 0 || (string) ($invoices[$invoiceIndex]['customer_id'] ?? '') !== (string) ($customer['id'] ?? '')) $customerError = 'Invoice atau pelanggan tidak ditemukan.';
    elseif (($invoices[$invoiceIndex]['status'] ?? '') !== 'unpaid') $customerError = 'Hanya invoice yang belum dibayar yang dapat dibuatkan link pembayaran.';
    else {
      $paymentOrderId = ($invoices[$invoiceIndex]['number'] ?? $invoiceId) . '-' . strtoupper(substr(uniqid(), -6));
      $paymentResult = mikhmonPaymentGatewayCreatePayment($provider, array(
        'order_id' => $paymentOrderId,
        'amount' => $invoices[$invoiceIndex]['amount'] ?? 0,
        'description' => 'Invoice ' . ($invoices[$invoiceIndex]['number'] ?? $invoiceId),
        'customer_name' => $customer['name'] ?? 'Pelanggan',
        'phone' => $customer['phone'] ?? '',
      ), $paymentGatewayConfig);
      if (!empty($paymentResult['success'])) {
        $invoices[$invoiceIndex]['payment_gateway'] = $paymentResult['provider'];
        $invoices[$invoiceIndex]['payment_environment'] = $paymentResult['environment'] ?? '';
        $invoices[$invoiceIndex]['payment_order_id'] = $paymentOrderId;
        $invoices[$invoiceIndex]['payment_url'] = $paymentResult['payment_url'];
        $invoices[$invoiceIndex]['payment_reference'] = $paymentResult['reference'] ?? '';
        $invoices[$invoiceIndex]['payment_created_at'] = time();
        if (mikhmonSaveInvoice($session, $invoices[$invoiceIndex]) === false) $customerError = 'Link pembayaran berhasil dibuat, tetapi gagal disimpan ke invoice.';
        else $customerMessage = 'Link pembayaran ' . strtoupper($paymentResult['provider']) . ' berhasil dibuat untuk invoice ' . ($invoices[$invoiceIndex]['number'] ?? '') . '.';
      } else $customerError = (string) ($paymentResult['message'] ?? 'Link pembayaran gagal dibuat.');
    }
  }
}

// Keep the last payment visible until the next invoice actually becomes due.
// A new cycle is generated immediately after payment, so simply selecting the
// newest invoice would make a successful payment look unpaid in the table.
$latestInvoices = array();
$invoiceCandidates = array();
foreach ($invoices as $invoice) if (isset($invoice['customer_id'])) {
  $key = (string) $invoice['customer_id'];
  if (!isset($invoiceCandidates[$key])) $invoiceCandidates[$key] = array('paid' => array(), 'unpaid' => array());
  $status = (string) ($invoice['status'] ?? '');
  if ($status !== 'paid' && $status !== 'unpaid') continue;
  $sortAt = $status === 'paid' ? (int) ($invoice['paid_at'] ?? $invoice['created_at'] ?? 0) : (int) ($invoice['created_at'] ?? 0);
  $current = $invoiceCandidates[$key][$status];
  $currentSortAt = $status === 'paid' ? (int) ($current['paid_at'] ?? $current['created_at'] ?? 0) : (int) ($current['created_at'] ?? 0);
  if (!$current || $sortAt >= $currentSortAt) $invoiceCandidates[$key][$status] = $invoice;
}
foreach ($invoiceCandidates as $key => $candidates) {
  $paid = $candidates['paid']; $unpaid = $candidates['unpaid'];
  if (!$unpaid) { if ($paid) $latestInvoices[$key] = $paid; continue; }
  $unpaidDue = billingDueTimestamp($unpaid['due_date'] ?? '');
  // Invalid/missing dates retain the old behavior and remain actionable.
  $unpaidIsDue = $unpaidDue <= 0 || $unpaidDue <= time();
  $latestInvoices[$key] = $paid && !$unpaidIsDue ? $paid : $unpaid;
}
?>
<div class="row"><div class="col-12"><div class="card">
  <div class="card-header"><h3><i class="fa fa-money"></i> Billing <span style="font-size:14px"> &nbsp;|&nbsp; <span id="billingVisibleCount"><?= count($customers); ?></span> pelanggan</span></h3></div>
  <div class="card-body">
    <?php if ($customerMessage !== ''): ?><div class="box bg-success"><?= htmlspecialchars($customerMessage, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($customerError !== ''): ?><div class="box bg-danger"><?= htmlspecialchars($customerError, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if (empty($routerConnected)): ?><div class="box bg-warning">Router MikroTik tidak terhubung. Invoice baru dan aktivasi pembayaran dinonaktifkan.</div><?php endif; ?>
    <div class="row"><div class="col-6 pd-t-5 pd-b-5"><div class="input-group"><div class="input-group-6 col-box-6"><input id="billingSearch" type="text" class="group-item group-item-l" placeholder="<?= $_search; ?>"></div><div class="input-group-6 col-box-6"><select id="billingStatus" class="group-item group-item-r"><option value="all">Status: Semua</option><option value="unpaid">Belum Bayar / Belum Dibuat</option><option value="paid">Sudah Bayar</option></select></div></div></div></div>
    <style>#billingSearch,#billingStatus{height:30px}.billing-service-select{min-width:110px}.billing-username{font-weight:bold;min-width:130px}.billing-profile{color:#777;font-size:12px;min-width:170px;white-space:normal}.billing-service-count{text-align:center;font-weight:bold}</style>
    <div class="overflow box-bordered" style="max-height:75vh"><table id="billingTable" class="table table-bordered table-hover text-nowrap"><thead><tr><th>No</th><th>Nama</th><th>HP</th><th>Jumlah Layanan</th><th>Layanan</th><th>Username</th><th>Profile</th><th>Status User</th><th>Jatuh Tempo</th><th>Invoice</th><th>Status Invoice</th><th>Total Tagihan</th><th>Diproses Oleh</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($customers as $index => $customer):
      $serviceDetails = billingServiceDetails($customer, $customerUsers, $customerSchedulers, $hotspotProfiles, $pppoeProfiles);
      $firstService = $serviceDetails[0] ?? array('username'=>'','profile'=>'','status_text'=>'-','status'=>'missing');
      $invoice = $latestInvoices[(string) $customer['id']] ?? array();
      $invoiceStatus = $invoice['status'] ?? 'none';
      $customerDueDate = $invoiceStatus === 'paid' && !empty($invoice['next_due_date'])
        ? (string) $invoice['next_due_date']
        : (!empty($invoice['due_date']) ? (string) $invoice['due_date'] : billingCustomerDueDate($customer, $serviceDetails));
      $invoiceStatusText = $invoiceStatus === 'paid' ? 'Sudah Bayar' : ($invoiceStatus === 'unpaid' ? 'Belum Bayar' : 'Belum Dibuat');
      $invoiceStatusClass = $invoiceStatus === 'paid' ? 'text-success' : ($invoiceStatus === 'unpaid' ? 'text-danger' : 'text-secondary');
      $estimatedAmount = 0; foreach ($serviceDetails as $detail) $estimatedAmount += (float) $detail['amount'];
      $amount = isset($invoice['amount']) ? (float) $invoice['amount'] : $estimatedAmount;
      $serviceSearch = implode(' ', array_map(function($row){return $row['service'].' '.$row['username'].' '.$row['profile'];}, $serviceDetails));
      $phone = billingPhone($customer['phone'] ?? ''); $customerName = trim((string) ($customer['name'] ?? ''));
      $messageBrand = isset($brandname) && trim((string) $brandname) !== '' ? trim((string) $brandname) : 'MIKHMON';
      $invoiceText = billingInvoiceMessage($customer, $invoice, $customerDueDate, $currency, $messageBrand);
      $waUrl = $phone !== '' && $invoiceStatus !== 'none' ? 'https://wa.me/' . $phone . '?text=' . rawurlencode($invoiceText) : '';
      $canSendFonnte = !empty($fonnteConfig['enabled']) && $fonnteConfig['token'] !== '' && $phone !== '' && $invoiceStatus !== 'none';
      $gatewayPaymentReceived = !empty($invoice['gateway_payment_received']) && $invoiceStatus === 'unpaid';
      $activationFailed = $gatewayPaymentReceived && ($invoice['activation_status'] ?? '') === 'failed';
      if ($gatewayPaymentReceived) {
        $invoiceStatusText = 'Diterima ' . strtoupper((string) ($invoice['payment_gateway'] ?? 'gateway'));
        $invoiceStatusClass = 'text-success';
      }
      $storedPaymentEnvironment = (string) ($invoice['payment_environment'] ?? '');
      if ($storedPaymentEnvironment === '' && ($invoice['payment_gateway'] ?? '') === 'midtrans') $storedPaymentEnvironment = mikhmonPaymentGatewayMidtransUrlEnvironment($invoice['payment_url'] ?? '');
      $paymentEnvironmentChanged = ($invoice['payment_gateway'] ?? '') === 'midtrans'
        && $storedPaymentEnvironment !== ''
        && $storedPaymentEnvironment !== $paymentGatewayConfig['midtrans']['environment'];
      $paymentExpired = $paymentEnvironmentChanged || (!empty($invoice['payment_created_at']) && (int) $invoice['payment_created_at'] + (int) $paymentGatewayConfig['invoice_duration'] <= time());
      $canCreatePayment = !empty($paymentGatewayConfig['enabled']) && $invoiceStatus === 'unpaid' && !$gatewayPaymentReceived;
      $paymentUrl = !$paymentExpired && !empty($invoice['payment_url']) ? (string) $invoice['payment_url'] : '';
    ?>
      <tr class="billing-row" data-search="<?= htmlspecialchars(strtolower($serviceSearch), ENT_QUOTES); ?>" data-status="<?= $invoiceStatus === 'paid' ? 'paid' : 'unpaid'; ?>"><td><?= $index + 1; ?></td><td><?= htmlspecialchars($customerName, ENT_QUOTES); ?></td><td><?= htmlspecialchars($customer['phone'] ?? '', ENT_QUOTES); ?></td><td class="billing-service-count"><?= count($serviceDetails); ?></td><td><select class="form-control billing-service-select"><?php foreach ($serviceDetails as $serviceIndex => $detail): ?><option value="<?= $serviceIndex; ?>" data-username="<?= htmlspecialchars($detail['username'], ENT_QUOTES); ?>" data-profile="<?= htmlspecialchars($detail['profile'], ENT_QUOTES); ?>" data-user-status="<?= htmlspecialchars($detail['status_text'], ENT_QUOTES); ?>" data-status-class="<?= $detail['status'] === 'active' ? 'text-success' : 'text-danger'; ?>"><?= strtoupper(htmlspecialchars($detail['service'], ENT_QUOTES)); ?></option><?php endforeach; ?></select></td><td class="billing-username"><?= htmlspecialchars($firstService['username'], ENT_QUOTES); ?></td><td class="billing-profile"><?= htmlspecialchars($firstService['profile'], ENT_QUOTES); ?></td><td class="billing-user-status <?= $firstService['status'] === 'active' ? 'text-success' : 'text-danger'; ?>"><strong><?= htmlspecialchars($firstService['status_text'], ENT_QUOTES); ?></strong></td><td class="billing-due-date"><?= htmlspecialchars($customerDueDate !== '' ? $customerDueDate : '-', ENT_QUOTES); ?></td><td><?= htmlspecialchars($invoice['number'] ?? '-', ENT_QUOTES); ?></td><td class="<?= $invoiceStatusClass; ?>"><strong><?= htmlspecialchars($invoiceStatusText, ENT_QUOTES); ?></strong></td><td><?= $amount > 0 ? htmlspecialchars($currency . ' ' . number_format($amount, 0, ',', '.'), ENT_QUOTES) : '-'; ?></td><td><?= $invoiceStatus === 'paid' ? htmlspecialchars($invoice['paid_by_name'] ?? 'Data lama', ENT_QUOTES) : '-'; ?></td><td><?php if ($invoiceStatus === 'paid'): ?><span class="text-success"><i class="fa fa-check"></i> Sudah Bayar</span> <form method="post" style="display:inline"><input type="hidden" name="billing_action" value="create_invoice"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($customer['id'], ENT_QUOTES); ?>"><button class="btn bg-primary" type="submit"><i class="fa fa-file-text"></i> Invoice Baru</button></form><?php else: ?><?php if ($invoiceStatus === 'none'): ?><form method="post" style="display:inline"><input type="hidden" name="billing_action" value="create_invoice"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($customer['id'], ENT_QUOTES); ?>"><button class="btn bg-primary" type="submit"><i class="fa fa-file-text"></i> Buat Invoice</button></form><?php endif; ?><?php if ($paymentUrl !== '' && !$gatewayPaymentReceived): ?><a class="btn bg-primary" target="_blank" href="<?= htmlspecialchars($paymentUrl, ENT_QUOTES); ?>"><i class="fa fa-credit-card"></i> Bayar <?= strtoupper(htmlspecialchars($invoice['payment_gateway'] ?? '', ENT_QUOTES)); ?></a><?php elseif ($canCreatePayment): ?><form method="post" style="display:inline"><input type="hidden" name="billing_action" value="create_payment"><input type="hidden" name="payment_gateway_csrf" value="<?= htmlspecialchars(mikhmonPaymentGatewayCsrfToken(), ENT_QUOTES); ?>"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($customer['id'], ENT_QUOTES); ?>"><input type="hidden" name="invoice_id" value="<?= htmlspecialchars($invoice['id'], ENT_QUOTES); ?>"><select name="payment_provider" class="btn" title="Pilih payment gateway"><option value="">Gateway utama</option><option value="midtrans">Midtrans</option><option value="xendit">Xendit</option></select><button class="btn bg-primary" type="submit"><i class="fa fa-credit-card"></i> Buat Link Bayar</button></form><?php endif; ?><?php if ($waUrl !== ''): ?><a class="btn bg-green" target="_blank" href="<?= htmlspecialchars($waUrl, ENT_QUOTES); ?>"><i class="fa fa-whatsapp"></i> Kirim</a><?php endif; ?><?php if ($canSendFonnte): ?><form method="post" style="display:inline"><input type="hidden" name="billing_action" value="send_fonnte"><input type="hidden" name="fonnte_csrf" value="<?= htmlspecialchars(mikhmonFonnteCsrfToken(), ENT_QUOTES); ?>"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($customer['id'], ENT_QUOTES); ?>"><input type="hidden" name="invoice_id" value="<?= htmlspecialchars($invoice['id'], ENT_QUOTES); ?>"><button class="btn bg-green" type="submit" title="Kirim melalui Fonnte"><i class="fa fa-send"></i> Fonnte</button></form><?php endif; ?><?php if ($invoiceStatus === 'unpaid'): ?><form method="post" style="display:inline"><input type="hidden" name="billing_action" value="mark_paid"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($customer['id'], ENT_QUOTES); ?>"><input type="hidden" name="invoice_id" value="<?= htmlspecialchars($invoice['id'], ENT_QUOTES); ?>"><button class="btn bg-success" type="submit" onclick="return confirm('Tandai invoice lunas dan aktifkan semua layanan pelanggan?');"><i class="fa fa-check"></i> <?= $gatewayPaymentReceived ? ($activationFailed ? 'Coba Lagi Aktivasi' : 'Aktifkan Layanan') : 'Sudah Bayar'; ?></button></form><?php endif; ?><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$customers): ?><tr><td colspan="14" class="text-center">Belum ada data pelanggan.</td></tr><?php endif; ?><tr id="billingNoResults" style="display:none"><td colspan="14" class="text-center">Data billing tidak ditemukan.</td></tr></tbody></table></div>
  </div>
</div></div></div>
<script>
$(function(){
  function showBillingService(select){var option=$(select).find('option:selected'),row=$(select).closest('tr'),status=row.find('.billing-user-status');row.find('.billing-username').text(option.data('username')||'-');row.find('.billing-profile').text(option.data('profile')||'-');status.removeClass('text-success text-danger').addClass(option.data('status-class')).find('strong').text(option.data('user-status')||'-');}
  $('.billing-service-select').on('change',function(){showBillingService(this);});
  function filterBilling(){var search=$('#billingSearch').val().toLowerCase(),status=$('#billingStatus').val(),visible=0;$('.billing-row').each(function(){var row=$(this),text=row.text().toLowerCase()+' '+String(row.data('search')||''),show=text.indexOf(search)>-1&&(status==='all'||row.data('status')===status);row.toggle(show);if(show)visible++;});$('#billingVisibleCount').text(visible);$('#billingNoResults').toggle(visible===0&&$('.billing-row').length>0);}
  $('#billingSearch').on('input',filterBilling);$('#billingStatus').on('change',filterBilling);filterBilling();
});
</script>
