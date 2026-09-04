<?php
error_reporting(0);
date_default_timezone_set(getenv('MIKHMON_TIMEZONE') ?: 'Asia/Jakarta');
// Keep the customer portal session across browser restarts for one day.
if (session_status() === PHP_SESSION_NONE) {
  session_set_cookie_params(array('lifetime' => 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax'));
  ini_set('session.use_strict_mode', '1');
}
session_start();
require_once __DIR__ . '/include/database.php';
require_once __DIR__ . '/include/access.php';
require_once __DIR__ . '/lib/customer_portal.php';
require_once __DIR__ . '/include/brand.php';

if (isset($_GET['logout'])) { unset($_SESSION['mikhmon'], $_SESSION['mikhmon_role'], $_SESSION['mikhmon_customer_id'], $_SESSION['mikhmon_customer_session'], $_SESSION['mikhmon_name'], $_SESSION['mikhmon_customer_expires_at'], $_SESSION['customer_otp_phone']); header('Location: pelanggan.php'); exit; }
$message = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string) ($_POST['portal_action'] ?? '');
  if (!in_array($action, array('request_otp', 'verify_otp'), true) && !mikhmonValidCsrf($_POST['_csrf'] ?? '')) {
    http_response_code(403);
    exit('Invalid request.');
  }
  if ($action === 'request_otp') {
    $phone = (string) ($_POST['phone'] ?? '');
    $result = mikhmonCustomerPortalOtp($phone);
    if (!empty($result['success'])) { $_SESSION['customer_otp_phone'] = mikhmonCustomerPhone($phone); $message = $result['message']; } else $error = $result['message'];
  } elseif ($action === 'verify_otp') {
    $phone = (string) ($_SESSION['customer_otp_phone'] ?? $_POST['phone'] ?? '');
    if (mikhmonVerifyCustomerOtp($phone, (string) ($_POST['otp'] ?? ''))) {
      session_regenerate_id(true);
      $customer = mikhmonFindCustomerByPhone($phone); mikhmonSetCustomerSession($customer, $customer['_session'] ?? ''); unset($_SESSION['customer_otp_phone']); header('Location: pelanggan.php'); exit;
    } else $error = 'OTP tidak valid atau sudah kedaluwarsa.';
  }
}

