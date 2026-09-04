<?php
/** Customer self-service helpers: OTP login, vouchers and monthly billing. */

require_once dirname(__DIR__) . '/include/database.php';
require_once dirname(__DIR__) . '/lib/fonnte.php';
require_once dirname(__DIR__) . '/lib/payment_gateway.php';
require_once dirname(__DIR__) . '/lib/payment_activation.php';
require_once dirname(__DIR__) . '/lib/billing_profile.php';
require_once dirname(__DIR__) . '/ppp/profilemeta.php';

function mikhmonCustomerPortalOtp($phone, $ttl = 300) {
  $customer = mikhmonFindCustomerByPhone($phone);
  if (!$customer) return array('success' => false, 'message' => 'Nomor handphone belum terdaftar sebagai pelanggan.');
  $config = mikhmonFonnteReadConfig();
  if (empty($config['enabled']) || $config['token'] === '') return array('success' => false, 'message' => 'Fonnte belum aktif. Hubungi administrator.');
  $otp = (string) random_int(100000, 999999);
  if (!mikhmonSaveCustomerOtp($phone, $otp, time() + max(60, (int) $ttl))) return array('success' => false, 'message' => 'OTP gagal disimpan.');
  $result = mikhmonFonnteSend($phone, 'Kode OTP login ' . ($GLOBALS['brandname'] ?? 'Mikhmon') . ': ' . $otp . '. Berlaku 5 menit.', $config);
  if (empty($result['status'])) return array('success' => false, 'message' => (string) ($result['reason'] ?? 'OTP gagal dikirim.'));
  return array('success' => true, 'message' => 'OTP telah dikirim ke WhatsApp Anda.', 'expires_at' => time() + max(60, (int) $ttl), 'customer' => $customer);
}

function mikhmonCustomerPortalFind($session, $customerId) {
  $customer = mikhmonFindCustomer($session, $customerId);
  return $customer ?: array();
}

function mikhmonCustomerPortalProfileDetails($profile) {
  $parts = explode(',', (string) ($profile['on-login'] ?? ''));
  $price = isset($parts[2]) ? (float) preg_replace('/[^0-9.]/', '', $parts[2]) : 0;
  $selling = isset($parts[4]) ? (float) preg_replace('/[^0-9.]/', '', $parts[4]) : 0;
  return array(
    'name' => (string) ($profile['name'] ?? ''),
    'price' => $price,
    'selling_price' => $selling > 0 ? $selling : $price,
    'validity' => isset($parts[3]) ? trim((string) $parts[3]) : '',
    'expired_mode' => mikhmonBillingProfileExpiredMode('hotspot', $profile),
    'server' => 'all',
  );
}

function mikhmonCustomerPortalVoucherProfiles($api) {
  if (!is_object($api) || !method_exists($api, 'comm')) return array();
  $rows = $api->comm('/ip/hotspot/user/profile/print');
  $profiles = array();
  foreach ((array) $rows as $row) {
    if (!isset($row['name'])) continue;
    $details = mikhmonCustomerPortalProfileDetails($row);
    if ($details['expired_mode'] === 'none' || $details['selling_price'] < 1) continue;
    $profiles[] = array_merge($details, array('row' => $row));
  }
  return $profiles;
}

function mikhmonCustomerPortalVoucherValidityLabel($validity) {
  $validity = trim((string) $validity);
  if ($validity === '') return '';
  if (preg_match('/^(\d+)\s*(m|min|minute|minutes)$/i', $validity, $match)) return $match[1] . ' menit';
  if (preg_match('/^(\d+)\s*(h|hr|hour|hours|jam)$/i', $validity, $match)) return $match[1] . ' jam';
  if (preg_match('/^(\d+)\s*(d|day|days|hari)$/i', $validity, $match)) return $match[1] . ' hari';
  if (preg_match('/^(\d+)\s*(w|week|weeks|minggu)$/i', $validity, $match)) return $match[1] . ' minggu';
  return $validity;
}

function mikhmonCustomerPortalVoucherAvailable($api, $username) {
  $username = trim((string) $username);
  if ($username === '' || !is_object($api) || !method_exists($api, 'comm')) return false;
  $rows = $api->comm('/ip/hotspot/user/print', array('?name' => $username));
  if (mikhmonPaymentActivationApiError($rows) !== '' || empty($rows[0])) return false;
  $disabled = strtolower(trim((string) ($rows[0]['disabled'] ?? 'no')));
  return !in_array($disabled, array('yes', 'true'), true);
}

