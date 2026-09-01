<?php
/**
 * Finalize a gateway payment by activating MikroTik services and advancing the
 * billing cycle. The operation is locked and idempotent per invoice.
 */

require_once dirname(__DIR__) . '/include/database.php';
require_once dirname(__DIR__) . '/lib/routeros_api.class.php';
require_once dirname(__DIR__) . '/lib/fonnte.php';
require_once dirname(__DIR__) . '/lib/billing_automation.php';
require_once dirname(__DIR__) . '/lib/billing_profile.php';

function mikhmonPaymentActivationApiError($response) {
  if (!is_array($response)) return 'Respons MikroTik tidak valid.';
  foreach (array('!trap', '!fatal') as $type) {
    if (isset($response[$type][0]['message'])) return (string) $response[$type][0]['message'];
  }
  return '';
}

function mikhmonPaymentActivationInvoiceServices($invoice, $customer) {
  if (!empty($invoice['services']) && is_array($invoice['services'])) return $invoice['services'];
  if (!empty($invoice['username'])) return array(array(
    'service' => ($invoice['service'] ?? '') === 'pppoe' ? 'pppoe' : 'hotspot',
    'username' => (string) $invoice['username'],
    'profile' => (string) ($invoice['profile'] ?? ''),
    'amount' => (float) ($invoice['amount'] ?? 0),
  ));
  return mikhmonCustomerServices($customer);
}

function mikhmonPaymentActivationRouterConfig($session) {
  $data = array();
  include dirname(__DIR__) . '/include/config.php';
  return isset($data[$session]) && is_array($data[$session]) ? $data[$session] : array();
}

function mikhmonPaymentActivationConnect($session) {
  $routerConfig = mikhmonPaymentActivationRouterConfig($session);
  $ip = explode('!', (string) ($routerConfig[1] ?? ''), 2)[1] ?? '';
  $username = explode('@|@', (string) ($routerConfig[2] ?? ''), 2)[1] ?? '';
  $password = explode('#|#', (string) ($routerConfig[3] ?? ''), 2)[1] ?? '';
  if ($ip === '' || $username === '' || $password === '') return array('api' => null, 'error' => 'Konfigurasi router tidak lengkap.');

  $api = new RouterosAPI();
  $api->debug = false;
  $api->attempts = 1;
  $api->timeout = 3;
  $api->delay = 0;
  if (!$api->connect($ip, $username, decrypt($password))) return array('api' => null, 'error' => 'Router MikroTik tidak terhubung.');
  return array('api' => $api, 'error' => '');
}

function mikhmonPaymentActivationFindRows($database, $session, $invoiceId) {
  $invoiceIndex = -1;
  $customerIndex = -1;
  $invoice = array();
  $customer = array();
  foreach ((array) ($database['invoices'][$session] ?? array()) as $index => $row) {
    if ((string) ($row['id'] ?? '') === (string) $invoiceId) { $invoiceIndex = $index; $invoice = $row; break; }
  }
  if ($invoiceIndex < 0) return array();
  foreach ((array) ($database['customers'][$session] ?? array()) as $index => $row) {
    if ((string) ($row['id'] ?? '') === (string) ($invoice['customer_id'] ?? '')) { $customerIndex = $index; $customer = mikhmonNormalizeCustomer($row); break; }
  }
  if ($customerIndex < 0) return array();
  return compact('invoiceIndex', 'customerIndex', 'invoice', 'customer');
}

function mikhmonPaymentActivationRecordFailure($session, $invoice, $message) {
  $invoice['activation_status'] = 'failed';
  $invoice['activation_last_error'] = substr(trim((string) $message), 0, 500);
  $invoice['activation_last_attempt_at'] = time();
  $invoice['activation_attempts'] = (int) ($invoice['activation_attempts'] ?? 0) + 1;
  mikhmonSaveInvoice($session, $invoice);
  return array('success' => false, 'message' => $invoice['activation_last_error'], 'invoice' => $invoice);
}

