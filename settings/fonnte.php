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
                <tr><td class="align-middle">Status</td><td><label><input type="checkbox" name="enabled" value="1" <?= !empty($fonnteConfig['enabled']) ? 'checked' : ''; ?>> Aktif</label></td></tr>
                <tr><td class="align-middle">Token Fonnte</td><td><input class="form-control" type="password" name="token" placeholder="<?= $maskedToken !== '' ? 'Token tersimpan, kosongkan untuk mempertahankan' : 'Masukkan token Fonnte'; ?>"></td></tr>
                <tr><td class="align-middle">Kode Negara</td><td><input class="form-control" type="text" inputmode="numeric" maxlength="5" name="country_code" value="<?= htmlspecialchars($fonnteConfig['country_code'], ENT_QUOTES); ?>"></td></tr>
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
              <div class="input-group"><input class="form-control" type="text" name="test_target" inputmode="tel" placeholder="08123456789" required><button class="btn bg-green" type="submit"><i class="fa fa-send"></i> Kirim</button></div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