function mikhmonCustomerPortalMonthlyServices($customer, $api) {
  if (!is_object($api) || !method_exists($api, 'comm')) return array();
  $hotspotProfiles = array(); $pppoeProfiles = array();
  foreach ((array) $api->comm('/ip/hotspot/user/profile/print') as $row) if (isset($row['name'])) $hotspotProfiles[(string) $row['name']] = $row;
  foreach ((array) $api->comm('/ppp/profile/print') as $row) if (isset($row['name'])) $pppoeProfiles[(string) $row['name']] = $row;
  $services = array();
  foreach (mikhmonCustomerServices($customer) as $service) {
    $type = ($service['service'] ?? '') === 'pppoe' ? 'pppoe' : 'hotspot';
    $profile = $type === 'pppoe' ? ($pppoeProfiles[(string) ($service['profile'] ?? '')] ?? array()) : ($hotspotProfiles[(string) ($service['profile'] ?? '')] ?? array());
    if (!$profile || !mikhmonBillingProfileCanManage($type, $profile)) continue;
    if ($type === 'pppoe') {
      $meta = pppProfileMetaDecode($profile['comment'] ?? '');
      $amount = (float) (($meta['selling-price'] ?? '') !== '' ? $meta['selling-price'] : ($meta['price'] ?? 0));
      $validity = (string) ($meta['validity'] ?? '');
    } else {
      $details = mikhmonCustomerPortalProfileDetails($profile);
      $amount = $details['selling_price']; $validity = $details['validity'];
    }
    if ($amount < 1) continue;
    $services[] = array('id' => $service['id'] ?? '', 'service' => $type, 'username' => $service['username'], 'profile' => $service['profile'], 'amount' => $amount, 'validity' => $validity);
  }
  return $services;
}

function mikhmonCustomerPortalLatestInvoice($session, $customerId, $kind = '') {
  $latest = array();
  foreach (mikhmonGetInvoices($session) as $invoice) {
    if ((string) ($invoice['customer_id'] ?? '') !== (string) $customerId) continue;
    if ($kind !== '' && (string) ($invoice['kind'] ?? 'monthly') !== $kind) continue;
    if (!$latest || (int) ($invoice['created_at'] ?? 0) > (int) ($latest['created_at'] ?? 0)) $latest = $invoice;
  }
  return $latest;
}

function mikhmonCustomerPortalActiveGateway($config = null) {
  $config = $config === null ? mikhmonPaymentGatewayReadConfig() : mikhmonPaymentGatewayNormalizeConfig($config);
  if (empty($config['enabled'])) return '';
  return !empty($config['midtrans']['enabled']) && $config['midtrans']['server_key'] !== '' ? 'midtrans' : '';
}