function mikhmonPaymentActivationProfileAllowed($api, $service) {
  $type = ($service['service'] ?? '') === 'pppoe' ? 'pppoe' : 'hotspot';
  $command = $type === 'pppoe' ? '/ppp/profile/print' : '/ip/hotspot/user/profile/print';
  $rows = $api->comm($command, array('?name' => (string) ($service['profile'] ?? '')));
  if (mikhmonPaymentActivationApiError($rows) !== '' || empty($rows[0])) return false;
  return mikhmonBillingProfileCanManage($type, $rows[0]);
}

function mikhmonPaymentActivationPrepareServices($api, $invoice, $customer) {
  $prepared = array();
  foreach (mikhmonPaymentActivationInvoiceServices($invoice, $customer) as $service) {
    $type = ($service['service'] ?? '') === 'pppoe' ? 'pppoe' : 'hotspot';
    $username = trim((string) ($service['username'] ?? ''));
    if ($username === '') return array('success' => false, 'message' => 'Username layanan kosong.');
    if (!mikhmonPaymentActivationProfileAllowed($api, $service)) return array('success' => false, 'message' => 'Profile layanan ' . $username . ' harus Expired Mode = None untuk dikelola Billing.');
    $command = $type === 'pppoe' ? '/ppp/secret' : '/ip/hotspot/user';
    $rows = $api->comm($command . '/print', array('?name' => $username));
    if (mikhmonPaymentActivationApiError($rows) !== '' || empty($rows[0]['.id'])) return array('success' => false, 'message' => 'User MikroTik ' . $username . ' tidak ditemukan.');
    $row = $rows[0];
    $prepared[] = array(
      'type' => $type,
      'username' => $username,
      'command' => $command,
      'id' => $row['.id'],
      'was_disabled' => isset($row['disabled']) && in_array($row['disabled'], array('true', 'yes'), true),
      'was_expired' => $type === 'hotspot' && isset($row['limit-uptime']) && $row['limit-uptime'] === '1s',
      'original_disabled' => isset($row['disabled']) && in_array($row['disabled'], array('true', 'yes'), true) ? 'yes' : 'no',
      'original_limit_uptime' => isset($row['limit-uptime']) ? (string) $row['limit-uptime'] : '0',
      'original_comment' => isset($row['comment']) ? (string) $row['comment'] : '',
    );
  }
  return array('success' => true, 'services' => $prepared);
}

function mikhmonPaymentActivationRollback($api, $changed) {
  foreach (array_reverse((array) $changed) as $service) {
    $args = array('.id' => $service['id'], 'disabled' => $service['original_disabled']);
    if ($service['type'] === 'hotspot') {
      $args['limit-uptime'] = $service['original_limit_uptime'];
      $args['comment'] = $service['original_comment'];
    }
    $api->comm($service['command'] . '/set', $args);
  }
}

function mikhmonPaymentActivationEnableServices($api, $prepared, $customer) {
  $changed = array();
  foreach ((array) $prepared as $service) {
    if (!$service['was_disabled'] && !$service['was_expired']) continue;
    $args = array('.id' => $service['id'], 'disabled' => 'no');
    if ($service['type'] === 'hotspot') {
      $args['limit-uptime'] = '0';
      $args['comment'] = 'up-' . (string) ($customer['name'] ?? '');
    }
    $response = $api->comm($service['command'] . '/set', $args);
    if (mikhmonPaymentActivationApiError($response) !== '') {
      mikhmonPaymentActivationRollback($api, $changed);
      return array('success' => false, 'message' => 'Gagal mengaktifkan user ' . $service['username'] . '.', 'changed' => array());
    }
    $changed[] = $service;
  }
  return array('success' => true, 'changed' => $changed);
}

function mikhmonPaymentActivationSchedulerName($customerId) {
  return 'mikhmon-customer-' . substr(md5((string) $customerId), 0, 12);
}

function mikhmonPaymentActivationRouterString($value) {
  return str_replace(array('\\', '"', '$', "\r", "\n"), array('\\\\', '\\"', '\\$', ' ', ' '), (string) $value);
}

