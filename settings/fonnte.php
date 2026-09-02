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
      'payment_link_enabled' => isset($_POST['payment_link_enabled']) && $_POST['payment_link_enabled'] === '1',
      'reminder_days' => max(1, min(30, (int) ($_POST['reminder_days'] ?? 7))),
      'grace_days' => max(0, min(30, (int) ($_POST['grace_days'] ?? 0))),
      'queue_min_delay_minutes' => max(1, min(120, (int) ($_POST['queue_min_delay_minutes'] ?? 5))),
      'queue_max_delay_minutes' => max(1, min(240, (int) ($_POST['queue_max_delay_minutes'] ?? 20))),
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
<style>
.fonnte-settings-table td:first-child {
  width: 145px;
  font-weight: 600;
}
.fonnte-automation-control {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px 10px;
}
.fonnte-automation-check,
.fonnte-day-control {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin: 0;
}
.fonnte-automation-check {
  min-width: 190px;
}
.fonnte-day-control {
  white-space: nowrap;
}
.fonnte-day-control .form-control {
  width: 60px;
  margin: 0;
  text-align: center;
}
.fonnte-automation-check input {
  margin: 0;
}
.fonnte-test-group {
  display: flex;
  align-items: center;
  gap: 5px;
}
.fonnte-test-group .form-control {
  flex: 1 1 auto;
  width: auto;
}
.fonnte-test-group .btn {
  flex: 0 0 auto;
  margin: 5px 0 5px 0;
}
.fonnte-payment-note {
  display: inline-block;
  margin-left: 8px;
  color: #777;
  font-size: 11px;
}
@media screen and (max-width: 750px) {
  .fonnte-settings-table td:first-child {
    width: 115px;
  }
  .fonnte-automation-check {
    min-width: 0;
    width: 100%;
  }
  .fonnte-payment-note {
    display: block;
    margin-left: 0;
    margin-top: 4px;
  }
}
</style>
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
              <table class="table table-sm fonnte-settings-table">
                <tr><td class="align-middle">Status Gateway</td><td><label><input type="checkbox" name="enabled" value="1" <?= !empty($fonnteConfig['enabled']) ? 'checked' : ''; ?>> Aktif</label></td></tr>
                <tr><td class="align-middle">Token Fonnte</td><td><input class="form-control" type="password" name="token" placeholder="<?= $maskedToken !== '' ? 'Token tersimpan, kosongkan untuk mempertahankan' : 'Masukkan token Fonnte'; ?>"></td></tr>
                <tr><td class="align-middle">Kode Negara</td><td><input class="form-control" type="text" inputmode="numeric" maxlength="5" name="country_code" value="<?= htmlspecialchars($fonnteConfig['country_code'], ENT_QUOTES); ?>"></td></tr>
                <tr><td class="align-middle">Otomasi Billing</td><td><label><input type="checkbox" name="automation_enabled" value="1" <?= !empty($fonnteConfig['automation_enabled']) ? 'checked' : ''; ?>> Aktif</label></td></tr>
                <tr>
                  <td class="align-middle">Pengingat</td>
                  <td>
                    <div class="fonnte-automation-control">
                      <label class="fonnte-automation-check"><input type="checkbox" name="reminder_enabled" value="1" <?= !empty($fonnteConfig['reminder_enabled']) ? 'checked' : ''; ?>> <span>Kirim pengingat</span></label>
                      <div class="fonnte-day-control"><input class="form-control" type="number" min="1" max="30" name="reminder_days" value="<?= (int) ($fonnteConfig['reminder_days'] ?? 7); ?>"> <span>hari sebelum jatuh tempo</span></div>
                    </div>
                  </td>
                </tr>
                <tr>
                  <td class="align-middle">Isolir Otomatis</td>
                  <td>
                    <div class="fonnte-automation-control">
                      <label class="fonnte-automation-check"><input type="checkbox" name="isolation_enabled" value="1" <?= !empty($fonnteConfig['isolation_enabled']) ? 'checked' : ''; ?>> <span>Isolir saat lewat jatuh tempo</span></label>
                      <div class="fonnte-day-control"><input class="form-control" type="number" min="0" max="30" name="grace_days" value="<?= (int) ($fonnteConfig['grace_days'] ?? 0); ?>"> <span>hari toleransi</span></div>
                    </div>
                  </td>
                </tr>
                <tr><td class="align-middle">Notifikasi Bayar</td><td><label><input type="checkbox" name="payment_enabled" value="1" <?= !empty($fonnteConfig['payment_enabled']) ? 'checked' : ''; ?>> Kirim setelah pembayaran</label></td></tr>
                <tr><td class="align-middle">Link Pembayaran</td><td><label><input type="checkbox" name="payment_link_enabled" value="1" <?= !empty($fonnteConfig['payment_link_enabled']) ? 'checked' : ''; ?>> Kirim link otomatis</label><small class="payment-secret-note fonnte-payment-note">Perlu Payment Gateway dan Fonnte aktif.</small></td></tr>
                <tr>
                  <td class="align-middle">Jeda Pesan</td>
                  <td>
                    <div class="fonnte-automation-control">
                      <div class="fonnte-day-control"><input class="form-control" type="number" min="1" max="120" name="queue_min_delay_minutes" value="<?= (int) ($fonnteConfig['queue_min_delay_minutes'] ?? 5); ?>"> <span>sampai</span></div>
                      <div class="fonnte-day-control"><input class="form-control" type="number" min="1" max="240" name="queue_max_delay_minutes" value="<?= (int) ($fonnteConfig['queue_max_delay_minutes'] ?? 20); ?>"> <span>menit (acak)</span></div>
                    </div>
                  </td>
                </tr>
              </table>
            </div>
          </div>
          <button class="btn bg-primary" type="submit"><i class="fa fa-save"></i> Simpan Pengaturan</button>
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
              <div class="input-group fonnte-test-group"><input class="form-control" type="text" name="test_target" inputmode="tel" placeholder="08123456789" required><button class="btn bg-green" type="submit"><i class="fa fa-send"></i> Kirim</button></div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