function mikhmonCustomerPortalSyncPayments($session, $customer, $api = null) {
  $config = mikhmonPaymentGatewayReadConfig();
  $result = array('checked' => 0, 'paid' => 0, 'activated' => 0, 'vouchers_created' => 0, 'voucher_notifications' => 0, 'errors' => array());
  // Voucher delivery is independent from the billing cron. Retrying when the
  // customer opens the portal covers a temporary Fonnte failure or webhook race.
  foreach (mikhmonGetInvoices($session) as $paidVoucher) {
    if ((string) ($paidVoucher['customer_id'] ?? '') !== (string) ($customer['id'] ?? '')) continue;
    if (($paidVoucher['kind'] ?? '') !== 'voucher' || ($paidVoucher['status'] ?? '') !== 'paid' || empty($paidVoucher['voucher_username'])) continue;
    if (!empty($paidVoucher['automation']['voucher_notification_sent_at'])) continue;
    $notification = mikhmonCustomerPortalSendVoucherPaymentNotification($session, $paidVoucher, $customer);
    if (!empty($notification['sent'])) $result['voucher_notifications']++;
    elseif (empty($notification['deferred']) && empty($notification['skipped'])) $result['errors'][] = $notification['message'] ?? 'Notifikasi voucher gagal dikirim.';
  }
  foreach (mikhmonGetInvoices($session) as $invoice) {
    if ((string) ($invoice['customer_id'] ?? '') !== (string) ($customer['id'] ?? '') || ($invoice['status'] ?? '') !== 'unpaid') continue;
    if (($invoice['payment_gateway'] ?? '') !== 'midtrans' || empty($invoice['payment_order_id'])) continue;
    if (!empty($invoice['payment_environment']) && $invoice['payment_environment'] !== $config['midtrans']['environment']) continue;
    $result['checked']++;
    $status = mikhmonPaymentGatewayGetMidtransStatus($invoice['payment_order_id'], $config);
    if (empty($status['success']) && !empty($invoice['payment_transaction_id'])) $status = mikhmonPaymentGatewayGetMidtransStatus($invoice['payment_transaction_id'], $config);
    if (empty($status['success']) && !empty($invoice['payment_reference'])) $status = mikhmonPaymentGatewayGetMidtransSnapStatus($invoice['payment_reference'], $config);
    if (empty($status['success'])) { $result['errors'][] = $status['message'] ?? 'Status Midtrans gagal diperiksa.'; continue; }
    if (($status['status'] ?? '') !== 'unknown') $invoice['gateway_status'] = $status['status'];
    if (!empty($status['reference'])) $invoice['payment_transaction_id'] = $status['reference'];
    if (empty($status['paid'])) { mikhmonSaveInvoice($session, $invoice); continue; }
    if ((int) round((float) ($status['amount'] ?? 0)) !== (int) round((float) ($invoice['amount'] ?? 0))) {
      $result['errors'][] = 'Nominal pembayaran ' . ($invoice['number'] ?? '') . ' tidak sesuai invoice.';
      continue;
    }
    $invoice['gateway_payment_received'] = true;
    $invoice['gateway_paid_at'] = !empty($status['paid_at']) ? (int) $status['paid_at'] : time();
    $invoice['gateway_updated_at'] = time();
    if (mikhmonSaveInvoice($session, $invoice) === false) { $result['errors'][] = 'Status invoice gagal disimpan.'; continue; }
    $result['paid']++;
    $activation = ($invoice['kind'] ?? 'monthly') === 'voucher'
      ? mikhmonCustomerPortalFulfillVoucher($session, $invoice['id'], $api)
      : mikhmonPaymentActivationProcess($session, $invoice['id'], $api, array('actor_name' => 'Otomatis MIDTRANS'));
    if (!empty($activation['success'])) {
      $result['activated']++;
      if (($invoice['kind'] ?? 'monthly') === 'voucher') {
        $result['vouchers_created']++;
        if (!empty($activation['notification_sent'])) $result['voucher_notifications']++;
        if (!empty($activation['notification_error'])) $result['errors'][] = $activation['notification_error'];
      }
    }
    else $result['errors'][] = $activation['message'] ?? 'Aktivasi pembayaran gagal.';
  }
  return $result;
}

function mikhmonCustomerPortalVoucherPaymentMessage($invoice, $customer) {
  $paidAt = (int) ($invoice['paid_at'] ?? $invoice['gateway_paid_at'] ?? time());
  $code = trim((string) ($invoice['voucher_username'] ?? ''));
  $name = trim((string) ($customer['name'] ?? $invoice['customer_name'] ?? 'Pelanggan')) ?: 'Pelanggan';
  $validity = mikhmonCustomerPortalVoucherValidityLabel($invoice['voucher_validity'] ?? '');
  $validityLine = $validity !== '' ? "\nMasa Berlaku: " . $validity : '';
  return "Pembayaran invoice " . ($invoice['number'] ?? $invoice['id'] ?? '') . " telah diterima.\n\n"
    . "Nama: " . $name . "\n"
    . "Total Dibayar: Rp " . number_format((float) ($invoice['amount'] ?? 0), 0, ',', '.') . "\n"
    . "Tanggal Bayar: " . date('Y-m-d H:i:s', $paidAt) . "\n\n"
    . "Kode Voucher: " . $code . $validityLine . "\n\n"
    . "Voucher Anda sudah aktif dan siap digunakan.";
}