function mikhmonPaymentActivationInstallScheduler($api, $customer, $dueAt, $fonnteConfig) {
  $name = mikhmonPaymentActivationSchedulerName($customer['id'] ?? '');
  $rows = $api->comm('/system/scheduler/print', array('?name' => $name));
  foreach ((array) $rows as $row) if (isset($row['.id'])) $api->comm('/system/scheduler/remove', array('.id' => $row['.id']));
  if (!empty($fonnteConfig['automation_enabled'])) return true;

  $script = '';
  foreach (mikhmonCustomerServices($customer) as $service) {
    $username = mikhmonPaymentActivationRouterString($service['username'] ?? '');
    if (($service['service'] ?? '') === 'pppoe') $script .= '/ppp active remove [find where name="' . $username . '"]; /ppp secret set [find where name="' . $username . '"] disabled=yes; ';
    else $script .= '/ip hotspot active remove [find where user="' . $username . '"]; /ip hotspot user set [find where name="' . $username . '"] disabled=yes; ';
  }
  $script .= '/system scheduler remove [find where name="' . mikhmonPaymentActivationRouterString($name) . '"];';
  $response = $api->comm('/system/scheduler/add', array(
    'name' => $name,
    'start-date' => strtolower(date('M/d/Y', $dueAt)),
    'start-time' => date('H:i:s', $dueAt),
    'interval' => '0s',
    'on-event' => $script,
    'disabled' => 'no',
    'comment' => 'Mikhmon customer due: ' . ($customer['name'] ?? ''),
  ));
  return mikhmonPaymentActivationApiError($response) === '';
}

