<?php
/**
 * CLI billing automation shared by the scheduled worker.
 */

require_once dirname(__DIR__) . '/ppp/profilemeta.php';
require_once dirname(__DIR__) . '/lib/billing_profile.php';
require_once dirname(__DIR__) . '/lib/payment_gateway.php';

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
  return $hour >= 7 && $hour < 17;
}

/** Return the next 07:00 boundary in the configured PHP timezone. */
function mikhmonBillingAutomationNextWorkStart($timestamp = null) {
  $timestamp = $timestamp === null ? time() : (int) $timestamp;
  $todayStart = mktime(7, 0, 0, (int) date('n', $timestamp), (int) date('j', $timestamp), (int) date('Y', $timestamp));
  if ($timestamp < $todayStart) return $todayStart;
  return mktime(7, 0, 0, (int) date('n', $timestamp), (int) date('j', $timestamp) + 1, (int) date('Y', $timestamp));
}

function mikhmonBillingAutomationQueuePath() {
  $override = getenv('MIKHMON_FONNTE_QUEUE');
  if ($override !== false && trim($override) !== '') return $override;
  return dirname(__DIR__) . '/data/fonnte-queue.json';
}

function mikhmonBillingAutomationReadQueue() {
  $path = mikhmonBillingAutomationQueuePath();
  $queue = is_file($path) ? json_decode((string) @file_get_contents($path), true) : array();
  return is_array($queue) ? $queue : array();
}