function mikhmonCustomerPortalSendVoucherPaymentNotification($session, &$invoice, $customer) {
  if (!empty($invoice['automation']['voucher_notification_sent_at'])) return array('sent' => true, 'already_sent' => true);
  $now = time();
  $lastAttemptAt = (int) ($invoice['automation']['voucher_notification_last_attempt_at'] ?? 0);
  if ($lastAttemptAt > 0 && $lastAttemptAt + 60 > $now) return array('sent' => false, 'deferred' => true, 'message' => 'Menunggu percobaan notifikasi voucher berikutnya.');
  $config = mikhmonFonnteReadConfig();
  if (empty($config['enabled']) || empty($config['payment_enabled']) || trim((string) ($config['token'] ?? '')) === '') {
    return array('sent' => false, 'skipped' => true, 'message' => 'Notifikasi voucher dilewati karena Fonnte atau notifikasi pembayaran tidak aktif.');
  }
  $message = mikhmonCustomerPortalVoucherPaymentMessage($invoice, $customer);
  $invoice['automation']['voucher_notification_last_attempt_at'] = $now;
  $send = mikhmonFonnteSend($customer['phone'] ?? '', $message, $config);
  if (!empty($send['status'])) {
    $invoice['automation']['voucher_notification_sent_at'] = $now;
    unset($invoice['automation']['voucher_notification_last_error'], $invoice['automation']['voucher_notification_last_attempt_at']);
    if (mikhmonSaveInvoice($session, $invoice) === false) return array('sent' => false, 'message' => 'Voucher terkirim, tetapi status notifikasi gagal disimpan.');
    return array('sent' => true, 'detail' => $send['detail'] ?? 'Notifikasi voucher berhasil dikirim.');
  }
  $reason = (string) ($send['reason'] ?? 'Notifikasi voucher gagal dikirim melalui Fonnte.');
  $invoice['automation']['voucher_notification_last_error'] = substr($reason, 0, 500);
  mikhmonSaveInvoice($session, $invoice);
  return array('sent' => false, 'message' => $reason);
}

function mikhmonCustomerPortalReturnUrl() {
  $baseUrl = mikhmonPaymentGatewayBaseUrl();
  return $baseUrl !== '' ? $baseUrl . '/pelanggan.php?payment=return' : '';
}

function mikhmonCustomerPortalCreateVoucherInvoice($session, $customer, $profile) {
  $details = is_array($profile) ? mikhmonCustomerPortalProfileDetails($profile) : array();
  if (!$details || $details['expired_mode'] === 'none' || $details['selling_price'] < 1) return array('success' => false, 'message' => 'Profile voucher tidak valid atau Expired Mode = None.');
  foreach (mikhmonGetInvoices($session) as $existing) {
    if (($existing['kind'] ?? '') !== 'voucher' || ($existing['status'] ?? '') !== 'unpaid') continue;
    if ((string) ($existing['customer_id'] ?? '') !== (string) ($customer['id'] ?? '') || (string) ($existing['voucher_profile'] ?? '') !== $details['name']) continue;
    if (!empty($existing['payment_url'])) return array('success' => true, 'invoice' => $existing, 'payment_url' => $existing['payment_url']);
  }
  $suffix = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
  $number = 'VCR-' . date('YmdHis') . '-' . $suffix;
  $invoice = array(
    'id' => 'invoice-' . bin2hex(random_bytes(8)), 'number' => $number,
    'customer_id' => $customer['id'], 'customer_name' => $customer['name'] ?? 'Pelanggan',
    'kind' => 'voucher', 'voucher_profile' => $details['name'], 'voucher_validity' => $details['validity'], 'voucher_username' => '', 'voucher_password' => '',
    'services' => array(), 'service_count' => 0, 'amount' => $details['selling_price'],
    'due_date' => date('Y-m-d H:i:s', time() + 86400), 'status' => 'unpaid', 'created_at' => time(),
  );
  $orderId = $number . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
  $gatewayConfig = mikhmonPaymentGatewayReadConfig();
  if (mikhmonCustomerPortalActiveGateway($gatewayConfig) === '') return array('success' => false, 'message' => 'Midtrans belum aktif atau Server Key belum tersedia.');
  $returnUrl = mikhmonCustomerPortalReturnUrl();
  $payment = mikhmonPaymentGatewayCreatePayment('midtrans', array('order_id' => $orderId, 'amount' => $invoice['amount'], 'description' => 'Voucher ' . $details['name'], 'customer_name' => $customer['name'] ?? 'Pelanggan', 'phone' => $customer['phone'] ?? '', 'success_redirect_url' => $returnUrl, 'failure_redirect_url' => $returnUrl), $gatewayConfig);
  if (empty($payment['success'])) return $payment;
  $invoice['payment_gateway'] = $payment['provider']; $invoice['payment_order_id'] = $orderId; $invoice['payment_url'] = $payment['payment_url'];
  $invoice['payment_reference'] = $payment['reference'] ?? ''; $invoice['payment_environment'] = $payment['environment'] ?? ''; $invoice['payment_created_at'] = time();
  if (mikhmonSaveInvoice($session, $invoice) === false) return array('success' => false, 'message' => 'Invoice voucher gagal disimpan.');
  return array('success' => true, 'invoice' => $invoice, 'payment_url' => $payment['payment_url']);
}

