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
  'Xendit Secret Key' => 'MIKHMON_XENDIT_SECRET_KEY',
  'Xendit Public Key' => 'MIKHMON_XENDIT_PUBLIC_KEY',
  'Xendit Webhook Token' => 'MIKHMON_XENDIT_WEBHOOK_TOKEN',
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
    $xenditSecretKey = mikhmonPaymentGatewayCleanSecret($_POST['xendit_secret_key'] ?? '');
    $xenditPublicKey = mikhmonPaymentGatewayCleanSecret($_POST['xendit_public_key'] ?? '');
    $xenditWebhookToken = mikhmonPaymentGatewayCleanSecret($_POST['xendit_webhook_token'] ?? '');
    if ($midtransServerKey === '') $midtransServerKey = $storedPaymentConfig['midtrans']['server_key'];
    if ($midtransClientKey === '') $midtransClientKey = $storedPaymentConfig['midtrans']['client_key'];
    if ($xenditSecretKey === '') $xenditSecretKey = $storedPaymentConfig['xendit']['secret_key'];
    if ($xenditPublicKey === '') $xenditPublicKey = $storedPaymentConfig['xendit']['public_key'];
    if ($xenditWebhookToken === '') $xenditWebhookToken = $storedPaymentConfig['xendit']['webhook_token'];

    $newPaymentConfig = array(
      'enabled' => isset($_POST['enabled']) && $_POST['enabled'] === '1',
      'default_gateway' => isset($_POST['default_gateway']) && $_POST['default_gateway'] === 'xendit' ? 'xendit' : 'midtrans',
      'currency' => $_POST['currency'] ?? 'IDR',
      'invoice_duration' => (int) ($_POST['invoice_duration'] ?? 86400),
      'midtrans' => array(
        'enabled' => isset($_POST['midtrans_enabled']) && $_POST['midtrans_enabled'] === '1',
        'environment' => isset($_POST['midtrans_environment']) && $_POST['midtrans_environment'] === 'production' ? 'production' : 'sandbox',
        'merchant_id' => trim((string) ($_POST['midtrans_merchant_id'] ?? '')),
        'server_key' => $midtransServerKey,
        'client_key' => $midtransClientKey,
      ),
      'xendit' => array(
        'enabled' => isset($_POST['xendit_enabled']) && $_POST['xendit_enabled'] === '1',
        'secret_key' => $xenditSecretKey,
        'public_key' => $xenditPublicKey,
        'webhook_token' => $xenditWebhookToken,
      ),
    );

    $normalizedPaymentConfig = mikhmonPaymentGatewayNormalizeConfig($newPaymentConfig);
    if ($normalizedPaymentConfig['enabled'] && !$normalizedPaymentConfig['midtrans']['enabled'] && !$normalizedPaymentConfig['xendit']['enabled']) {
      $paymentError = 'Aktifkan minimal satu provider sebelum mengaktifkan Payment Gateway.';
    } elseif ($normalizedPaymentConfig['midtrans']['enabled'] && $normalizedPaymentConfig['midtrans']['server_key'] === '') {
      $paymentError = 'Server Key Midtrans wajib diisi ketika Midtrans diaktifkan.';
    } elseif ($normalizedPaymentConfig['xendit']['enabled'] && $normalizedPaymentConfig['xendit']['secret_key'] === '') {
      $paymentError = 'Secret API Key Xendit wajib diisi ketika Xendit diaktifkan.';
    } elseif (mikhmonPaymentGatewayWriteConfig($normalizedPaymentConfig)) {
      $storedPaymentConfig = mikhmonPaymentGatewayReadStoredConfig();
      $paymentConfig = mikhmonPaymentGatewayReadConfig();
      $paymentMessage = 'Pengaturan Payment Gateway berhasil disimpan.';
    } else {
      $paymentError = 'Pengaturan Payment Gateway gagal disimpan. Periksa izin folder data/.';
    }
  } elseif ($action === 'test_midtrans' || $action === 'test_xendit') {
    $provider = $action === 'test_xendit' ? 'xendit' : 'midtrans';
    $testResult = mikhmonPaymentGatewayTestConnection($provider, $paymentConfig);
    if (!empty($testResult['success'])) $paymentMessage = $testResult['message'];
    else $paymentError = $testResult['message'];
  }
}

