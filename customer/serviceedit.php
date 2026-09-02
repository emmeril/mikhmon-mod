<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
include_once('./include/database.php');
include_once('./lib/billing_profile.php');
include_once('./ppp/profilemeta.php');

$serviceEditError = '';
$serviceCustomerId = (string) ($_GET['customer-id'] ?? $_POST['customer_id'] ?? '');
$serviceId = (string) ($_GET['service-id'] ?? $_POST['service_id'] ?? '');
$serviceCustomer = $serviceCustomerId !== '' ? mikhmonFindCustomer($session, $serviceCustomerId) : false;
$editedService = array();
if ($serviceCustomer && mikhmonCanManageCustomer($serviceCustomer)) {
  foreach (mikhmonCustomerServices($serviceCustomer) as $candidate) {
    if ((string) ($candidate['id'] ?? '') === $serviceId) { $editedService = $candidate; break; }
  }
} elseif ($serviceCustomer) {
  $serviceEditError = 'Anda tidak berhak mengelola layanan ini.';
  $serviceCustomer = false;
}
if (!$serviceCustomer || !$editedService) $serviceEditError = $serviceEditError ?: 'Layanan pelanggan tidak ditemukan.';

function serviceEditApiError($response) {
  if (!is_array($response)) return '';
  foreach (array('!trap', '!fatal') as $type) if (isset($response[$type][0]['message'])) return (string) $response[$type][0]['message'];
  return '';
}

