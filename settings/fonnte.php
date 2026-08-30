<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon']) || !mikhmonIsAdmin()) {
  header('Location:./admin.php?id=login');
  exit;
}

include_once('./lib/fonnte.php');
$storedFonnteConfig = mikhmonFonnteReadStoredConfig();
$fonnteConfig = mikhmonFonnteReadConfig();
$fonnteEnvironmentToken = getenv('MIKHMON_FONNTE_TOKEN');
$fonnteEnvironmentTokenConfigured = $fonnteEnvironmentToken !== false && trim($fonnteEnvironmentToken) !== '';
$fonnteMessage = '';
$fonnteError = '';
$deviceResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = isset($_POST['fonnte_action']) ? (string) $_POST['fonnte_action'] : '';
  if (!mikhmonFonnteValidCsrf($_POST['fonnte_csrf'] ?? '')) {
    $fonnteError = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
  } elseif ($action === 'save') {
    $token = trim((string) ($_POST['token'] ?? ''));
    // An empty token means keep the existing secret, which prevents accidental deletion.
    if ($token === '') $token = $storedFonnteConfig['token'];
    $newConfig = array(
      'enabled' => isset($_POST['enabled']) && $_POST['enabled'] === '1',
      'token' => $token,
      'country_code' => preg_replace('/[^0-9]/', '', (string) ($_POST['country_code'] ?? '62')),
      'automation_enabled' => isset($_POST['automation_enabled']) && $_POST['automation_enabled'] === '1',
      'reminder_enabled' => isset($_POST['reminder_enabled']) && $_POST['reminder_enabled'] === '1',
      'isolation_enabled' => isset($_POST['isolation_enabled']) && $_POST['isolation_enabled'] === '1',
      'payment_enabled' => isset($_POST['payment_enabled']) && $_POST['payment_enabled'] === '1',
      'reminder_days' => max(1, min(30, (int) ($_POST['reminder_days'] ?? 7))),
      'grace_days' => max(0, min(30, (int) ($_POST['grace_days'] ?? 0))),
      'templates' => array(
        'reminder' => array_key_exists('template_reminder', $_POST) ? trim((string) $_POST['template_reminder']) : ($fonnteConfig['templates']['reminder'] ?? ''),
        'isolation' => array_key_exists('template_isolation', $_POST) ? trim((string) $_POST['template_isolation']) : ($fonnteConfig['templates']['isolation'] ?? ''),
        'payment' => array_key_exists('template_payment', $_POST) ? trim((string) $_POST['template_payment']) : ($fonnteConfig['templates']['payment'] ?? ''),
      ),
    );
    if (mikhmonFonnteWriteConfig($newConfig)) {
      $fonnteConfig = mikhmonFonnteReadConfig();
      $fonnteMessage = 'Pengaturan Fonnte berhasil disimpan.';
    } else {
      $fonnteError = 'Pengaturan Fonnte gagal disimpan. Periksa izin folder data/.';
    }
  } elseif ($action === 'device') {
    $deviceResult = mikhmonFonnteDevice($fonnteConfig);
    if (!empty($deviceResult['status'])) $fonnteMessage = 'Koneksi Fonnte berhasil. Perangkat: ' . ($deviceResult['device'] ?? '-');
    else $fonnteError = (string) ($deviceResult['reason'] ?? 'Status perangkat gagal dibaca.');
  } elseif ($action === 'test') {
    $target = preg_replace('/[^0-9]/', '', (string) ($_POST['test_target'] ?? ''));
    $testResult = mikhmonFonnteSend($target, 'Pesan uji Fonnte dari Mikhmon.', $fonnteConfig);
    if (!empty($testResult['status'])) $fonnteMessage = (string) ($testResult['detail'] ?? 'Pesan uji berhasil masuk antrean Fonnte.');
    else $fonnteError = (string) ($testResult['reason'] ?? 'Pesan uji gagal dikirim.');
  }
}