function mikhmonCustomerPortalCreateMonthlyInvoice($session, $customer, $api) {
  // Billing admin and the customer portal share the same unpaid invoice.
  // Look it up before recalculating services so an existing admin invoice is
  // never duplicated or silently changed by the portal.
  $invoice = array();
  foreach (mikhmonGetInvoices($session) as $existing) {
    if (($existing['kind'] ?? 'monthly') !== 'monthly' || ($existing['status'] ?? '') !== 'unpaid' || (string) ($existing['customer_id'] ?? '') !== (string) $customer['id']) continue;
    if (!$invoice || (int) ($existing['created_at'] ?? 0) > (int) ($invoice['created_at'] ?? 0)) $invoice = $existing;
  }
  if ($invoice && !empty($invoice['payment_url'])) return array('success' => true, 'invoice' => $invoice, 'payment_url' => $invoice['payment_url']);
  $services = array();
  if (!$invoice) {
    $services = mikhmonCustomerPortalMonthlyServices($customer, $api);
    if (!$services) return array('success' => false, 'message' => 'Tidak ada layanan bulanan dengan Expired Mode = None.');
  }
  $amount = $invoice ? (float) ($invoice['amount'] ?? 0) : 0;
  if (!$invoice) foreach ($services as $service) $amount += (float) $service['amount'];
  if ($amount < 1) return array('success' => false, 'message' => 'Nominal invoice langganan tidak valid.');
  if (!$invoice) {
    $number = 'INV-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    $invoice = array('id' => 'invoice-' . bin2hex(random_bytes(8)), 'number' => $number, 'customer_id' => $customer['id'], 'customer_name' => $customer['name'] ?? '', 'kind' => 'monthly', 'services' => $services, 'service_count' => count($services), 'amount' => $amount, 'due_date' => date('Y-m-d H:i:s'), 'status' => 'unpaid', 'created_at' => time());
  }
  $number = (string) ($invoice['number'] ?? $invoice['id']);
  $orderId = $number . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
  $gatewayConfig = mikhmonPaymentGatewayReadConfig();
  if (mikhmonCustomerPortalActiveGateway($gatewayConfig) === '') return array('success' => false, 'message' => 'Midtrans belum aktif atau Server Key belum tersedia.');
  $returnUrl = mikhmonCustomerPortalReturnUrl();
  $payment = mikhmonPaymentGatewayCreatePayment('midtrans', array('order_id' => $orderId, 'amount' => $amount, 'description' => 'Langganan bulanan ' . $number, 'customer_name' => $customer['name'] ?? 'Pelanggan', 'phone' => $customer['phone'] ?? '', 'success_redirect_url' => $returnUrl, 'failure_redirect_url' => $returnUrl), $gatewayConfig);
  if (empty($payment['success'])) return $payment;
  $invoice['payment_gateway'] = $payment['provider']; $invoice['payment_order_id'] = $orderId; $invoice['payment_url'] = $payment['payment_url']; $invoice['payment_reference'] = $payment['reference'] ?? ''; $invoice['payment_environment'] = $payment['environment'] ?? ''; $invoice['payment_created_at'] = time();
  if (mikhmonSaveInvoice($session, $invoice) === false) return array('success' => false, 'message' => 'Invoice langganan gagal disimpan.');
  return array('success' => true, 'invoice' => $invoice, 'payment_url' => $payment['payment_url']);
}