$hotspotProfiles = $hotspotServers = $pppoeProfiles = array();
if (!empty($routerConnected)) {
  $hotspotProfiles = $API->comm('/ip/hotspot/user/profile/print');
  $hotspotServers = $API->comm('/ip/hotspot/print');
  $pppoeProfiles = $API->comm('/ppp/profile/print');
  if (!is_array($hotspotProfiles) || serviceEditApiError($hotspotProfiles) !== '') $hotspotProfiles = array();
  if (!is_array($hotspotServers) || serviceEditApiError($hotspotServers) !== '') $hotspotServers = array();
  if (!is_array($pppoeProfiles) || serviceEditApiError($pppoeProfiles) !== '') $pppoeProfiles = array();
}
$billingHotspotProfiles = array_values(array_filter($hotspotProfiles, function ($profile) { return isset($profile['name']) && mikhmonBillingProfileCanManage('hotspot', $profile); }));
$billingPppoeProfiles = array_values(array_filter($pppoeProfiles, function ($profile) { return isset($profile['name']) && mikhmonBillingProfileCanManage('pppoe', $profile); }));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['service_action'] ?? '') === 'update' && $serviceCustomer && $editedService) {
  $serviceType = ($editedService['service'] ?? '') === 'pppoe' ? 'pppoe' : 'hotspot';
  $username = trim((string) ($_POST['service_username'] ?? ''));
  $password = (string) ($_POST['service_password'] ?? '');
  $profile = trim((string) ($_POST['service_profile'] ?? ''));
  $server = $serviceType === 'hotspot' ? (trim((string) ($_POST['service_server'] ?? 'all')) ?: 'all') : 'all';

  if (empty($routerConnected)) $serviceEditError = 'Router MikroTik tidak terhubung.';
  elseif ($username === '' || $profile === '') $serviceEditError = 'Username dan profile wajib diisi.';
  else {
    foreach (mikhmonGetCustomers($session) as $candidateCustomer) {
      foreach (mikhmonCustomerServices($candidateCustomer) as $candidateService) {
        if ((string) ($candidateService['id'] ?? '') === $serviceId && (string) ($candidateCustomer['id'] ?? '') === $serviceCustomerId) continue;
        if (($candidateService['service'] ?? '') === $serviceType && strtolower((string) ($candidateService['username'] ?? '')) === strtolower($username)) {
          $serviceEditError = 'Username ' . $username . ' sudah dikaitkan ke pelanggan lain.';
          break 2;
        }
      }
    }
  }

  if ($serviceEditError === '') {
    $profileRows = $serviceType === 'pppoe' ? $pppoeProfiles : $hotspotProfiles;
    $profileRow = array();
    foreach ($profileRows as $candidate) if (isset($candidate['name']) && (string) $candidate['name'] === $profile) { $profileRow = $candidate; break; }
    if (!$profileRow || !mikhmonBillingProfileCanManage($serviceType, $profileRow)) $serviceEditError = 'Profile yang dikelola Billing wajib menggunakan Expired Mode = None.';
  }

  $command = $serviceType === 'pppoe' ? '/ppp/secret' : '/ip/hotspot/user';
  $oldUsername = (string) ($editedService['username'] ?? '');
  $routerRow = array();
  if ($serviceEditError === '') {
    $rows = $API->comm($command . '/print', array('?name' => $oldUsername));
    $apiError = serviceEditApiError($rows);
    if ($apiError !== '') $serviceEditError = 'Gagal membaca layanan MikroTik: ' . $apiError;
    elseif (!isset($rows[0]['.id'])) $serviceEditError = 'User MikroTik ' . $oldUsername . ' tidak ditemukan.';
    else $routerRow = $rows[0];
  }
  if ($serviceEditError === '' && strtolower($username) !== strtolower($oldUsername)) {
    $existingRows = $API->comm($command . '/print', array('?name' => $username));
    $apiError = serviceEditApiError($existingRows);
    if ($apiError !== '') $serviceEditError = 'Gagal memeriksa username MikroTik: ' . $apiError;
    elseif (isset($existingRows[0]['.id']) && (string) $existingRows[0]['.id'] !== (string) $routerRow['.id']) $serviceEditError = 'Username ' . $username . ' sudah digunakan di MikroTik.';
  }

  if ($serviceEditError === '') {
    $comment = $serviceType === 'pppoe' ? (string) $serviceCustomer['name'] : 'up-' . (string) $serviceCustomer['name'];
    if (mikhmonIsMitra()) $comment .= ' ' . mikhmonOwnerTag();
    $setArgs = array('.id' => $routerRow['.id'], 'name' => $username, 'profile' => $profile, 'comment' => $comment);
    if ($serviceType === 'pppoe') $setArgs['service'] = 'pppoe'; else $setArgs['server'] = $server;
    if ($password !== '') $setArgs['password'] = $password;
    $response = $API->comm($command . '/set', $setArgs);
    $apiError = serviceEditApiError($response);
    if ($apiError !== '') $serviceEditError = 'MikroTik menolak perubahan layanan: ' . $apiError;
    else {
      $saved = mikhmonUpdateCustomerService($session, $serviceCustomerId, $serviceId, array(
        'service' => $serviceType, 'username' => $username, 'profile' => $profile, 'server' => $server,
      ));
      if ($saved === false) {
        $rollbackArgs = array(
          '.id' => $routerRow['.id'],
          'name' => $routerRow['name'] ?? $oldUsername,
          'profile' => $routerRow['profile'] ?? ($editedService['profile'] ?? ''),
        );
        if (array_key_exists('password', $routerRow)) $rollbackArgs['password'] = $routerRow['password'];
        if (array_key_exists('comment', $routerRow)) $rollbackArgs['comment'] = $routerRow['comment'];
        if ($serviceType === 'pppoe') $rollbackArgs['service'] = $routerRow['service'] ?? 'pppoe';
        else $rollbackArgs['server'] = $routerRow['server'] ?? ($editedService['server'] ?? 'all');
        $API->comm($command . '/set', $rollbackArgs);
        $serviceEditError = 'Data layanan gagal disimpan. Perubahan MikroTik telah dikembalikan.';
      } else {
        $schedulerName = 'mikhmon-customer-' . substr(md5($serviceCustomerId), 0, 12);
        $schedulerRows = $API->comm('/system/scheduler/print', array('?name' => $schedulerName));
        if (serviceEditApiError($schedulerRows) === '') foreach ((array) $schedulerRows as $schedulerRow) if (isset($schedulerRow['.id'])) $API->comm('/system/scheduler/remove', array('.id' => $schedulerRow['.id']));
        $query = './?customer=list&session=' . rawurlencode($session) . '&service-updated=1';
        echo '<script>window.location=' . json_encode($query) . '</script>'; exit;
      }
    }
  }
}