function mikhmonPaymentActivationProcess($session, $invoiceId, $api = null, $options = array()) {
  $lockDirectory = dirname(mikhmonBackupPath());
  if (!is_dir($lockDirectory)) @mkdir($lockDirectory, 0700, true);
  $lockPath = $lockDirectory . '/payment-activation-' . sha1((string) $session . '|' . (string) $invoiceId) . '.lock';
  $lock = @fopen($lockPath, 'c');
  if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return array('success' => false, 'message' => 'Aktivasi invoice sedang diproses.');
  @chmod($lockPath, 0600);
  $ownedApi = false;

  try {
    $database = mikhmonReadDatabase();
    $rows = mikhmonPaymentActivationFindRows($database, $session, $invoiceId);
    if (!$rows) return array('success' => false, 'message' => 'Invoice atau pelanggan tidak ditemukan.');
    $invoice = $rows['invoice'];
    $customer = $rows['customer'];
    if (($invoice['status'] ?? '') === 'paid') return array('success' => true, 'already_paid' => true, 'message' => 'Invoice sudah dibayar.', 'invoice' => $invoice);
    if (($invoice['status'] ?? '') !== 'unpaid') return mikhmonPaymentActivationRecordFailure($session, $invoice, 'Status invoice tidak dapat diaktifkan.');
    if (empty($options['allow_manual']) && empty($invoice['gateway_payment_received'])) return mikhmonPaymentActivationRecordFailure($session, $invoice, 'Pembayaran gateway belum dikonfirmasi.');

    if (!is_object($api) || !method_exists($api, 'comm')) {
      $connection = mikhmonPaymentActivationConnect($session);
      if (!$connection['api']) return mikhmonPaymentActivationRecordFailure($session, $invoice, $connection['error']);
      $api = $connection['api'];
      $ownedApi = true;
    }

    $prepared = mikhmonPaymentActivationPrepareServices($api, $invoice, $customer);
    if (empty($prepared['success'])) return mikhmonPaymentActivationRecordFailure($session, $invoice, $prepared['message']);
    $activation = mikhmonPaymentActivationEnableServices($api, $prepared['services'], $customer);
    if (empty($activation['success'])) return mikhmonPaymentActivationRecordFailure($session, $invoice, $activation['message']);

    $now = time();
    $dueAt = mikhmonBillingAutomationDueTimestamp($invoice['due_date'] ?? '');
    $nextDueAt = mikhmonBillingAutomationNextDueTimestamp($dueAt > 0 ? $dueAt : $now);
    $nextDueDate = date('Y-m-d H:i:s', $nextDueAt);
    $provider = strtoupper((string) ($invoice['payment_gateway'] ?? 'gateway'));
    $invoice['status'] = 'paid';
    $invoice['paid_at'] = !empty($invoice['gateway_paid_at']) ? (int) $invoice['gateway_paid_at'] : $now;
    $invoice['paid_by_user_id'] = (string) ($options['paid_by_user_id'] ?? '');
    $invoice['paid_by_name'] = !empty($options['actor_name']) ? (string) $options['actor_name'] : 'Otomatis ' . $provider;
    $invoice['biller_commission'] = (float) ($options['biller_commission'] ?? 0);
    $invoice['next_due_date'] = $nextDueDate;
    $invoice['activation_status'] = 'success';
    $invoice['activation_last_attempt_at'] = $now;
    $invoice['activation_attempts'] = (int) ($invoice['activation_attempts'] ?? 0) + 1;
    $invoice['activated_at'] = $now;
    unset($invoice['activation_last_error']);

    $nextInvoice = array();
    foreach ((array) ($database['invoices'][$session] ?? array()) as $candidate) {
      if ((string) ($candidate['generated_from'] ?? '') === (string) ($invoice['id'] ?? '')) { $nextInvoice = $candidate; break; }
    }
    if (!$nextInvoice) {
      $nextInvoice = array(
        'id' => 'invoice-' . uniqid(),
        'number' => 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
        'customer_id' => $customer['id'],
        'customer_name' => $customer['name'] ?? '',
        'services' => mikhmonPaymentActivationInvoiceServices($invoice, $customer),
        'service_count' => count(mikhmonPaymentActivationInvoiceServices($invoice, $customer)),
        'amount' => (float) ($invoice['amount'] ?? 0),
        'due_date' => $nextDueDate,
        'status' => 'unpaid',
        'created_at' => $now,
        'generated_from' => $invoice['id'],
      );
      $database['invoices'][$session][] = $nextInvoice;
    }
    $invoice['next_invoice_id'] = $nextInvoice['id'];
    $fonnteConfig = mikhmonFonnteReadConfig();
    if (!empty($fonnteConfig['enabled']) && !empty($fonnteConfig['payment_enabled']) && $fonnteConfig['token'] !== '') $invoice['automation']['payment_notification_pending'] = true;
    $database['invoices'][$session][$rows['invoiceIndex']] = $invoice;
    $database['customers'][$session][$rows['customerIndex']]['due_date'] = $nextDueDate;
    $database['customers'][$session][$rows['customerIndex']]['updated_at'] = $now;

    if (!mikhmonWriteDatabase($database)) {
      mikhmonPaymentActivationRollback($api, $activation['changed']);
      return array('success' => false, 'message' => 'Layanan diaktifkan, tetapi status invoice gagal disimpan dan perubahan router dikembalikan.');
    }

    foreach ($activation['changed'] as $service) {
      if ($service['type'] === 'hotspot') $api->comm($service['command'] . '/reset-counters', array('.id' => $service['id']));
    }
    $schedulerInstalled = mikhmonPaymentActivationInstallScheduler($api, $customer, $nextDueAt, $fonnteConfig);
    if (!$schedulerInstalled) {
      $invoice['activation_warning'] = 'Scheduler jatuh tempo berikutnya gagal dipasang.';
      mikhmonSaveInvoice($session, $invoice);
    }
    return array(
      'success' => true,
      'message' => 'Pembayaran diproses dan ' . count($prepared['services']) . ' layanan berhasil diaktifkan.',
      'invoice' => $invoice,
      'next_invoice' => $nextInvoice,
      'scheduler_installed' => $schedulerInstalled,
    );
  } finally {
    if ($ownedApi && is_object($api) && method_exists($api, 'disconnect')) $api->disconnect();
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}
