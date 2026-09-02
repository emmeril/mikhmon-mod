<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon']) || !mikhmonIsAdmin()) {
  header('Location:./admin.php?id=login');
  exit;
}

include_once('./lib/payment_gateway.php');
$storedPaymentConfig = mikhmonPaymentGatewayReadStoredConfig();
$paymentConfig = mikhmonPaymentGatewayReadConfig();
$paymentMessage = '';
$paymentError = '';

$paymentEnvironmentSecrets = array(
  'Midtrans Merchant ID' => 'MIKHMON_MIDTRANS_MERCHANT_ID',
  'Midtrans Server Key' => 'MIKHMON_MIDTRANS_SERVER_KEY',
  'Midtrans Client Key' => 'MIKHMON_MIDTRANS_CLIENT_KEY',
);
$activePaymentEnvironmentSecrets = array();
foreach ($paymentEnvironmentSecrets as $label => $environmentName) {
  $value = getenv($environmentName);
  if ($value !== false && trim($value) !== '') $activePaymentEnvironmentSecrets[] = $label;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = isset($_POST['payment_gateway_action']) ? (string) $_POST['payment_gateway_action'] : '';
  if (!mikhmonPaymentGatewayValidCsrf($_POST['payment_gateway_csrf'] ?? '')) {
    $paymentError = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
  } elseif ($action === 'save') {
    $midtransServerKey = mikhmonPaymentGatewayCleanSecret($_POST['midtrans_server_key'] ?? '');
    $midtransClientKey = mikhmonPaymentGatewayCleanSecret($_POST['midtrans_client_key'] ?? '');
    if ($midtransServerKey === '') $midtransServerKey = $storedPaymentConfig['midtrans']['server_key'];
    if ($midtransClientKey === '') $midtransClientKey = $storedPaymentConfig['midtrans']['client_key'];
    $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';

    $newPaymentConfig = array(
      'enabled' => $enabled,
      'invoice_duration' => (int) ($_POST['invoice_duration'] ?? 86400),
      'midtrans' => array(
        'enabled' => $enabled,
        'environment' => isset($_POST['midtrans_environment']) && $_POST['midtrans_environment'] === 'production' ? 'production' : 'sandbox',
        'merchant_id' => trim((string) ($_POST['midtrans_merchant_id'] ?? '')),
        'server_key' => $midtransServerKey,
        'client_key' => $midtransClientKey,
      ),
    );

    $normalizedPaymentConfig = mikhmonPaymentGatewayNormalizeConfig($newPaymentConfig);
    if ($normalizedPaymentConfig['enabled'] && $normalizedPaymentConfig['midtrans']['server_key'] === '') {
      $paymentError = 'Server Key Midtrans wajib diisi ketika Midtrans diaktifkan.';
    } elseif (mikhmonPaymentGatewayWriteConfig($normalizedPaymentConfig)) {
      $storedPaymentConfig = mikhmonPaymentGatewayReadStoredConfig();
      $paymentConfig = mikhmonPaymentGatewayReadConfig();
      $paymentMessage = 'Pengaturan Payment Gateway berhasil disimpan.';
    } else {
      $paymentError = 'Pengaturan Payment Gateway gagal disimpan. Periksa izin folder data/.';
    }
  } elseif ($action === 'test_midtrans') {
    $testResult = mikhmonPaymentGatewayTestConnection('midtrans', $paymentConfig);
    if (!empty($testResult['success'])) $paymentMessage = $testResult['message'];
    else $paymentError = $testResult['message'];
  }
}