$loggedIn = mikhmonIsCustomer() && mikhmonRefreshCustomerSession();
$customer = $loggedIn ? mikhmonFindCustomer($_SESSION['mikhmon_customer_session'], $_SESSION['mikhmon_customer_id']) : array();
$hasHotspotService = false;
$hotspotUsernames = array();
foreach (mikhmonCustomerServices($customer) as $customerService) {
  if (($customerService['service'] ?? '') === 'hotspot' && trim((string) ($customerService['username'] ?? '')) !== '') {
    $hasHotspotService = true;
    $hotspotUsernames[] = (string) $customerService['username'];
  }
}
$portalSession = $loggedIn ? (string) $_SESSION['mikhmon_customer_session'] : '';
$api = null; $profiles = array();
if ($loggedIn) {
  $connection = mikhmonPaymentActivationConnect($portalSession); $api = $connection['api'];
  if ($api) $profiles = mikhmonCustomerPortalVoucherProfiles($api);
  $paymentSync = mikhmonCustomerPortalSyncPayments($portalSession, $customer, $api);
  if (!empty($paymentSync['vouchers_created'])) $message = !empty($paymentSync['voucher_notifications']) ? 'Pembayaran voucher berhasil dikonfirmasi. Kode voucher sudah dikirim ke WhatsApp.' : 'Pembayaran voucher berhasil dikonfirmasi. Username dan password voucher sudah dibuat.';
  elseif (!empty($paymentSync['activated'])) $message = 'Pembayaran berhasil dikonfirmasi dan layanan sudah diperbarui.';
  elseif (isset($_GET['payment']) && $_GET['payment'] === 'return' && !empty($paymentSync['errors'])) $error = (string) $paymentSync['errors'][0];
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['portal_action'] ?? '');
    if ($action === 'change_password') {
      $result = mikhmonCustomerPortalChangeHotspotPassword($portalSession, $customer, $_POST['hotspot_username'] ?? '', $_POST['new_password'] ?? '', $api);
      if (!empty($result['success'])) $message = $result['message']; else $error = $result['message'];
    } elseif ($action === 'voucher_invoice') {
      $selected = array(); foreach ($profiles as $profile) if ((string) $profile['name'] === (string) ($_POST['profile'] ?? '')) { $selected = $profile['row']; break; }
      $result = mikhmonCustomerPortalCreateVoucherInvoice($portalSession, $customer, $selected);
      if (!empty($result['success'])) { header('Location: ' . $result['payment_url']); exit; } else $error = $result['message'];
    } elseif ($action === 'monthly_invoice') {
      $result = mikhmonCustomerPortalCreateMonthlyInvoice($portalSession, $customer, $api);
      if (!empty($result['success']) && !empty($result['payment_url'])) { header('Location: ' . $result['payment_url']); exit; } else $error = $result['message'] ?? 'Invoice gagal dibuat.';
    }
  }
}
$invoices = array();
if ($loggedIn) {
  foreach (mikhmonGetInvoices($portalSession) as $invoice) {
    if ((string) ($invoice['customer_id'] ?? '') !== (string) ($customer['id'] ?? '')) continue;
    $invoices[] = $invoice;
  }
}
$paymentHistory = array();
foreach ($invoices as $invoice) {
  if (($invoice['status'] ?? '') !== 'paid') continue;
  $paymentHistory[] = $invoice;
}
usort($paymentHistory, function ($left, $right) {
  return (int) ($right['paid_at'] ?? $right['gateway_paid_at'] ?? $right['created_at'] ?? 0) <=> (int) ($left['paid_at'] ?? $left['gateway_paid_at'] ?? $left['created_at'] ?? 0);
});
$voucherInvoices = array();
if ($loggedIn) {
  foreach ($invoices as $invoice) {
    if (($invoice['kind'] ?? '') !== 'voucher' || ($invoice['status'] ?? '') !== 'paid') continue;
    if (trim((string) ($invoice['voucher_username'] ?? '')) === '' || trim((string) ($invoice['voucher_password'] ?? '')) === '') continue;
    if (!mikhmonCustomerPortalVoucherAvailable($api, $invoice['voucher_username'])) continue;
    $voucherInvoices[] = $invoice;
  }
  usort($voucherInvoices, function ($left, $right) {
    return (int) ($right['paid_at'] ?? $right['gateway_paid_at'] ?? $right['created_at'] ?? 0) <=> (int) ($left['paid_at'] ?? $left['gateway_paid_at'] ?? $left['created_at'] ?? 0);
  });
}
$gateway = mikhmonPaymentGatewayReadConfig();
$activeGateway = mikhmonCustomerPortalActiveGateway($gateway);
$monthlyInvoice = array();
$monthlyPaidThisMonth = false;
foreach ($invoices as $candidateInvoice) {
  if (($candidateInvoice['kind'] ?? 'monthly') !== 'monthly' || (string) ($candidateInvoice['customer_id'] ?? '') !== (string) ($customer['id'] ?? '')) continue;
  if (!$monthlyInvoice || (int) ($candidateInvoice['created_at'] ?? 0) > (int) ($monthlyInvoice['created_at'] ?? 0)) $monthlyInvoice = $candidateInvoice;
  if (($candidateInvoice['status'] ?? '') !== 'paid') continue;
  $paidAt = (int) ($candidateInvoice['paid_at'] ?? $candidateInvoice['activated_at'] ?? 0);
  $nextDueAt = !empty($candidateInvoice['next_due_date']) ? strtotime((string) $candidateInvoice['next_due_date']) : 0;
  if (($paidAt > 0 && date('Ym', $paidAt) === date('Ym')) || ($nextDueAt > time())) {
    if (!$monthlyPaidThisMonth || $paidAt > (int) ($monthlyPaidThisMonth['paid_at'] ?? 0)) $monthlyPaidThisMonth = $candidateInvoice;
  }
}
$monthlyIsPaid = is_array($monthlyPaidThisMonth);
if ($monthlyIsPaid) $monthlyInvoice = $monthlyPaidThisMonth;
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($brandname, ENT_QUOTES); ?> - Pelanggan</title><link rel="stylesheet" href="css/mikhmon-ui.dark.min.css"><link rel="stylesheet" href="css/font-awesome/css/font-awesome.min.css"><style>body{background:#101419;color:#e8edf2;font-family:Arial,sans-serif}.portal{max-width:1100px;margin:30px auto;padding:0 16px}.card{background:#1a2129;border:1px solid #303b46;border-radius:8px;padding:20px;margin-bottom:18px;box-sizing:border-box;max-width:100%;overflow:hidden}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}.form-control{width:100%;box-sizing:border-box;margin:6px 0 12px;padding:10px}.btn{display:inline-block;padding:10px 14px;border:0;border-radius:4px;color:#fff;text-decoration:none;cursor:pointer}.primary{background:#1677d2}.success{background:#218838}.warning{background:#b07a00}.danger{background:#c53030}.portal-disabled{opacity:.5;cursor:not-allowed;pointer-events:none}.login-card{width:100%;max-width:440px;margin:8vh auto;padding:32px;border-top:3px solid #1677d2;box-shadow:0 22px 60px rgba(0,0,0,.38)}.login-brand{text-align:center;margin-bottom:26px}.login-icon{display:inline-flex;width:64px;height:64px;align-items:center;justify-content:center;border-radius:50%;background:linear-gradient(145deg,#1677d2,#0d4f89);box-shadow:0 10px 28px rgba(22,119,210,.3);font-size:28px}.login-brand h2{margin:16px 0 6px}.login-brand p{margin:0}.login-form label{display:block;margin-bottom:6px;font-weight:600}.login-input{position:relative}.login-input>i{position:absolute;left:14px;top:18px;color:#7f96aa;z-index:1}.login-input .form-control{padding:12px 12px 12px 42px;background:#10161d;border:1px solid #374653;border-radius:6px;color:#fff;font-size:15px}.login-input .form-control:focus{border-color:#1677d2;outline:0;box-shadow:0 0 0 3px rgba(22,119,210,.16)}.login-submit{display:block;width:100%;padding:12px;font-size:15px;font-weight:700}.login-note{text-align:center;margin:18px 0 0}.login-note a{color:#62b5ff}.voucher-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}.voucher-item{background:#111820;border:1px solid #2f8f5b;border-radius:6px;padding:15px}.voucher-item h4{margin:0 0 8px;color:#7ee2a8}.voucher-credential{display:block;background:#0b1015;border-radius:4px;padding:10px;margin-top:8px;font-size:16px;letter-spacing:.5px;word-break:break-all}.table-scroll{width:100%;max-height:420px;overflow:auto;-webkit-overflow-scrolling:touch}.table-scroll table{min-width:680px}.table-scroll thead th{position:sticky;top:0;z-index:1;background:#1a2129}.muted{color:#aab4bf;font-size:13px}table{width:100%;border-collapse:collapse}td,th{padding:9px;border-bottom:1px solid #35414d;text-align:left;white-space:nowrap}@media(max-width:600px){.portal{margin:12px auto}.card{padding:14px}.login-card{margin:4vh auto;padding:24px 18px}.table-scroll{max-height:360px}}</style></head><body><div class="portal">
<div class="card<?= !$loggedIn ? ' login-card' : ''; ?>"><?php if (!$loggedIn): ?><div class="login-brand"><span class="login-icon"><i class="fa fa-wifi"></i></span><h2>Portal Pelanggan</h2><p class="muted"><?= htmlspecialchars($brandname, ENT_QUOTES); ?></p></div><?php else: ?><h2><i class="fa fa-wifi"></i> <?= htmlspecialchars($brandname, ENT_QUOTES); ?> - Portal Pelanggan</h2><?php endif; ?><?php if ($message !== ''): ?><p class="success" style="padding:10px"><?= htmlspecialchars($message, ENT_QUOTES); ?></p><?php endif; ?><?php if ($error !== ''): ?><p class="danger" style="padding:10px"><?= htmlspecialchars($error, ENT_QUOTES); ?></p><?php endif; ?>
<?php if (!$loggedIn): ?><?php if (!empty($_SESSION['customer_otp_phone'])): ?><form class="login-form" method="post"><input type="hidden" name="portal_action" value="verify_otp"><label for="customer-otp">Kode OTP</label><div class="login-input"><i class="fa fa-key"></i><input id="customer-otp" class="form-control" name="otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="Masukkan 6 digit OTP" autofocus required></div><button class="btn primary login-submit" type="submit"><i class="fa fa-sign-in"></i> Verifikasi dan Masuk</button></form><p class="muted login-note">OTP dikirim ke <?= htmlspecialchars($_SESSION['customer_otp_phone'], ENT_QUOTES); ?><br><a href="pelanggan.php"><i class="fa fa-refresh"></i> Gunakan nomor lain</a></p><?php else: ?><form class="login-form" method="post"><input type="hidden" name="portal_action" value="request_otp"><label for="customer-phone">Nomor Handphone</label><div class="login-input"><i class="fa fa-phone"></i><input id="customer-phone" class="form-control" name="phone" inputmode="tel" autocomplete="tel" placeholder="Contoh: 0812xxxxxxx" autofocus required></div><button class="btn primary login-submit" type="submit"><i class="fa fa-paper-plane"></i> Kirim Kode OTP</button></form><p class="muted login-note"><i class="fa fa-whatsapp"></i> OTP dikirim melalui WhatsApp Fonnte ke nomor yang terdaftar.</p><?php endif; ?></div><?php else: ?><p>Halo, <strong><?= htmlspecialchars($customer['name'] ?? 'Pelanggan', ENT_QUOTES); ?></strong> <a class="btn danger" style="float:right" href="pelanggan.php?logout=1">Keluar</a></p></div>
<div class="grid"><?php if ($hasHotspotService): ?><div class="card"><h3>Ganti Password Hotspot</h3><p class="muted">Pilih username hotspot yang ingin diubah:</p><form method="post"><?= mikhmonCsrfField(); ?><input type="hidden" name="portal_action" value="change_password"><select class="form-control" name="hotspot_username" required><?php foreach ($hotspotUsernames as $hotspotUsername): ?><option value="<?= htmlspecialchars($hotspotUsername, ENT_QUOTES); ?>"><?= htmlspecialchars($hotspotUsername, ENT_QUOTES); ?></option><?php endforeach; ?></select><label>Password baru</label><input class="form-control" type="password" name="new_password" minlength="6" placeholder="Minimal 6 karakter" required><button class="btn warning" type="submit">Simpan Password</button></form></div><?php endif; ?><div class="card"><h3>Langganan Bulanan</h3><?php if ($monthlyInvoice): ?><p class="muted">Invoice: <strong><?= htmlspecialchars($monthlyInvoice['number'] ?? $monthlyInvoice['id'], ENT_QUOTES); ?></strong><br>Nominal: <strong><?= number_format((float) ($monthlyInvoice['amount'] ?? 0), 0, ',', '.'); ?></strong><br>Status: <strong class="<?= $monthlyIsPaid ? 'text-success' : 'text-warning'; ?>"><?= $monthlyIsPaid ? 'Sudah Dibayar' : 'Belum Dibayar'; ?></strong><?php if ($monthlyIsPaid && !empty($monthlyInvoice['paid_at'])): ?><br>Tanggal bayar: <?= htmlspecialchars(date('Y-m-d H:i', (int) $monthlyInvoice['paid_at']), ENT_QUOTES); ?><?php endif; ?></p><?php else: ?><p class="muted">Belum ada invoice langganan bulan ini.</p><?php endif; ?><?php if ($monthlyIsPaid): ?><span class="btn success portal-disabled">Sudah Dibayar Bulan Ini</span><?php elseif ($activeGateway === ''): ?><span class="btn danger portal-disabled">Payment Gateway Belum Aktif</span><?php else: ?><form method="post"><?= mikhmonCsrfField(); ?><input type="hidden" name="portal_action" value="monthly_invoice"><button class="btn success" type="submit">Bayar</button></form><?php endif; ?></div></div>
<div class="card"><h3>Pembelian Voucher Baru</h3><p class="muted">Hanya profile hotspot dengan Expired Mode selain None yang ditampilkan. Pembayaran diverifikasi langsung ke Midtrans; setelah lunas kode voucher dibuat otomatis.</p><form method="post"><?= mikhmonCsrfField(); ?><input type="hidden" name="portal_action" value="voucher_invoice"><select class="form-control" name="profile" required><option value="">Pilih profile voucher</option><?php foreach ($profiles as $profile): ?><option value="<?= htmlspecialchars($profile['name'], ENT_QUOTES); ?>"><?= htmlspecialchars($profile['name'], ENT_QUOTES); ?> - <?= number_format($profile['selling_price'], 0, ',', '.'); ?> (<?= htmlspecialchars($profile['validity'], ENT_QUOTES); ?>)</option><?php endforeach; ?></select><?php if ($activeGateway === ''): ?><span class="btn danger portal-disabled">Payment Gateway Belum Aktif</span><?php elseif (!$profiles): ?><span class="btn danger portal-disabled">Profile Voucher Tidak Tersedia</span><?php else: ?><button class="btn primary" type="submit">Bayar</button><?php endif; ?></form></div>
<?php if ($voucherInvoices): ?><div class="card"><h3><i class="fa fa-ticket"></i> Voucher Berhasil Dibuat</h3><p class="muted">Gunakan kode voucher berikut untuk login hotspot.</p><div class="voucher-list"><?php foreach ($voucherInvoices as $voucherInvoice): ?><div class="voucher-item"><h4><?= htmlspecialchars($voucherInvoice['voucher_profile'] ?? 'Voucher', ENT_QUOTES); ?></h4><p class="muted">Dibuat: <?= htmlspecialchars(date('Y-m-d H:i', (int) ($voucherInvoice['paid_at'] ?? $voucherInvoice['gateway_paid_at'] ?? $voucherInvoice['created_at'] ?? time())), ENT_QUOTES); ?></p><div>Kode Voucher</div><code class="voucher-credential"><?= htmlspecialchars($voucherInvoice['voucher_username'], ENT_QUOTES); ?></code><?php $voucherValidityLabel = mikhmonCustomerPortalVoucherValidityLabel($voucherInvoice['voucher_validity'] ?? ''); if ($voucherValidityLabel !== ''): ?><div style="margin-top:10px">Masa Berlaku</div><div><?= htmlspecialchars($voucherValidityLabel, ENT_QUOTES); ?></div><?php endif; ?></div><?php endforeach; ?></div></div><?php endif; ?><div class="card"><h3>Riwayat Pembayaran</h3><p class="muted">Hanya transaksi lunas milik akun pelanggan ini yang ditampilkan.</p><div class="table-scroll"><table><thead><tr><th>Tanggal Pembayaran</th><th>Jenis</th><th>Invoice</th><th>Nominal</th><th>Status</th></tr></thead><tbody><?php foreach ($paymentHistory as $invoice): ?><tr><td><?= htmlspecialchars(date('Y-m-d H:i', (int) ($invoice['paid_at'] ?? $invoice['gateway_paid_at'] ?? $invoice['created_at'] ?? time())), ENT_QUOTES); ?></td><td><?= ($invoice['kind'] ?? 'monthly') === 'voucher' ? 'Voucher ' . htmlspecialchars($invoice['voucher_profile'] ?? '', ENT_QUOTES) : 'Langganan bulanan'; ?></td><td><?= htmlspecialchars($invoice['number'] ?? $invoice['id'] ?? '-', ENT_QUOTES); ?></td><td>Rp<?= number_format((float) ($invoice['amount'] ?? 0), 0, ',', '.'); ?></td><td><strong class="text-success">Lunas</strong></td></tr><?php endforeach; ?><?php if (!$paymentHistory): ?><tr><td colspan="5" class="muted">Belum ada riwayat pembayaran yang lunas.</td></tr><?php endif; ?></tbody></table></div></div><?php endif; ?></div><script src="js/table-sort.js"></script></body></html>