$paymentBaseUrl = mikhmonPaymentGatewayBaseUrl();
$midtransWebhookUrl = $paymentBaseUrl !== '' ? $paymentBaseUrl . '/payment-notification.php?provider=midtrans' : 'https://domain-anda/payment-notification.php?provider=midtrans';
$xenditWebhookUrl = $paymentBaseUrl !== '' ? $paymentBaseUrl . '/payment-notification.php?provider=xendit' : 'https://domain-anda/payment-notification.php?provider=xendit';
?>
<style>
.payment-settings-hero {
  background: linear-gradient(125deg, #0f766e, #155e75);
  color: #fff;
  border-radius: 5px;
  padding: 18px 20px;
  margin-bottom: 15px;
}
.payment-settings-hero h3 { margin: 0 0 6px; color: #fff; }
.payment-settings-grid { display: flex; flex-wrap: wrap; margin: 0 -7px; }
.payment-settings-column { width: 50%; padding: 0 7px; box-sizing: border-box; }
.payment-provider-card { border-top: 4px solid #0f766e; height: calc(100% - 14px); }
.payment-provider-card.xendit { border-top-color: #2563eb; }
.payment-provider-heading { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.payment-provider-heading h3 { margin: 0; }
.payment-provider-badge { border-radius: 20px; padding: 3px 9px; font-size: 11px; font-weight: bold; letter-spacing: .4px; }
.payment-provider-badge.sandbox { background: #fff3cd; color: #856404; }
.payment-provider-badge.production { background: #d4edda; color: #155724; }
.payment-settings-table td:first-child { width: 155px; font-weight: 600; }
.payment-secret-note { color: #777; display: block; font-size: 11px; margin-top: 4px; }
.payment-webhook-box { background: rgba(0,0,0,.035); border-left: 3px solid #0f766e; padding: 10px; word-break: break-all; }
.payment-webhook-box code { white-space: normal; }
.payment-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
@media screen and (max-width: 750px) {
  .payment-settings-column { width: 100%; }
  .payment-settings-table, .payment-settings-table tbody, .payment-settings-table tr, .payment-settings-table td { display: block; width: 100%; box-sizing: border-box; }
  .payment-settings-table td:first-child { width: 100%; padding-bottom: 3px; }
  .payment-settings-table td:last-child { padding-top: 3px; }
  .payment-settings-hero { padding: 15px; }
}
</style>
<div class="row">
  <div class="col-12">
    <div class="payment-settings-hero">
      <h3><i class="fa fa-credit-card"></i> Payment Gateway</h3>
      <div>Kelola pembayaran invoice melalui Midtrans dan Xendit dari satu tempat.</div>
    </div>

    <?php if ($paymentMessage !== ''): ?><div class="bg-success pd-10 radius-3 mr-b-10"><i class="fa fa-check"></i> <?= htmlspecialchars($paymentMessage, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($paymentError !== ''): ?><div class="bg-danger pd-10 radius-3 mr-b-10"><i class="fa fa-ban"></i> <?= htmlspecialchars($paymentError, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($activePaymentEnvironmentSecrets): ?><div class="bg-info pd-10 radius-3 mr-b-10"><i class="fa fa-shield"></i> Kredensial aktif dari environment: <?= htmlspecialchars(implode(', ', $activePaymentEnvironmentSecrets), ENT_QUOTES); ?>. Nilai environment tidak disalin ke file konfigurasi.</div><?php endif; ?>

    <form autocomplete="off" method="post" action="">
      <input type="hidden" name="payment_gateway_action" value="save">
      <input type="hidden" name="payment_gateway_csrf" value="<?= htmlspecialchars(mikhmonPaymentGatewayCsrfToken(), ENT_QUOTES); ?>">

      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fa fa-sliders"></i> Pengaturan Umum</h3></div>
        <div class="card-body">
          <div class="row"><div class="col-6">
            <table class="table table-sm payment-settings-table">
              <tr><td class="align-middle">Status Gateway</td><td><label><input type="checkbox" name="enabled" value="1" <?= !empty($paymentConfig['enabled']) ? 'checked' : ''; ?>> Aktifkan pembayaran online</label></td></tr>
              <tr><td class="align-middle">Gateway Utama</td><td><select class="form-control" name="default_gateway"><option value="midtrans" <?= $paymentConfig['default_gateway'] === 'midtrans' ? 'selected' : ''; ?>>Midtrans</option><option value="xendit" <?= $paymentConfig['default_gateway'] === 'xendit' ? 'selected' : ''; ?>>Xendit</option></select></td></tr>
              <tr><td class="align-middle">Mata Uang</td><td><input class="form-control" type="text" name="currency" maxlength="3" value="<?= htmlspecialchars($paymentConfig['currency'], ENT_QUOTES); ?>" required></td></tr>
              <tr><td class="align-middle">Masa Berlaku</td><td><select class="form-control" name="invoice_duration"><?php foreach (array(3600=>'1 jam',21600=>'6 jam',43200=>'12 jam',86400=>'24 jam') as $seconds => $label): ?><option value="<?= $seconds; ?>" <?= (int) $paymentConfig['invoice_duration'] === $seconds ? 'selected' : ''; ?>><?= $label; ?></option><?php endforeach; ?></select></td></tr>
            </table>
          </div></div>
        </div>
      </div>

      <div class="payment-settings-grid">
        <div class="payment-settings-column">
          <div class="card payment-provider-card">
            <div class="card-header payment-provider-heading">
              <h3 class="card-title"><i class="fa fa-credit-card-alt"></i> Midtrans</h3>
              <span class="payment-provider-badge <?= $paymentConfig['midtrans']['environment']; ?>"><?= strtoupper($paymentConfig['midtrans']['environment']); ?></span>
            </div>
            <div class="card-body">
              <table class="table table-sm payment-settings-table">
                <tr><td class="align-middle">Status</td><td><label><input type="checkbox" name="midtrans_enabled" value="1" <?= !empty($paymentConfig['midtrans']['enabled']) ? 'checked' : ''; ?>> Aktif</label></td></tr>
                <tr><td class="align-middle">Mode</td><td><select class="form-control" name="midtrans_environment"><option value="sandbox" <?= $paymentConfig['midtrans']['environment'] === 'sandbox' ? 'selected' : ''; ?>>Sandbox</option><option value="production" <?= $paymentConfig['midtrans']['environment'] === 'production' ? 'selected' : ''; ?>>Production</option></select></td></tr>
                <tr><td class="align-middle">Merchant ID</td><td><input class="form-control" type="text" name="midtrans_merchant_id" value="<?= htmlspecialchars($storedPaymentConfig['midtrans']['merchant_id'], ENT_QUOTES); ?>" placeholder="G123456789"></td></tr>
                <tr><td class="align-middle">Server Key</td><td><input class="form-control" type="password" name="midtrans_server_key" placeholder="<?= $paymentConfig['midtrans']['server_key'] !== '' ? htmlspecialchars('Tersimpan: ' . mikhmonPaymentGatewayMask($paymentConfig['midtrans']['server_key']), ENT_QUOTES) : 'SB-Mid-server-...'; ?>"><small class="payment-secret-note">Kosongkan untuk mempertahankan key yang tersimpan.</small></td></tr>
                <tr><td class="align-middle">Client Key</td><td><input class="form-control" type="password" name="midtrans_client_key" placeholder="<?= $paymentConfig['midtrans']['client_key'] !== '' ? htmlspecialchars('Tersimpan: ' . mikhmonPaymentGatewayMask($paymentConfig['midtrans']['client_key']), ENT_QUOTES) : 'SB-Mid-client-...'; ?>"><small class="payment-secret-note">Digunakan saat Snap ditampilkan di browser.</small></td></tr>
              </table>
              <div class="payment-webhook-box"><b>Payment Notification URL</b><br><code><?= htmlspecialchars($midtransWebhookUrl, ENT_QUOTES); ?></code></div>
            </div>
          </div>
        </div>

        <div class="payment-settings-column">
          <div class="card payment-provider-card xendit">
            <div class="card-header payment-provider-heading">
              <h3 class="card-title"><i class="fa fa-bolt"></i> Xendit</h3>
              <span class="payment-provider-badge production">API</span>
            </div>
            <div class="card-body">
              <table class="table table-sm payment-settings-table">
                <tr><td class="align-middle">Status</td><td><label><input type="checkbox" name="xendit_enabled" value="1" <?= !empty($paymentConfig['xendit']['enabled']) ? 'checked' : ''; ?>> Aktif</label></td></tr>
                <tr><td class="align-middle">Secret API Key</td><td><input class="form-control" type="password" name="xendit_secret_key" placeholder="<?= $paymentConfig['xendit']['secret_key'] !== '' ? htmlspecialchars('Tersimpan: ' . mikhmonPaymentGatewayMask($paymentConfig['xendit']['secret_key']), ENT_QUOTES) : 'xnd_development_...'; ?>"><small class="payment-secret-note">Gunakan development key untuk pengujian.</small></td></tr>
                <tr><td class="align-middle">Public API Key</td><td><input class="form-control" type="password" name="xendit_public_key" placeholder="<?= $paymentConfig['xendit']['public_key'] !== '' ? htmlspecialchars('Tersimpan: ' . mikhmonPaymentGatewayMask($paymentConfig['xendit']['public_key']), ENT_QUOTES) : 'xnd_public_development_...'; ?>"></td></tr>
                <tr><td class="align-middle">Webhook Token</td><td><input class="form-control" type="password" name="xendit_webhook_token" placeholder="<?= $paymentConfig['xendit']['webhook_token'] !== '' ? htmlspecialchars('Tersimpan: ' . mikhmonPaymentGatewayMask($paymentConfig['xendit']['webhook_token']), ENT_QUOTES) : 'Verification token dari Xendit'; ?>"><small class="payment-secret-note">Verification token wajib untuk memvalidasi callback.</small></td></tr>
              </table>
              <div class="payment-webhook-box"><b>Invoice Callback URL</b><br><code><?= htmlspecialchars($xenditWebhookUrl, ENT_QUOTES); ?></code></div>
            </div>
          </div>
        </div>
      </div>

      <div class="card"><div class="card-body payment-actions">
        <button class="btn bg-primary" type="submit"><i class="fa fa-save"></i> Simpan Pengaturan</button>
      </div></div>
    </form>

    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="fa fa-plug"></i> Uji Koneksi</h3></div>
      <div class="card-body payment-actions">
        <form method="post"><input type="hidden" name="payment_gateway_action" value="test_midtrans"><input type="hidden" name="payment_gateway_csrf" value="<?= htmlspecialchars(mikhmonPaymentGatewayCsrfToken(), ENT_QUOTES); ?>"><button class="btn bg-secondary" type="submit"><i class="fa fa-refresh"></i> Tes Midtrans</button></form>
        <form method="post"><input type="hidden" name="payment_gateway_action" value="test_xendit"><input type="hidden" name="payment_gateway_csrf" value="<?= htmlspecialchars(mikhmonPaymentGatewayCsrfToken(), ENT_QUOTES); ?>"><button class="btn bg-secondary" type="submit"><i class="fa fa-refresh"></i> Tes Xendit</button></form>
        <span class="text-secondary">Simpan kredensial terlebih dahulu sebelum menjalankan pengujian.</span>
      </div>
    </div>
  </div>
</div>