$paymentBaseUrl = mikhmonPaymentGatewayBaseUrl();
$midtransWebhookUrl = $paymentBaseUrl !== '' ? $paymentBaseUrl . '/payment-notification.php?provider=midtrans' : 'https://domain-anda/payment-notification.php?provider=midtrans';
?>
<style>
.payment-layout { display: flex; flex-wrap: wrap; align-items: flex-start; margin: 0 -7px; }
.payment-layout-main, .payment-layout-test { width: 50%; flex: 0 0 50%; padding: 0 7px; box-sizing: border-box; }
.payment-layout form { margin: 0; }
.payment-layout .card { width: calc(100% - 10px); box-sizing: border-box; }
.payment-provider-card { border-top: 4px solid #0f766e; }
.payment-provider-heading { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.payment-provider-heading h3 { margin: 0; }
.payment-provider-badge { border-radius: 20px; padding: 3px 9px; font-size: 11px; font-weight: bold; letter-spacing: .4px; }
.payment-provider-badge.sandbox { background: #fff3cd; color: #856404; }
.payment-provider-badge.production { background: #d4edda; color: #155724; }
.payment-settings-table td { text-align: left; }
.payment-settings-table td:first-child { width: 155px; font-weight: 600; }
.payment-settings-table .form-control { width: 100%; max-width: 420px; margin-left: 0; margin-right: 0; box-sizing: border-box; }
.payment-settings-table select[name="invoice_duration"], .payment-settings-table select[name="midtrans_environment"] { max-width: 180px; }
.payment-settings-table input[name="midtrans_merchant_id"] { max-width: 220px; }
.payment-settings-table input[name="midtrans_server_key"], .payment-settings-table input[name="midtrans_client_key"] { max-width: 360px; }
.payment-secret-note { color: #777; display: block; font-size: 11px; margin-top: 4px; }
.payment-webhook-box { background: rgba(0,0,0,.035); border-left: 3px solid #0f766e; padding: 10px; word-break: break-all; }
.payment-webhook-box code { white-space: normal; }
.payment-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: flex-start; }
@media screen and (max-width: 750px) {
  .payment-layout-main, .payment-layout-test { width: 100%; flex-basis: 100%; }
  .payment-layout .card { width: calc(100% - 10px); }
  .payment-settings-table, .payment-settings-table tbody, .payment-settings-table tr, .payment-settings-table td { display: block; width: 100%; box-sizing: border-box; }
  .payment-settings-table td:first-child { width: 100%; padding-bottom: 3px; }
  .payment-settings-table td:last-child { padding-top: 3px; }
  .payment-settings-table .form-control { max-width: 100%; }
}
</style>
<div class="row">
  <div class="col-12">
    <?php if ($paymentMessage !== ''): ?><div class="bg-success pd-10 radius-3 mr-b-10"><i class="fa fa-check"></i> <?= htmlspecialchars($paymentMessage, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($paymentError !== ''): ?><div class="bg-danger pd-10 radius-3 mr-b-10"><i class="fa fa-ban"></i> <?= htmlspecialchars($paymentError, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($activePaymentEnvironmentSecrets): ?><div class="bg-info pd-10 radius-3 mr-b-10"><i class="fa fa-shield"></i> Kredensial aktif dari environment: <?= htmlspecialchars(implode(', ', $activePaymentEnvironmentSecrets), ENT_QUOTES); ?>. Nilai environment tidak disalin ke file konfigurasi.</div><?php endif; ?>

    <div class="payment-layout">
      <div class="payment-layout-main">
        <form autocomplete="off" method="post" action="">
          <input type="hidden" name="payment_gateway_action" value="save">
          <input type="hidden" name="payment_gateway_csrf" value="<?= htmlspecialchars(mikhmonPaymentGatewayCsrfToken(), ENT_QUOTES); ?>">

          <div class="card payment-provider-card">
            <div class="card-header payment-provider-heading">
              <h3 class="card-title"><i class="fa fa-credit-card-alt"></i> Pembayaran Midtrans</h3>
              <span class="payment-provider-badge <?= $paymentConfig['midtrans']['environment']; ?>"><?= strtoupper($paymentConfig['midtrans']['environment']); ?></span>
            </div>
            <div class="card-body">
              <table class="table table-sm payment-settings-table">
                <tr><td class="align-middle">Status</td><td><label><input type="checkbox" name="enabled" value="1" <?= !empty($paymentConfig['enabled']) ? 'checked' : ''; ?>> Aktifkan pembayaran online melalui Midtrans</label></td></tr>
                <tr><td class="align-middle">Provider</td><td><strong>Midtrans</strong></td></tr>
                <tr><td class="align-middle">Masa Berlaku</td><td><select class="form-control" name="invoice_duration"><?php foreach (array(3600=>'1 jam',21600=>'6 jam',43200=>'12 jam',86400=>'24 jam') as $seconds => $label): ?><option value="<?= $seconds; ?>" <?= (int) $paymentConfig['invoice_duration'] === $seconds ? 'selected' : ''; ?>><?= $label; ?></option><?php endforeach; ?></select></td></tr>
                <tr><td class="align-middle">Mode</td><td><select class="form-control" name="midtrans_environment"><option value="sandbox" <?= $paymentConfig['midtrans']['environment'] === 'sandbox' ? 'selected' : ''; ?>>Sandbox</option><option value="production" <?= $paymentConfig['midtrans']['environment'] === 'production' ? 'selected' : ''; ?>>Production</option></select></td></tr>
                <tr><td class="align-middle">Merchant ID</td><td><input class="form-control" type="text" name="midtrans_merchant_id" value="<?= htmlspecialchars($storedPaymentConfig['midtrans']['merchant_id'], ENT_QUOTES); ?>" placeholder="G123456789"></td></tr>
                <tr><td class="align-middle">Server Key</td><td><input class="form-control" type="password" name="midtrans_server_key" placeholder="<?= $paymentConfig['midtrans']['server_key'] !== '' ? htmlspecialchars('Tersimpan: ' . mikhmonPaymentGatewayMask($paymentConfig['midtrans']['server_key']), ENT_QUOTES) : 'SB-Mid-server-...'; ?>"><small class="payment-secret-note">Kosongkan untuk mempertahankan key yang tersimpan.</small></td></tr>
                <tr><td class="align-middle">Client Key</td><td><input class="form-control" type="password" name="midtrans_client_key" placeholder="<?= $paymentConfig['midtrans']['client_key'] !== '' ? htmlspecialchars('Tersimpan: ' . mikhmonPaymentGatewayMask($paymentConfig['midtrans']['client_key']), ENT_QUOTES) : 'SB-Mid-client-...'; ?>"><small class="payment-secret-note">Digunakan saat Snap ditampilkan di browser.</small></td></tr>
              </table>
              <div class="payment-webhook-box"><b>Payment Notification URL (opsional)</b><br><code><?= htmlspecialchars($midtransWebhookUrl, ENT_QUOTES); ?></code><br><small>Status pembayaran juga diperiksa langsung ke API Midtrans saat Billing admin atau portal pelanggan dibuka.</small></div>
              <div class="payment-actions">
                <button class="btn bg-primary" type="submit"><i class="fa fa-save"></i> Simpan Pengaturan</button>
              </div>
            </div>
          </div>
        </form>
      </div>

      <div class="payment-layout-test">
        <div class="card">
          <div class="card-header"><h3 class="card-title"><i class="fa fa-plug"></i> Uji Koneksi</h3></div>
          <div class="card-body payment-actions">
            <form method="post"><input type="hidden" name="payment_gateway_action" value="test_midtrans"><input type="hidden" name="payment_gateway_csrf" value="<?= htmlspecialchars(mikhmonPaymentGatewayCsrfToken(), ENT_QUOTES); ?>"><button class="btn bg-secondary" type="submit"><i class="fa fa-refresh"></i> Tes Midtrans</button></form>
            <span class="text-secondary">Simpan kredensial terlebih dahulu sebelum menjalankan pengujian.</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