$maskedToken = $fonnteConfig['token'] !== '' ? str_repeat('*', max(4, min(20, strlen($fonnteConfig['token'])))) : '';
?>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="fa fa-whatsapp"></i> WhatsApp Gateway Fonnte</h3></div>
      <div class="card-body">
        <?php if ($fonnteMessage !== ''): ?><div class="bg-success pd-10 radius-3 mr-b-10"><i class="fa fa-check"></i> <?= htmlspecialchars($fonnteMessage, ENT_QUOTES); ?></div><?php endif; ?>
        <?php if ($fonnteError !== ''): ?><div class="bg-danger pd-10 radius-3 mr-b-10"><i class="fa fa-ban"></i> <?= htmlspecialchars($fonnteError, ENT_QUOTES); ?></div><?php endif; ?>
        <?php if ($fonnteEnvironmentTokenConfigured): ?><div class="bg-info pd-10 radius-3 mr-b-10"><i class="fa fa-info-circle"></i> Token aktif berasal dari environment <code>MIKHMON_FONNTE_TOKEN</code> dan tidak akan disalin ke file konfigurasi.</div><?php endif; ?>
        <form autocomplete="off" method="post" action="">
          <input type="hidden" name="fonnte_action" value="save">
          <input type="hidden" name="fonnte_csrf" value="<?= htmlspecialchars(mikhmonFonnteCsrfToken(), ENT_QUOTES); ?>">
          <div class="row">
            <div class="col-6">
              <table class="table table-sm">
                <tr><td class="align-middle">Status Gateway</td><td><label><input type="checkbox" name="enabled" value="1" <?= !empty($fonnteConfig['enabled']) ? 'checked' : ''; ?>> Aktif</label></td></tr>
                <tr><td class="align-middle">Token Fonnte</td><td><input class="form-control" type="password" name="token" placeholder="<?= $maskedToken !== '' ? 'Token tersimpan, kosongkan untuk mempertahankan' : 'Masukkan token Fonnte'; ?>"></td></tr>
                <tr><td class="align-middle">Kode Negara</td><td><input class="form-control" type="text" inputmode="numeric" maxlength="5" name="country_code" value="<?= htmlspecialchars($fonnteConfig['country_code'], ENT_QUOTES); ?>"></td></tr>
                <tr><td class="align-middle">Otomasi Billing</td><td><label><input type="checkbox" name="automation_enabled" value="1" <?= !empty($fonnteConfig['automation_enabled']) ? 'checked' : ''; ?>> Aktif</label></td></tr>
                <tr><td class="align-middle">Pengingat</td><td><label><input type="checkbox" name="reminder_enabled" value="1" <?= !empty($fonnteConfig['reminder_enabled']) ? 'checked' : ''; ?>> Kirim pengingat</label> <input class="form-control" style="display:inline-block;width:90px" type="number" min="1" max="30" name="reminder_days" value="<?= (int) ($fonnteConfig['reminder_days'] ?? 7); ?>"> hari sebelum jatuh tempo</td></tr>
                <tr><td class="align-middle">Isolir Otomatis</td><td><label><input type="checkbox" name="isolation_enabled" value="1" <?= !empty($fonnteConfig['isolation_enabled']) ? 'checked' : ''; ?>> Isolir saat lewat jatuh tempo</label> <input class="form-control" style="display:inline-block;width:90px" type="number" min="0" max="30" name="grace_days" value="<?= (int) ($fonnteConfig['grace_days'] ?? 0); ?>"> hari toleransi</td></tr>
                <tr><td class="align-middle">Notifikasi Bayar</td><td><label><input type="checkbox" name="payment_enabled" value="1" <?= !empty($fonnteConfig['payment_enabled']) ? 'checked' : ''; ?>> Kirim setelah pembayaran</label></td></tr>
              </table>
            </div>
          </div>
          <button class="btn bg-primary" type="submit"><i class="fa fa-save"></i> Simpan Pengaturan</button>
        </form>

        <hr>
        <h4><i class="fa fa-pencil"></i> Template Pesan Otomatis</h4>
        <p>Variabel yang tersedia: <code>{{nama_pelanggan}}</code>, <code>{{nama_brand}}</code>, <code>{{nomor_invoice}}</code>, <code>{{total_tagihan}}</code>, <code>{{jatuh_tempo}}</code>, <code>{{detail_layanan}}</code>, <code>{{tanggal_bayar}}</code>, <code>{{jatuh_tempo_berikutnya}}</code>.</p>
        <form autocomplete="off" method="post" action="">
          <input type="hidden" name="fonnte_action" value="save">
          <input type="hidden" name="fonnte_csrf" value="<?= htmlspecialchars(mikhmonFonnteCsrfToken(), ENT_QUOTES); ?>">
          <input type="hidden" name="enabled" value="<?= !empty($fonnteConfig['enabled']) ? '1' : '0'; ?>">
          <input type="hidden" name="automation_enabled" value="<?= !empty($fonnteConfig['automation_enabled']) ? '1' : '0'; ?>">
          <input type="hidden" name="reminder_enabled" value="<?= !empty($fonnteConfig['reminder_enabled']) ? '1' : '0'; ?>">
          <input type="hidden" name="isolation_enabled" value="<?= !empty($fonnteConfig['isolation_enabled']) ? '1' : '0'; ?>">
          <input type="hidden" name="payment_enabled" value="<?= !empty($fonnteConfig['payment_enabled']) ? '1' : '0'; ?>">
          <input type="hidden" name="country_code" value="<?= htmlspecialchars($fonnteConfig['country_code'], ENT_QUOTES); ?>">
          <input type="hidden" name="reminder_days" value="<?= (int) ($fonnteConfig['reminder_days'] ?? 7); ?>"><input type="hidden" name="grace_days" value="<?= (int) ($fonnteConfig['grace_days'] ?? 0); ?>">
          <div class="row"><div class="col-6">
            <label>Pengingat H-<?= (int) ($fonnteConfig['reminder_days'] ?? 7); ?><textarea class="form-control" name="template_reminder" rows="7" required><?= htmlspecialchars($fonnteConfig['templates']['reminder'] ?? '', ENT_QUOTES); ?></textarea></label>
            <label>Pesan Isolir<textarea class="form-control" name="template_isolation" rows="6" required><?= htmlspecialchars($fonnteConfig['templates']['isolation'] ?? '', ENT_QUOTES); ?></textarea></label>
            <label>Konfirmasi Pembayaran &amp; Aktif Kembali<textarea class="form-control" name="template_payment" rows="6" required><?= htmlspecialchars($fonnteConfig['templates']['payment'] ?? '', ENT_QUOTES); ?></textarea></label>
          </div></div>
          <button class="btn bg-primary" type="submit"><i class="fa fa-save"></i> Simpan Template</button>
        </form>

        <hr>
        <div class="row">
          <div class="col-6">
            <h4><i class="fa fa-mobile"></i> Status Perangkat</h4>
            <form method="post"><input type="hidden" name="fonnte_action" value="device"><input type="hidden" name="fonnte_csrf" value="<?= htmlspecialchars(mikhmonFonnteCsrfToken(), ENT_QUOTES); ?>"><button class="btn bg-secondary" type="submit"><i class="fa fa-refresh"></i> Cek Status Fonnte</button></form>
            <?php if (is_array($deviceResult)): ?><div class="box-bordered pd-10 mr-t-10"><b><?= !empty($deviceResult['device_status']) ? htmlspecialchars($deviceResult['device_status'], ENT_QUOTES) : 'Tidak terhubung'; ?></b><?php if (!empty($deviceResult['quota'])): ?> &middot; Kuota: <?= htmlspecialchars((string) $deviceResult['quota'], ENT_QUOTES); ?><?php endif; ?></div><?php endif; ?>
          </div>
          <div class="col-6">
            <h4><i class="fa fa-paper-plane"></i> Pesan Uji</h4>
            <form autocomplete="off" method="post">
              <input type="hidden" name="fonnte_action" value="test">
              <input type="hidden" name="fonnte_csrf" value="<?= htmlspecialchars(mikhmonFonnteCsrfToken(), ENT_QUOTES); ?>">
              <div class="input-group"><input class="form-control" type="text" name="test_target" inputmode="tel" placeholder="08123456789" required><button class="btn bg-green" type="submit"><i class="fa fa-send"></i> Kirim</button></div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