$serviceType = ($editedService['service'] ?? '') === 'pppoe' ? 'pppoe' : 'hotspot';
$formUsername = $_POST['service_username'] ?? ($editedService['username'] ?? '');
$formProfile = $_POST['service_profile'] ?? ($editedService['profile'] ?? '');
$formServer = $_POST['service_server'] ?? ($editedService['server'] ?? 'all');
$profileOptions = $serviceType === 'pppoe' ? $billingPppoeProfiles : $billingHotspotProfiles;
?>
<style>
  .service-edit-card{max-width:780px;margin:0 auto}
  .service-edit-fields{display:grid;grid-template-columns:1fr;gap:14px}
  .service-edit-fields label{display:block;margin-bottom:6px;font-size:12px;font-weight:bold;color:#d7dbe0}
  .service-edit-type{padding:9px 12px;border-radius:3px;background:rgba(255,255,255,.08);font-weight:bold}
  .service-edit-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}
  .service-edit-actions .btn,.service-edit-actions a{box-sizing:border-box;margin:0}
  @media(max-width:767px){.service-edit-actions{flex-direction:column}.service-edit-actions .btn,.service-edit-actions a{width:100%;text-align:center}}
</style>
<div class="row"><div class="col-12"><div class="card box-bordered service-edit-card">
  <div class="card-header"><h3><i class="fa fa-pencil"></i> Edit Layanan</h3></div>
  <div class="card-body">
    <?php if ($serviceEditError !== ''): ?><div class="box bg-danger"><i class="fa fa-warning"></i> <?= htmlspecialchars($serviceEditError, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if (empty($routerConnected)): ?><div class="box bg-warning">Router MikroTik tidak terhubung. Layanan belum dapat diperbarui.</div><?php endif; ?>
    <?php if ($serviceCustomer && $editedService): ?>
    <form method="post" autocomplete="off"><input type="hidden" name="service_action" value="update"><input type="hidden" name="customer_id" value="<?= htmlspecialchars($serviceCustomerId, ENT_QUOTES); ?>"><input type="hidden" name="service_id" value="<?= htmlspecialchars($serviceId, ENT_QUOTES); ?>">
      <div class="service-edit-fields">
        <div><label>Identitas Pelanggan</label><div class="service-edit-type"><?= htmlspecialchars($serviceCustomer['name'] ?? '', ENT_QUOTES); ?></div></div>
        <div><label>Jenis Layanan</label><div class="service-edit-type"><?= strtoupper(htmlspecialchars($serviceType, ENT_QUOTES)); ?></div></div>
        <div><label>Username *</label><input class="form-control" name="service_username" required value="<?= htmlspecialchars($formUsername, ENT_QUOTES); ?>" placeholder="Username MikroTik"></div>
        <div><label>Password Baru</label><input class="form-control" type="password" name="service_password" placeholder="Kosongkan jika tidak diubah"><small style="display:block;margin-top:6px;color:#888">Password lama tetap digunakan jika kolom ini kosong.</small></div>
        <div><label>Profile *</label><select class="form-control" name="service_profile" required><option value="">Pilih profile</option><?php foreach ($profileOptions as $profileRow): ?><option value="<?= htmlspecialchars($profileRow['name'], ENT_QUOTES); ?>"<?= (string) $formProfile === (string) $profileRow['name'] ? ' selected' : ''; ?>><?= htmlspecialchars($profileRow['name'], ENT_QUOTES); ?></option><?php endforeach; ?></select><small style="display:block;margin-top:6px;color:#888">Hanya profile dengan Expired Mode = None yang tersedia untuk Billing.</small></div>
        <?php if ($serviceType === 'hotspot'): ?><div><label>Server Hotspot</label><select class="form-control" name="service_server"><option value="all"<?= $formServer === 'all' ? ' selected' : ''; ?>>all</option><?php foreach ($hotspotServers as $serverRow): if (isset($serverRow['name'])): ?><option value="<?= htmlspecialchars($serverRow['name'], ENT_QUOTES); ?>"<?= (string) $formServer === (string) $serverRow['name'] ? ' selected' : ''; ?>><?= htmlspecialchars($serverRow['name'], ENT_QUOTES); ?></option><?php endif; endforeach; ?></select></div><?php endif; ?>
      </div>
      <div class="service-edit-actions"><button class="btn bg-primary" type="submit" onclick="loader()"><i class="fa fa-save"></i> Simpan Perubahan</button><a class="btn bg-warning" href="./?customer=list&session=<?= rawurlencode($session); ?>"><i class="fa fa-close"></i> Batal</a></div>
    </form>
    <?php else: ?><a class="btn bg-warning" href="./?customer=list&session=<?= rawurlencode($session); ?>"><i class="fa fa-arrow-left"></i> Kembali</a><?php endif; ?>
  </div>
</div></div></div>
