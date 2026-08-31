<?php
/**
 * CLI billing automation shared by the scheduled worker.
 */

function mikhmonBillingAutomationDueTimestamp($value) {
  $value = trim((string) $value);
  if ($value === '') return 0;
  $months = array('jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12);
  $lower = strtolower($value);
  if (preg_match('/^([a-z]{3})\/(\d{1,2})(?:\/(\d{4}))?(?:\s+(\d{1,2}:\d{2}:\d{2}))?$/', $lower, $matches) && isset($months[$matches[1]])) {
    $year = !empty($matches[3]) ? (int) $matches[3] : (int) date('Y');
    $time = !empty($matches[4]) ? $matches[4] : '00:00:00';
    $timestamp = strtotime(sprintf('%04d-%02d-%02d %s', $year, $months[$matches[1]], (int) $matches[2], $time));
    if (empty($matches[3]) && $timestamp < time() - 86400) $timestamp = strtotime('+1 year', $timestamp);
    return $timestamp ?: 0;
  }
  return strtotime($value) ?: 0;
}

/**
 * Return the next upcoming billing date on the fixed monthly due day.
 * The day is intentionally centralized so the web UI and cron worker agree.
 */
function mikhmonBillingAutomationUpcomingDueTimestamp($now = null) {
  $now = $now === null ? time() : (int) $now;
  $candidate = mktime(0, 0, 0, (int) date('n', $now), 5, (int) date('Y', $now));
  if ($candidate <= $now) $candidate = mktime(0, 0, 0, (int) date('n', $now) + 1, 5, (int) date('Y', $now));
  return $candidate;
}

function mikhmonBillingAutomationNextDueTimestamp($baseTimestamp = null, $now = null) {
  $baseTimestamp = $baseTimestamp === null ? time() : (int) $baseTimestamp;
  if ($baseTimestamp <= 0) $baseTimestamp = time();
  // Advance exactly one billing cycle from the invoice being paid. A late
  // payment must not skip outstanding monthly periods to the current month.
  return mktime(0, 0, 0, (int) date('n', $baseTimestamp) + 1, 5, (int) date('Y', $baseTimestamp));
}

function mikhmonBillingAutomationIsWorkHour($timestamp = null) {
  $timestamp = $timestamp === null ? time() : (int) $timestamp;
  $hour = (int) date('G', $timestamp);
  return $hour >= 8 && $hour < 17;
}

function mikhmonBillingAutomationApiError($response) {
  if (!is_array($response)) return 'Respons router tidak valid.';
  foreach (array('!trap', '!fatal') as $type) if (isset($response[$type][0]['message'])) return (string) $response[$type][0]['message'];
  return '';
}

function mikhmonBillingAutomationInvoiceServices($invoice, $customer) {
  if (!empty($invoice['services']) && is_array($invoice['services'])) return $invoice['services'];
  if (!empty($invoice['username'])) return array(array(
    'service' => ($invoice['service'] ?? 'hotspot') === 'pppoe' ? 'pppoe' : 'hotspot',
    'username' => (string) $invoice['username'], 'profile' => (string) ($invoice['profile'] ?? ''),
    'amount' => (float) ($invoice['amount'] ?? 0),
  ));
  return mikhmonCustomerServices($customer);
}

function mikhmonBillingAutomationAmount($amount, $currency) {
  $indo = in_array($currency, array('RP','Rp','rp','IDR','idr','RP.','Rp.','rp.','IDR.','idr.'), true);
  return $currency . ' ' . number_format((float) $amount, $indo ? 0 : 2, $indo ? ',' : '.', $indo ? '.' : ',');
}

function mikhmonBillingAutomationMessage($template, $customer, $invoice, $currency, $brand, $dueDate, $nextDueDate = '') {
  $services = array();
  foreach (mikhmonBillingAutomationInvoiceServices($invoice, $customer) as $service) {
    $services[] = '- ' . strtoupper((string) ($service['service'] ?? 'hotspot')) . ' / ' . (string) ($service['username'] ?? '') . ' / ' . (string) ($service['profile'] ?? '') . ' / ' . mikhmonBillingAutomationAmount($service['amount'] ?? 0, $currency);
  }
  return mikhmonFonnteRenderTemplate($template, array(
    'nama_pelanggan' => $customer['name'] ?? '',
    'nama_brand' => $brand,
    'nomor_invoice' => $invoice['number'] ?? '',
    'total_tagihan' => mikhmonBillingAutomationAmount($invoice['amount'] ?? 0, $currency),
    'jatuh_tempo' => $invoice['due_date'] ?? $dueDate,
    'detail_layanan' => implode("\n", $services),
    'tanggal_bayar' => !empty($invoice['paid_at']) ? date('Y-m-d H:i:s', (int) $invoice['paid_at']) : date('Y-m-d H:i:s'),
    'jatuh_tempo_berikutnya' => $nextDueDate,
  ));
}

function mikhmonBillingAutomationSetServices($api, $customer, $disabled) {
  if (!is_object($api) || !method_exists($api, 'comm')) return false;
  $changed = array();
  foreach (mikhmonCustomerServices($customer) as $service) {
    $type = ($service['service'] ?? '') === 'pppoe' ? 'pppoe' : 'hotspot';
    $command = $type === 'pppoe' ? '/ppp/secret' : '/ip/hotspot/user';
    $username = trim((string) ($service['username'] ?? ''));
    if ($username === '') continue;
    $rows = $api->comm($command . '/print', array('?name' => $username));
    if (mikhmonBillingAutomationApiError($rows) !== '' || empty($rows[0]['.id'])) {
      foreach ($changed as $rollback) if (empty($rollback['was_disabled'])) $api->comm($rollback['command'] . '/set', array('.id' => $rollback['id'], 'disabled' => 'no'));
      return false;
    }
    if ($disabled) {
      $activeCommand = $type === 'pppoe' ? '/ppp/active' : '/ip/hotspot/active';
      $activeFilter = $type === 'pppoe' ? array('?name' => $username) : array('?user' => $username);
      $activeRows = $api->comm($activeCommand . '/print', $activeFilter);
      foreach ((array) $activeRows as $activeRow) if (isset($activeRow['.id'])) $api->comm($activeCommand . '/remove', array('.id' => $activeRow['.id']));
    }
    $args = array('.id' => $rows[0]['.id'], 'disabled' => $disabled ? 'yes' : 'no');
    if (!$disabled && $type === 'hotspot') $args['limit-uptime'] = '0';
    if (mikhmonBillingAutomationApiError($api->comm($command . '/set', $args)) !== '') {
      foreach ($changed as $rollback) if (empty($rollback['was_disabled'])) $api->comm($rollback['command'] . '/set', array('.id' => $rollback['id'], 'disabled' => 'no'));
      return false;
    }
    $changed[] = array(
      'command' => $command, 'id' => $rows[0]['.id'],
      'was_disabled' => isset($rows[0]['disabled']) && in_array($rows[0]['disabled'], array('true', 'yes'), true),
    );
  }
  return true;
}

function mikhmonBillingAutomationRemoveScheduler($api, $customer) {
  if (!is_object($api) || !method_exists($api, 'comm')) return false;
  $name = 'mikhmon-customer-' . substr(md5((string) ($customer['id'] ?? '')), 0, 12);
  $rows = $api->comm('/system/scheduler/print', array('?name' => $name));
  foreach ((array) $rows as $row) if (isset($row['.id'])) $api->comm('/system/scheduler/remove', array('.id' => $row['.id']));
  return true;
}

function mikhmonBillingAutomationLatestUnpaid($invoices, $customerId) {
  $latest = array();
  foreach ((array) $invoices as $invoice) {
    if (($invoice['status'] ?? '') !== 'unpaid' || (string) ($invoice['customer_id'] ?? '') !== (string) $customerId) continue;
    if (!$latest || (int) ($invoice['created_at'] ?? 0) > (int) ($latest['created_at'] ?? 0)) $latest = $invoice;
  }
  return $latest;
}

function mikhmonBillingAutomationLatestPaid($invoices, $customerId) {
  $latest = array();
  foreach ((array) $invoices as $invoice) {
    if (($invoice['status'] ?? '') !== 'paid' || (string) ($invoice['customer_id'] ?? '') !== (string) $customerId) continue;
    if (!$latest || (int) ($invoice['paid_at'] ?? $invoice['created_at'] ?? 0) >= (int) ($latest['paid_at'] ?? $latest['created_at'] ?? 0)) $latest = $invoice;
  }
  return $latest;
}

function mikhmonBillingAutomationEnsureUnpaidInvoice($session, &$invoices, $customer) {
  $customerId = (string) ($customer['id'] ?? '');
  $unpaid = mikhmonBillingAutomationLatestUnpaid($invoices, $customerId);
  if ($unpaid) return $unpaid;
  $paid = mikhmonBillingAutomationLatestPaid($invoices, $customerId);
  if (!$paid) return array();
  $paidDueAt = mikhmonBillingAutomationDueTimestamp($paid['due_date'] ?? '');
  $dueAt = mikhmonBillingAutomationNextDueTimestamp($paidDueAt > 0 ? $paidDueAt : ($paid['paid_at'] ?? time()));
  $dueDate = date('Y-m-d H:i:s', $dueAt);
  $nextInvoice = array(
    'id' => 'invoice-' . uniqid(), 'number' => 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
    'customer_id' => $customerId, 'customer_name' => $customer['name'] ?? '',
    'services' => mikhmonBillingAutomationInvoiceServices($paid, $customer),
    'service_count' => count(mikhmonBillingAutomationInvoiceServices($paid, $customer)),
    'amount' => (float) ($paid['amount'] ?? 0), 'due_date' => $dueDate,
    'status' => 'unpaid', 'created_at' => time(), 'generated_from' => $paid['id'] ?? '',
  );
  if (mikhmonSaveInvoice($session, $nextInvoice) === false) return array();
  $paid['next_invoice_id'] = $nextInvoice['id'];
  $paid['next_due_date'] = $dueDate;
  mikhmonSaveInvoice($session, $paid);
  $invoices[] = $nextInvoice;
  return $nextInvoice;
}

function mikhmonBillingAutomationProcessPaidNotification($session, &$invoices, $customersById, $invoice, $currency, $brand, $fonnteConfig, $now) {
  $customerId = (string) ($invoice['customer_id'] ?? '');
  if (!isset($customersById[$customerId])) return false;
  $message = mikhmonBillingAutomationMessage($fonnteConfig['templates']['payment'] ?? '', $customersById[$customerId], $invoice, $currency, $brand, $invoice['due_date'] ?? '', $invoice['next_due_date'] ?? '');
  $send = mikhmonFonnteSend($customersById[$customerId]['phone'] ?? '', $message, $fonnteConfig);
  if (!empty($send['status'])) {
    $invoice['automation']['payment_notification_pending'] = false;
    $invoice['automation']['payment_sent_at'] = $now;
    mikhmonBillingAutomationClearFailure($invoice, 'payment');
    mikhmonSaveInvoice($session, $invoice);
    return true;
  }
  mikhmonBillingAutomationRecordFailure($invoice, 'payment', $send['reason'] ?? 'Fonnte error', $now);
  mikhmonSaveInvoice($session, $invoice);
  return false;
}

function mikhmonBillingAutomationRecordFailure(&$invoice, $event, $reason, $now) {
  if (!isset($invoice['automation']) || !is_array($invoice['automation'])) $invoice['automation'] = array();
  $invoice['automation']['last_error_event'] = (string) $event;
  $invoice['automation']['last_error'] = substr(trim((string) $reason), 0, 500);
  $invoice['automation']['last_error_at'] = (int) $now;
}

function mikhmonBillingAutomationClearFailure(&$invoice, $event) {
  if (($invoice['automation']['last_error_event'] ?? '') !== (string) $event) return;
  unset($invoice['automation']['last_error_event'], $invoice['automation']['last_error'], $invoice['automation']['last_error_at']);
}

function mikhmonBillingAutomationProcessSession($api, $session, $routerConfig, $fonnteConfig) {
  $customers = mikhmonGetCustomers($session);
  $invoices = mikhmonGetInvoices($session);
  $brand = 'MIKHMON';
  include dirname(__DIR__) . '/include/brand.php';
  $brand = trim((string) ($brandname ?? 'MIKHMON')) ?: 'MIKHMON';
  $currency = explode('&', $routerConfig[6] ?? '&Rp', 2)[1] ?? 'Rp';
  $now = time();
  $workHours = mikhmonBillingAutomationIsWorkHour($now);
  $result = array('invoices' => 0, 'reminders' => 0, 'isolated' => 0, 'payments' => 0, 'errors' => 0);
  $customersById = array();
  foreach ($customers as $customer) {
    $customersById[(string) ($customer['id'] ?? '')] = $customer;
    if (!empty($fonnteConfig['automation_enabled'])) mikhmonBillingAutomationRemoveScheduler($api, $customer);
    $invoice = mikhmonBillingAutomationLatestUnpaid($invoices, $customer['id'] ?? '');
    if (!$invoice) {
      $invoice = mikhmonBillingAutomationEnsureUnpaidInvoice($session, $invoices, $customer);
      if ($invoice) $result['invoices']++;
    }
    if (!$invoice) continue;
    $dueAt = mikhmonBillingAutomationDueTimestamp($invoice['due_date'] ?? ($customer['due_date'] ?? ''));
    if ($dueAt <= 0) continue;
    $automation = isset($invoice['automation']) && is_array($invoice['automation']) ? $invoice['automation'] : array();
    $reminderAt = $dueAt - ((int) ($fonnteConfig['reminder_days'] ?? 7) * 86400);
    if ($workHours && !empty($fonnteConfig['automation_enabled']) && !empty($fonnteConfig['reminder_enabled']) && empty($automation['reminder_sent_at']) && $now >= $reminderAt && $now < $dueAt) {
      $message = mikhmonBillingAutomationMessage($fonnteConfig['templates']['reminder'] ?? '', $customer, $invoice, $currency, $brand, date('Y-m-d H:i:s', $dueAt));
      $send = mikhmonFonnteSend($customer['phone'] ?? '', $message, $fonnteConfig);
      if (!empty($send['status'])) { $invoice['automation']['reminder_sent_at'] = $now; mikhmonBillingAutomationClearFailure($invoice, 'reminder'); $result['reminders']++; }
      else { mikhmonBillingAutomationRecordFailure($invoice, 'reminder', $send['reason'] ?? 'Fonnte error', $now); $result['errors']++; }
    }
    $isolationAt = $dueAt + ((int) ($fonnteConfig['grace_days'] ?? 0) * 86400);
    if (!empty($fonnteConfig['automation_enabled']) && !empty($fonnteConfig['isolation_enabled']) && empty($automation['isolated_at']) && $now >= $isolationAt) {
      if (mikhmonBillingAutomationSetServices($api, $customer, true)) {
        $invoice['automation']['isolated_at'] = $now;
        mikhmonBillingAutomationClearFailure($invoice, 'isolation');
        $result['isolated']++;
      } else { mikhmonBillingAutomationRecordFailure($invoice, 'isolation', 'Gagal mengubah status layanan MikroTik.', $now); $result['errors']++; }
    }
    if ($workHours && !empty($fonnteConfig['automation_enabled']) && !empty($fonnteConfig['isolation_enabled']) && !empty($invoice['automation']['isolated_at']) && empty($invoice['automation']['isolation_sent_at'])) {
      $message = mikhmonBillingAutomationMessage($fonnteConfig['templates']['isolation'] ?? '', $customer, $invoice, $currency, $brand, date('Y-m-d H:i:s', $dueAt));
      $send = mikhmonFonnteSend($customer['phone'] ?? '', $message, $fonnteConfig);
      if (!empty($send['status'])) { $invoice['automation']['isolation_sent_at'] = $now; mikhmonBillingAutomationClearFailure($invoice, 'isolation_message'); }
      else { mikhmonBillingAutomationRecordFailure($invoice, 'isolation_message', $send['reason'] ?? 'Fonnte error', $now); $result['errors']++; }
    }
    if (!empty($invoice['automation']) && $invoice['automation'] !== ($automation ?? array())) {
      $saved = mikhmonSaveInvoice($session, $invoice);
      if ($saved === false) $result['errors']++;
      else foreach ($invoices as $index => $row) if (($row['id'] ?? '') === ($invoice['id'] ?? '')) { $invoices[$index] = $invoice; break; }
    }
  }
  if ($workHours && !empty($fonnteConfig['payment_enabled'])) foreach ($invoices as $invoice) {
    if (($invoice['status'] ?? '') !== 'paid' || empty($invoice['automation']['payment_notification_pending'])) continue;
    $customerId = (string) ($invoice['customer_id'] ?? '');
    if (!isset($customersById[$customerId])) { $result['errors']++; continue; }
    if (mikhmonBillingAutomationProcessPaidNotification($session, $invoices, $customersById, $invoice, $currency, $brand, $fonnteConfig, $now)) $result['payments']++;
    else $result['errors']++;
  }
  return $result;
}