function mikhmonBillingAutomationWriteQueue($queue) {
  $path = mikhmonBillingAutomationQueuePath();
  $directory = dirname($path);
  if (!is_dir($directory)) @mkdir($directory, 0700, true);
  $temporary = $path . '.tmp.' . getmypid();
  if (@file_put_contents($temporary, json_encode((array) $queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) return false;
  @chmod($temporary, 0600);
  if (!@rename($temporary, $path)) { @unlink($temporary); return false; }
  @chmod($path, 0600);
  return true;
}

function mikhmonBillingAutomationNextQueueAttempt($now, $delayMinutes) {
  $candidate = (int) $now + (max(1, (int) $delayMinutes) * 60);
  return mikhmonBillingAutomationIsWorkHour($candidate) ? $candidate : mikhmonBillingAutomationNextWorkStart($candidate);
}

/**
 * Send one automatic message when its global queue slot is available. The
 * caller records the event on its invoice only after a successful response.
 */
function mikhmonBillingAutomationQueuedSend($target, $message, $config, $now = null) {
  static $attemptedThisRun = false;
  $now = $now === null ? time() : (int) $now;
  if (!mikhmonBillingAutomationIsWorkHour($now)) return array('attempted' => false, 'status' => false, 'reason' => 'Di luar jam kerja.');
  if ($attemptedThisRun) return array('attempted' => false, 'status' => false, 'reason' => 'Menunggu putaran worker berikutnya.');
  $queue = mikhmonBillingAutomationReadQueue();
  $nextAt = (int) ($queue['next_attempt_at'] ?? 0);
  if ($nextAt > $now) return array('attempted' => false, 'status' => false, 'reason' => 'Menunggu slot antrean berikutnya.');
  $attemptedThisRun = true;
  $minimum = max(1, min(120, (int) ($config['queue_min_delay_minutes'] ?? 5)));
  $maximum = max($minimum, min(240, (int) ($config['queue_max_delay_minutes'] ?? 20)));
  try { $delayMinutes = random_int($minimum, $maximum); } catch (Exception $exception) { $delayMinutes = $minimum; }
  $candidate = mikhmonBillingAutomationNextQueueAttempt($now, $delayMinutes);
  $queue = array(
    'last_attempt_at' => $now,
    'last_status' => 'sending',
    'next_attempt_at' => $candidate,
  );
  if (!mikhmonBillingAutomationWriteQueue($queue)) return array('attempted' => true, 'status' => false, 'reason' => 'Antrean Fonnte gagal disimpan.');
  $send = mikhmonFonnteSend($target, $message, $config);
  $queue['last_status'] = !empty($send['status']) ? 'sent' : 'failed';
  mikhmonBillingAutomationWriteQueue($queue);
  $send['attempted'] = true;
  return $send;
}

function mikhmonBillingAutomationPaymentWindowOpen($dueAt, $reminderDays, $now = null) {
  $now = $now === null ? time() : (int) $now;
  $dueAt = (int) $dueAt;
  if ($dueAt <= 0) return false;
  return $now >= $dueAt - (max(1, (int) $reminderDays) * 86400);
}

function mikhmonBillingAutomationRetryReady($invoice, $event, $now, $delaySeconds = 3600) {
  $automation = isset($invoice['automation']) && is_array($invoice['automation']) ? $invoice['automation'] : array();
  if (($automation['last_error_event'] ?? '') !== (string) $event) return true;
  return (int) ($automation['last_error_at'] ?? 0) + max(60, (int) $delaySeconds) <= (int) $now;
}

function mikhmonBillingAutomationApiError($response) {
  if (!is_array($response)) return 'Respons router tidak valid.';
  foreach (array('!trap', '!fatal') as $type) if (isset($response[$type][0]['message'])) return (string) $response[$type][0]['message'];
  return '';
}

function mikhmonBillingAutomationIsMonthlyInvoice($invoice) {
  return ($invoice['kind'] ?? 'monthly') !== 'voucher';
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

function mikhmonBillingAutomationMessage($template, $customer, $invoice, $currency, $brand, $dueDate, $nextDueDate = '', $includePaymentLink = true) {
  $services = array();
  foreach (mikhmonBillingAutomationInvoiceServices($invoice, $customer) as $service) {
    $services[] = '- ' . strtoupper((string) ($service['service'] ?? 'hotspot')) . ' / ' . (string) ($service['username'] ?? '') . ' / ' . (string) ($service['profile'] ?? '') . ' / ' . mikhmonBillingAutomationAmount($service['amount'] ?? 0, $currency);
  }
  $message = mikhmonFonnteRenderTemplate($template, array(
    'nama_pelanggan' => $customer['name'] ?? '',
    'nama_brand' => $brand,
    'nomor_invoice' => $invoice['number'] ?? '',
    'total_tagihan' => mikhmonBillingAutomationAmount($invoice['amount'] ?? 0, $currency),
    'jatuh_tempo' => $invoice['due_date'] ?? $dueDate,
    'detail_layanan' => implode("\n", $services),
    'tanggal_bayar' => !empty($invoice['paid_at']) ? date('Y-m-d H:i:s', (int) $invoice['paid_at']) : date('Y-m-d H:i:s'),
    'jatuh_tempo_berikutnya' => $nextDueDate,
    'link_pembayaran' => $invoice['payment_url'] ?? '',
  ));
  $paymentUrl = trim((string) ($invoice['payment_url'] ?? ''));
  if ($includePaymentLink && $paymentUrl !== '' && strpos($message, $paymentUrl) === false) $message .= "\n\nLink Pembayaran: " . $paymentUrl;
  return $message;
}

/** Create (and optionally deliver) one payment link for an automated invoice. */
function mikhmonBillingAutomationEnsurePaymentLink($session, &$invoice, $customer, $currency, $brand, $fonnteConfig, $paymentGatewayConfig, $now = null, $sendMessage = true) {
  $now = $now === null ? time() : (int) $now;
  $result = array('created' => false, 'sent' => false, 'error' => '');
  if (($invoice['status'] ?? '') !== 'unpaid' || !empty($invoice['gateway_payment_received'])) return $result;
  if (empty($fonnteConfig['payment_link_enabled']) || empty($paymentGatewayConfig['enabled']) || empty($paymentGatewayConfig['midtrans']['enabled']) || empty($paymentGatewayConfig['midtrans']['server_key'])) return $result;

  $provider = (string) ($invoice['payment_gateway'] ?? '');
  $storedEnvironment = (string) ($invoice['payment_environment'] ?? '');
  if ($provider === 'midtrans' && $storedEnvironment === '') $storedEnvironment = mikhmonPaymentGatewayMidtransUrlEnvironment($invoice['payment_url'] ?? '');
  $environmentChanged = $provider === 'midtrans' && $storedEnvironment !== '' && $storedEnvironment !== (string) ($paymentGatewayConfig['midtrans']['environment'] ?? 'sandbox');
  $expired = !empty($invoice['payment_created_at']) && (int) $invoice['payment_created_at'] + (int) ($paymentGatewayConfig['invoice_duration'] ?? 86400) <= $now;
  $hasUsableLink = !empty($invoice['payment_url']) && !$environmentChanged && !$expired;
  if (!$hasUsableLink) {
    $orderId = ($invoice['number'] ?? $invoice['id'] ?? 'invoice') . '-' . strtoupper(substr(uniqid(), -6));
    $payment = mikhmonPaymentGatewayCreatePayment('midtrans', array(
      'order_id' => $orderId,
      'amount' => $invoice['amount'] ?? 0,
      'description' => 'Invoice ' . ($invoice['number'] ?? $invoice['id'] ?? ''),
      'customer_name' => $customer['name'] ?? 'Pelanggan',
      'phone' => $customer['phone'] ?? '',
    ), $paymentGatewayConfig);
    if (empty($payment['success'])) {
      $result['error'] = (string) ($payment['message'] ?? 'Link pembayaran gagal dibuat.');
      $invoice['automation']['payment_link_last_error'] = substr($result['error'], 0, 500);
      $invoice['automation']['payment_link_last_attempt_at'] = $now;
      return $result;
    }
    $invoice['payment_gateway'] = $payment['provider'];
    $invoice['payment_environment'] = $payment['environment'] ?? '';
    $invoice['payment_order_id'] = $orderId;
    $invoice['payment_url'] = (string) $payment['payment_url'];
    $invoice['payment_reference'] = $payment['reference'] ?? '';
    $invoice['payment_created_at'] = $now;
    unset($invoice['automation']['payment_link_last_error']);
    $result['created'] = true;
  }

  $lastLinkAttempt = (int) ($invoice['automation']['payment_link_last_attempt_at'] ?? 0);
  $linkRetryReady = $lastLinkAttempt <= 0 || $lastLinkAttempt + 3600 <= $now;
  if ($sendMessage && $linkRetryReady && empty($invoice['automation']['payment_link_sent_at']) && !empty($fonnteConfig['enabled']) && !empty($fonnteConfig['token'])) {
    $message = mikhmonBillingAutomationMessage($fonnteConfig['templates']['reminder'] ?? '', $customer, $invoice, $currency, $brand, $invoice['due_date'] ?? '');
    $send = mikhmonBillingAutomationQueuedSend($customer['phone'] ?? '', $message, $fonnteConfig, $now);
    if (!empty($send['status'])) {
      $invoice['automation']['payment_link_sent_at'] = $now;
      unset($invoice['automation']['payment_link_last_error'], $invoice['automation']['payment_link_last_attempt_at']);
      $result['sent'] = true;
    } elseif (!empty($send['attempted'])) {
      $result['error'] = (string) ($send['reason'] ?? 'Link pembayaran gagal dikirim melalui Fonnte.');
      $invoice['automation']['payment_link_last_error'] = substr($result['error'], 0, 500);
      $invoice['automation']['payment_link_last_attempt_at'] = $now;
    }
  }
  if (!mikhmonSaveInvoice($session, $invoice)) $result['error'] = 'Link pembayaran dibuat, tetapi invoice gagal disimpan.';
  return $result;
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
    if (!mikhmonBillingAutomationIsMonthlyInvoice($invoice)) continue;
    if (($invoice['status'] ?? '') !== 'unpaid' || (string) ($invoice['customer_id'] ?? '') !== (string) $customerId) continue;
    if (!$latest || (int) ($invoice['created_at'] ?? 0) > (int) ($latest['created_at'] ?? 0)) $latest = $invoice;
  }
  return $latest;
}

function mikhmonBillingAutomationLatestPaid($invoices, $customerId) {
  $latest = array();
  foreach ((array) $invoices as $invoice) {
    if (!mikhmonBillingAutomationIsMonthlyInvoice($invoice)) continue;
    if (($invoice['status'] ?? '') !== 'paid' || (string) ($invoice['customer_id'] ?? '') !== (string) $customerId) continue;
    if (!$latest || (int) ($invoice['paid_at'] ?? $invoice['created_at'] ?? 0) >= (int) ($latest['paid_at'] ?? $latest['created_at'] ?? 0)) $latest = $invoice;
  }
  return $latest;
}

function mikhmonBillingAutomationInitialService($service, $serviceRow, $hotspotProfiles, $pppoeProfiles) {
  $profileName = (string) ($serviceRow['profile'] ?? '');
  $profiles = $service === 'pppoe' ? $pppoeProfiles : $hotspotProfiles;
  foreach ((array) $profiles as $profile) {
    if ((string) ($profile['name'] ?? '') !== $profileName) continue;
    $price = ''; $sellingPrice = ''; $validity = '';
    if ($service === 'pppoe') {
      $meta = pppProfileMetaDecode($profile['comment'] ?? '');
      $price = $meta['price']; $sellingPrice = $meta['selling-price']; $validity = $meta['validity'];
    } elseif (preg_match('/,([^,]*),([^,]*),([^,]*),([^,]*),/', (string) ($profile['on-login'] ?? ''), $matches)) {
      $price = $matches[2]; $validity = $matches[3]; $sellingPrice = $matches[4];
    }
    if (!mikhmonBillingProfileCanManage($service, $profile)) return array();
    $amount = (float) ($sellingPrice !== '' ? $sellingPrice : $price);
    if ($amount <= 0) return array();
    return array(
      'id' => $serviceRow['id'] ?? '', 'service' => $service, 'username' => (string) ($serviceRow['username'] ?? ''),
      'profile' => $profileName, 'amount' => $amount, 'validity' => $validity,
      'due_date' => '',
    );
  }
  return array();
}

function mikhmonBillingAutomationEnsureInitialInvoice($api, $session, &$invoices, $customer, $dueAt) {
  if (!is_object($api) || !method_exists($api, 'comm')) return array();
  $customerId = (string) ($customer['id'] ?? '');
  if ($customerId === '' || mikhmonBillingAutomationLatestUnpaid($invoices, $customerId)) return array();
  static $profileCache = array();
  $apiKey = function_exists('spl_object_hash') ? spl_object_hash($api) : 'default';
  if (!isset($profileCache[$apiKey])) $profileCache[$apiKey] = array(
    'api' => $api,
    'hotspot' => $api->comm('/ip/hotspot/user/profile/print'),
    'pppoe' => $api->comm('/ppp/profile/print'),
  );
  $hotspotProfiles = $profileCache[$apiKey]['hotspot'];
  $pppoeProfiles = $profileCache[$apiKey]['pppoe'];
  if (mikhmonBillingAutomationApiError($hotspotProfiles) !== '' || mikhmonBillingAutomationApiError($pppoeProfiles) !== '') return array();
  $services = array(); $amount = 0;
  foreach (mikhmonCustomerServices($customer) as $serviceRow) {
    $service = ($serviceRow['service'] ?? '') === 'pppoe' ? 'pppoe' : 'hotspot';
    $detail = mikhmonBillingAutomationInitialService($service, $serviceRow, $hotspotProfiles, $pppoeProfiles);
    if (!$detail) return array();
    $detail['due_date'] = date('Y-m-d H:i:s', $dueAt);
    $services[] = $detail; $amount += (float) $detail['amount'];
  }
  if (!$services || $amount <= 0) return array();
  $invoice = array(
    'id' => 'invoice-' . uniqid(), 'number' => 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
    'customer_id' => $customerId, 'customer_name' => $customer['name'] ?? '',
    'services' => $services, 'service_count' => count($services), 'amount' => $amount,
    'due_date' => date('Y-m-d H:i:s', $dueAt), 'status' => 'unpaid', 'created_at' => time(),
    'generated_from' => 'bootstrap',
  );
  if (mikhmonSaveInvoice($session, $invoice) === false) return array();
  $invoices[] = $invoice;
  return $invoice;
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
  if (!mikhmonBillingAutomationRetryReady($invoice, 'payment', $now)) return null;
  $message = mikhmonBillingAutomationMessage($fonnteConfig['templates']['payment'] ?? '', $customersById[$customerId], $invoice, $currency, $brand, $invoice['due_date'] ?? '', $invoice['next_due_date'] ?? '', false);
  $send = mikhmonBillingAutomationQueuedSend($customersById[$customerId]['phone'] ?? '', $message, $fonnteConfig, $now);
  if (!empty($send['status'])) {
    $invoice['automation']['payment_notification_pending'] = false;
    $invoice['automation']['payment_sent_at'] = $now;
    mikhmonBillingAutomationClearFailure($invoice, 'payment');
    mikhmonSaveInvoice($session, $invoice);
    return true;
  }
  if (!empty($send['attempted'])) {
    mikhmonBillingAutomationRecordFailure($invoice, 'payment', $send['reason'] ?? 'Fonnte error', $now);
    mikhmonSaveInvoice($session, $invoice);
    return false;
  }
  return null;
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

/**
 * Recover recent paid invoices that bypassed the normal activation flow.
 * The age limit prevents legacy payments from suddenly sending notifications
 * after an upgrade, while still covering outages and bulk updates.
 */
function mikhmonBillingAutomationReconcilePaymentNotifications($session, &$invoices, $customersById, $fonnteConfig, $now = null, $maxAgeSeconds = 604800) {
  $now = $now === null ? time() : (int) $now;
  if (empty($fonnteConfig['enabled']) || empty($fonnteConfig['payment_enabled']) || trim((string) ($fonnteConfig['token'] ?? '')) === '') return 0;
  $queued = 0;
  foreach ($invoices as $index => $invoice) {
    if (!mikhmonBillingAutomationIsMonthlyInvoice($invoice)) continue;
    if (($invoice['status'] ?? '') !== 'paid') continue;
    $automation = isset($invoice['automation']) && is_array($invoice['automation']) ? $invoice['automation'] : array();
    if (!empty($automation['payment_sent_at']) || !empty($automation['payment_notification_pending']) || !empty($automation['payment_notification_skipped_at'])) continue;
    $paidAt = (int) ($invoice['paid_at'] ?? 0);
    if ($paidAt <= 0 || $paidAt > $now + 300 || $paidAt < $now - max(3600, (int) $maxAgeSeconds)) continue;
    $customerId = (string) ($invoice['customer_id'] ?? '');
    $customer = $customersById[$customerId] ?? array();
    if (preg_replace('/[^0-9]/', '', (string) ($customer['phone'] ?? '')) === '') continue;
    $invoice['automation']['payment_notification_pending'] = true;
    $invoice['automation']['payment_notification_queued_at'] = $now;
    // A payment supersedes any stale reminder/isolation delivery failure.
    if (in_array($invoice['automation']['last_error_event'] ?? '', array('reminder', 'isolation', 'isolation_message'), true)) {
      unset($invoice['automation']['last_error_event'], $invoice['automation']['last_error'], $invoice['automation']['last_error_at']);
    }
    if (mikhmonSaveInvoice($session, $invoice) === false) continue;
    $invoices[$index] = $invoice;
    $queued++;
  }
  return $queued;
}

function mikhmonBillingAutomationProcessSession($api, $session, $routerConfig, $fonnteConfig) {
  $customers = mikhmonGetCustomers($session);
  $invoices = mikhmonGetInvoices($session);
  $brand = 'MIKHMON';
  include dirname(__DIR__) . '/include/brand.php';
  $brand = trim((string) ($brandname ?? 'MIKHMON')) ?: 'MIKHMON';
  $currency = explode('&', $routerConfig[6] ?? '&Rp', 2)[1] ?? 'Rp';
  $paymentGatewayConfig = mikhmonPaymentGatewayReadConfig();
  $now = time();
  $workHours = mikhmonBillingAutomationIsWorkHour($now);
  $result = array('invoices' => 0, 'payment_links' => 0, 'reminders' => 0, 'isolated' => 0, 'payments_queued' => 0, 'payments' => 0, 'errors' => 0);
  $customersById = array();
  foreach ($customers as $customer) $customersById[(string) ($customer['id'] ?? '')] = $customer;
  $result['payments_queued'] = mikhmonBillingAutomationReconcilePaymentNotifications($session, $invoices, $customersById, $fonnteConfig, $now);
  foreach ($customers as $customer) {
    if (!empty($fonnteConfig['automation_enabled'])) mikhmonBillingAutomationRemoveScheduler($api, $customer);
    $invoice = mikhmonBillingAutomationLatestUnpaid($invoices, $customer['id'] ?? '');
    if (!$invoice) {
      $invoice = mikhmonBillingAutomationEnsureUnpaidInvoice($session, $invoices, $customer);
      if ($invoice) $result['invoices']++;
    }
    if (!$invoice && !empty($fonnteConfig['automation_enabled'])) {
      $customerDueAt = mikhmonBillingAutomationDueTimestamp($customer['due_date'] ?? '');
      if ($customerDueAt <= 0) $customerDueAt = mikhmonBillingAutomationUpcomingDueTimestamp($now);
      $reminderAt = $customerDueAt - ((int) ($fonnteConfig['reminder_days'] ?? 7) * 86400);
      if ($now >= $reminderAt) {
        $invoice = mikhmonBillingAutomationEnsureInitialInvoice($api, $session, $invoices, $customer, $customerDueAt);
        if ($invoice) $result['invoices']++;
      }
    }
    if (!$invoice) continue;
    $dueAt = mikhmonBillingAutomationDueTimestamp($invoice['due_date'] ?? ($customer['due_date'] ?? ''));
    if ($dueAt <= 0) continue;
    $reminderAt = $dueAt - ((int) ($fonnteConfig['reminder_days'] ?? 7) * 86400);
    if (!empty($fonnteConfig['payment_link_enabled']) && mikhmonBillingAutomationPaymentWindowOpen($dueAt, $fonnteConfig['reminder_days'] ?? 7, $now)) {
      $linkResult = mikhmonBillingAutomationEnsurePaymentLink($session, $invoice, $customer, $currency, $brand, $fonnteConfig, $paymentGatewayConfig, $now);
      if (!empty($linkResult['sent'])) $result['payment_links']++;
      if (!empty($linkResult['error'])) $result['errors']++;
      foreach ($invoices as $index => $row) if (($row['id'] ?? '') === ($invoice['id'] ?? '')) { $invoices[$index] = $invoice; break; }
    }
    $automation = isset($invoice['automation']) && is_array($invoice['automation']) ? $invoice['automation'] : array();
    if ($workHours && !empty($fonnteConfig['automation_enabled']) && !empty($fonnteConfig['reminder_enabled']) && empty($automation['reminder_sent_at']) && empty($automation['payment_link_sent_at']) && mikhmonBillingAutomationRetryReady($invoice, 'reminder', $now) && $now >= $reminderAt && $now < $dueAt) {
      $message = mikhmonBillingAutomationMessage($fonnteConfig['templates']['reminder'] ?? '', $customer, $invoice, $currency, $brand, date('Y-m-d H:i:s', $dueAt));
      $send = mikhmonBillingAutomationQueuedSend($customer['phone'] ?? '', $message, $fonnteConfig, $now);
      if (!empty($send['status'])) { $invoice['automation']['reminder_sent_at'] = $now; mikhmonBillingAutomationClearFailure($invoice, 'reminder'); $result['reminders']++; }
      elseif (!empty($send['attempted'])) { mikhmonBillingAutomationRecordFailure($invoice, 'reminder', $send['reason'] ?? 'Fonnte error', $now); $result['errors']++; }
    }
    $isolationAt = $dueAt + ((int) ($fonnteConfig['grace_days'] ?? 0) * 86400);
    if (!empty($fonnteConfig['automation_enabled']) && !empty($fonnteConfig['isolation_enabled']) && empty($automation['isolated_at']) && $now >= $isolationAt) {
      if (mikhmonBillingAutomationSetServices($api, $customer, true)) {
        $invoice['automation']['isolated_at'] = $now;
        mikhmonBillingAutomationClearFailure($invoice, 'isolation');
        $result['isolated']++;
      } else { mikhmonBillingAutomationRecordFailure($invoice, 'isolation', 'Gagal mengubah status layanan MikroTik.', $now); $result['errors']++; }
    }
    if ($workHours && !empty($fonnteConfig['automation_enabled']) && !empty($fonnteConfig['isolation_enabled']) && !empty($invoice['automation']['isolated_at']) && empty($invoice['automation']['isolation_sent_at']) && mikhmonBillingAutomationRetryReady($invoice, 'isolation_message', $now)) {
      $message = mikhmonBillingAutomationMessage($fonnteConfig['templates']['isolation'] ?? '', $customer, $invoice, $currency, $brand, date('Y-m-d H:i:s', $dueAt));
      $send = mikhmonBillingAutomationQueuedSend($customer['phone'] ?? '', $message, $fonnteConfig, $now);
      if (!empty($send['status'])) { $invoice['automation']['isolation_sent_at'] = $now; mikhmonBillingAutomationClearFailure($invoice, 'isolation_message'); }
      elseif (!empty($send['attempted'])) { mikhmonBillingAutomationRecordFailure($invoice, 'isolation_message', $send['reason'] ?? 'Fonnte error', $now); $result['errors']++; }
    }
    if (!empty($invoice['automation']) && $invoice['automation'] !== ($automation ?? array())) {
      $saved = mikhmonSaveInvoice($session, $invoice);
      if ($saved === false) $result['errors']++;
      else foreach ($invoices as $index => $row) if (($row['id'] ?? '') === ($invoice['id'] ?? '')) { $invoices[$index] = $invoice; break; }
    }
  }
  if ($workHours && !empty($fonnteConfig['payment_enabled'])) foreach ($invoices as $invoice) {
    if (!mikhmonBillingAutomationIsMonthlyInvoice($invoice)) continue;
    if (($invoice['status'] ?? '') !== 'paid' || empty($invoice['automation']['payment_notification_pending'])) continue;
    $customerId = (string) ($invoice['customer_id'] ?? '');
    if (!isset($customersById[$customerId])) { $result['errors']++; continue; }
    $paymentResult = mikhmonBillingAutomationProcessPaidNotification($session, $invoices, $customersById, $invoice, $currency, $brand, $fonnteConfig, $now);
    if ($paymentResult === true) $result['payments']++;
    elseif ($paymentResult === false) $result['errors']++;
  }
  return $result;
}