function mikhmonCustomerPortalFulfillVoucher($session, $invoiceId, $api = null) {
  $invoice = array(); foreach (mikhmonGetInvoices($session) as $row) if ((string) ($row['id'] ?? '') === (string) $invoiceId) { $invoice = $row; break; }
  if (!$invoice || ($invoice['kind'] ?? '') !== 'voucher') return array('success' => false, 'message' => 'Invoice voucher tidak ditemukan.');
  if (($invoice['status'] ?? '') === 'paid' && !empty($invoice['voucher_username'])) {
    $customer = mikhmonFindCustomer($session, $invoice['customer_id'] ?? '');
    if (!$customer) return array('success' => false, 'message' => 'Pelanggan tidak ditemukan.');
    $notification = mikhmonCustomerPortalSendVoucherPaymentNotification($session, $invoice, $customer);
    return array('success' => true, 'already_paid' => true, 'invoice' => $invoice, 'notification_sent' => !empty($notification['sent']), 'notification_error' => $notification['message'] ?? '');
  }
  $customer = mikhmonFindCustomer($session, $invoice['customer_id'] ?? '');
  if (!$customer) return array('success' => false, 'message' => 'Pelanggan tidak ditemukan.');
  if (!is_object($api) || !method_exists($api, 'comm')) { $connection = mikhmonPaymentActivationConnect($session); if (!$connection['api']) return array('success' => false, 'message' => $connection['error']); $api = $connection['api']; }
  $profileRows = $api->comm('/ip/hotspot/user/profile/print', array('?name' => (string) ($invoice['voucher_profile'] ?? '')));
  $profile = $profileRows[0] ?? array();
  if (!$profile || mikhmonBillingProfileExpiredMode('hotspot', $profile) === 'none') return array('success' => false, 'message' => 'Profile voucher tidak tersedia.');
  if (empty($invoice['voucher_validity'])) $invoice['voucher_validity'] = mikhmonCustomerPortalProfileDetails($profile)['validity'] ?? '';
  $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
  $response = $api->comm('/ip/hotspot/user/add', array('server' => 'all', 'name' => $code, 'password' => $code, 'profile' => $invoice['voucher_profile'], 'comment' => 'vc-portal-' . ($customer['name'] ?? 'Pelanggan')));
  if (mikhmonPaymentActivationApiError($response) !== '') return array('success' => false, 'message' => 'Voucher gagal dibuat di router.');
  $invoice['status'] = 'paid'; $invoice['paid_at'] = (int) ($invoice['gateway_paid_at'] ?? time()); $invoice['voucher_username'] = $code; $invoice['voucher_password'] = $code; $invoice['activation_status'] = 'success'; $invoice['gateway_payment_received'] = true;
  if (mikhmonSaveInvoice($session, $invoice) === false) return array('success' => false, 'message' => 'Voucher dibuat, tetapi invoice gagal disimpan.');
  $notification = mikhmonCustomerPortalSendVoucherPaymentNotification($session, $invoice, $customer);
  return array('success' => true, 'invoice' => $invoice, 'voucher_username' => $code, 'voucher_password' => $code, 'notification_sent' => !empty($notification['sent']), 'notification_error' => $notification['message'] ?? '');
}

function mikhmonCustomerPortalChangeHotspotPassword($session, $customer, $username, $newPassword, $api = null) {
  $newPassword = (string) $newPassword;
  $username = trim((string) $username);
  if (strlen($newPassword) < 6) return array('success' => false, 'message' => 'Password minimal 6 karakter.');
  if ($username === '') return array('success' => false, 'message' => 'Username hotspot wajib dipilih.');
  if (!is_object($api) || !method_exists($api, 'comm')) { $connection = mikhmonPaymentActivationConnect($session); if (!$connection['api']) return array('success' => false, 'message' => $connection['error']); $api = $connection['api']; }
  $changed = 0;
  foreach (mikhmonCustomerServices($customer) as $service) {
    if (($service['service'] ?? '') !== 'hotspot') continue;
    if ((string) ($service['username'] ?? '') !== $username) continue;
    $rows = $api->comm('/ip/hotspot/user/print', array('?name' => $service['username']));
    if (empty($rows[0]['.id'])) continue;
    $response = $api->comm('/ip/hotspot/user/set', array('.id' => $rows[0]['.id'], 'password' => $newPassword));
    if (mikhmonPaymentActivationApiError($response) !== '') return array('success' => false, 'message' => 'Password gagal diubah.');
    $changed++;
  }
  return $changed > 0 ? array('success' => true, 'message' => 'Password hotspot untuk username ' . $username . ' berhasil diubah.') : array('success' => false, 'message' => 'Username hotspot tidak ditemukan pada layanan pelanggan Anda.');
}
