<?php
error_reporting(0);
if (!isset($_SESSION['mikhmon'])) { header('Location:../admin.php?id=login'); exit; }
include_once('./include/database.php');
include_once('./lib/billing_profile.php');
include_once('./ppp/profilemeta.php');
$serviceError = '';
$selectedServiceType = ($_POST['service_type'] ?? ($_GET['service'] ?? '')) === 'pppoe' ? 'pppoe' : 'hotspot';
$serviceCustomers = mikhmonVisibleCustomers($session);
$selectedCustomerId = (string) ($_GET['customer-id'] ?? $_POST['customer_id'] ?? '');
$selectedCustomer = $selectedCustomerId !== '' ? mikhmonFindCustomer($session, $selectedCustomerId) : false;
if ($selectedCustomer && !mikhmonCanManageCustomer($selectedCustomer)) $selectedCustomer = false;
$hotspotProfiles = $hotspotServers = $pppoeProfiles = array();
function serviceAddApiError($response) { if (!is_array($response)) return ''; foreach (array('!trap','!fatal') as $type) if (isset($response[$type][0]['message'])) return $response[$type][0]['message']; return ''; }
if (!empty($routerConnected)) {
  $hotspotProfiles = $API->comm('/ip/hotspot/user/profile/print');
  $hotspotServers = $API->comm('/ip/hotspot/print');
  $pppoeProfiles = $API->comm('/ppp/profile/print');
  if (!is_array($hotspotProfiles) || serviceAddApiError($hotspotProfiles) !== '') $hotspotProfiles = array();
  if (!is_array($hotspotServers) || serviceAddApiError($hotspotServers) !== '') $hotspotServers = array();
  if (!is_array($pppoeProfiles) || serviceAddApiError($pppoeProfiles) !== '') $pppoeProfiles = array();
}
$billingHotspotProfiles = array_values(array_filter($hotspotProfiles, function ($profile) { return isset($profile['name']) && mikhmonBillingProfileCanManage('hotspot', $profile); }));
$billingPppoeProfiles = array_values(array_filter($pppoeProfiles, function ($profile) { return isset($profile['name']) && mikhmonBillingProfileCanManage('pppoe', $profile); }));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['service_action'] ?? '') === 'save') {
  $customerId = (string) ($_POST['customer_id'] ?? '');
  $customer = mikhmonFindCustomer($session, $customerId);
  $service = $selectedServiceType;
  $username = trim((string) ($_POST['service_username'] ?? ''));
  $password = (string) ($_POST['service_password'] ?? '');
  $profile = trim((string) ($_POST['service_profile'] ?? ''));
  $server = trim((string) ($_POST['service_server'] ?? 'all')) ?: 'all';
  $selectedCustomerId = $customerId; $selectedCustomer = $customer;
  if (!$customer || !mikhmonCanManageCustomer($customer)) $serviceError = 'Identitas pelanggan tidak valid atau Anda tidak berhak mengelolanya.';
  elseif (empty($routerConnected)) $serviceError = 'Router MikroTik tidak terhubung.';
  elseif ($username === '' || $profile === '') $serviceError = 'Username dan profile wajib diisi.';
  elseif ($password === '') $serviceError = 'Password wajib diisi untuk layanan baru.';
  else {
    $profileRows = $service === 'pppoe' ? $pppoeProfiles : $hotspotProfiles;
    $profileRow = array();
    foreach ((array) $profileRows as $candidate) if (isset($candidate['name']) && (string) $candidate['name'] === $profile) { $profileRow = $candidate; break; }
    if (!$profileRow || !mikhmonBillingProfileCanManage($service, $profileRow)) $serviceError = 'Profile yang dikelola Billing wajib menggunakan Expired Mode = None.';
    if ($serviceError !== '') {
      // Keep the MikroTik state untouched when a non-Billing profile is submitted.
    } else {
    $command = $service === 'pppoe' ? '/ppp/secret' : '/ip/hotspot/user';
    $existing = $API->comm($command . '/print', array('?name' => $username));
    if (serviceAddApiError($existing) !== '') $serviceError = 'Gagal memeriksa username MikroTik: ' . serviceAddApiError($existing);
    elseif (count($existing) > 0) $serviceError = 'Username ' . $username . ' sudah digunakan di MikroTik.';
    else {
      $args = $service === 'pppoe'
        ? array('name' => $username, 'password' => $password, 'service' => 'pppoe', 'profile' => $profile, 'comment' => $customer['name'])
        : array('server' => $server, 'name' => $username, 'password' => $password, 'profile' => $profile, 'comment' => 'up-' . $customer['name']);
      if (mikhmonIsMitra()) $args['comment'] .= ' ' . mikhmonOwnerTag();
      $response = $API->comm($command . '/add', $args);
      if (serviceAddApiError($response) !== '') $serviceError = 'MikroTik menolak layanan: ' . serviceAddApiError($response);
      elseif (mikhmonAddCustomerService($session, $customerId, array('service' => $service, 'username' => $username, 'profile' => $profile, 'server' => $server)) === false) {
        $createdRows = $API->comm($command . '/print', array('?name' => $username));
        if (isset($createdRows[0]['.id'])) $API->comm($command . '/remove', array('.id' => $createdRows[0]['.id']));
        $serviceError = 'Layanan gagal dikaitkan ke identitas pelanggan.';
      } else {
        $schedulerName = 'mikhmon-customer-' . substr(md5((string) $customerId), 0, 12);
        $schedulerRows = $API->comm('/system/scheduler/print', array('?name' => $schedulerName));
        if (serviceAddApiError($schedulerRows) === '' && is_array($schedulerRows)) foreach ($schedulerRows as $schedulerRow) if (isset($schedulerRow['.id'])) $API->comm('/system/scheduler/remove', array('.id' => $schedulerRow['.id']));
        $query = './?customer=list&session=' . rawurlencode($session) . '&service-added=1';
        echo "<script>window.location=" . json_encode($query) . "</script>"; exit;
      }
    }
    }
  }
}
?>
<style>
  .service-add-card{max-width:780px;margin:0 auto}
  .service-add-fields{display:grid;grid-template-columns:1fr;gap:14px}
  .service-add-fields .wide{grid-column:1/-1}
  .service-add-fields label{display:block;margin-bottom:6px;font-size:12px;font-weight:bold;color:#d7dbe0}
  .service-add-actions{display:flex;justify-content:space-between;gap:10px;margin-top:18px}
  .service-add-actions .btn,.service-add-actions a{box-sizing:border-box;margin:0}
  @media(max-width:767px){.service-add-fields{grid-template-columns:1fr}.service-add-fields .wide{grid-column:auto}.service-add-actions{flex-direction:column}.service-add-actions .btn,.service-add-actions a{width:100%;text-align:center}}
</style>
<div class="row"><div class="col-12"><div class="card box-bordered service-add-card">
  <div class="card-header"><h3><i class="fa fa-link"></i> Tambah Layanan</h3></div>
  <div class="card-body">
    <?php if ($serviceError !== ''): ?><div class="box bg-danger"><i class="fa fa-warning"></i> <?= htmlspecialchars($serviceError, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if (empty($routerConnected)): ?><div class="box bg-warning">Router MikroTik tidak terhubung. Layanan belum dapat ditambahkan.</div><?php endif; ?>
    <form method="post" autocomplete="off"><input type="hidden" name="service_action" value="save">
      <div class="service-add-fields">
        <div class="wide"><label>Identitas Pelanggan *</label><select class="form-control" name="customer_id" required><option value="">Pilih identitas pelanggan</option><?php foreach ($serviceCustomers as $customer): ?><option value="<?= htmlspecialchars($customer['id'], ENT_QUOTES); ?>"<?= (string) $customer['id'] === (string) $selectedCustomerId ? ' selected' : ''; ?>><?= htmlspecialchars($customer['name'], ENT_QUOTES); ?><?= !empty($customer['phone']) ? ' - ' . htmlspecialchars($customer['phone'], ENT_QUOTES) : ''; ?></option><?php endforeach; ?></select><?php if (!$serviceCustomers): ?><small style="display:block;margin-top:6px;color:#888">Belum ada identitas. Buat identitas pelanggan terlebih dahulu.</small><?php endif; ?></div>
        <div><label>Jenis Layanan *</label><select class="form-control service-type" name="service_type" required><option value="hotspot"<?= $selectedServiceType === 'hotspot' ? ' selected' : ''; ?>>Hotspot</option><option value="pppoe"<?= $selectedServiceType === 'pppoe' ? ' selected' : ''; ?>>PPPoE</option></select></div>
        <div><label>Username *</label><input class="form-control" name="service_username" required value="<?= htmlspecialchars($_POST['service_username'] ?? '', ENT_QUOTES); ?>" placeholder="Username MikroTik"></div>
        <div><label>Password *</label><input class="form-control" type="password" name="service_password" required placeholder="Password layanan"></div>
        <div><label>Profile *</label><select class="form-control service-profile" name="service_profile" required><option value="">Pilih profile</option><?php foreach ($billingHotspotProfiles as $profileRow): ?><option data-service="hotspot" value="<?= htmlspecialchars($profileRow['name'], ENT_QUOTES); ?>"<?= (string) ($_POST['service_profile'] ?? '') === (string) $profileRow['name'] ? ' selected' : ''; ?>><?= htmlspecialchars($profileRow['name'], ENT_QUOTES); ?></option><?php endforeach; ?><?php foreach ($billingPppoeProfiles as $profileRow): ?><option data-service="pppoe" value="<?= htmlspecialchars($profileRow['name'], ENT_QUOTES); ?>" style="display:none"<?= (string) ($_POST['service_profile'] ?? '') === (string) $profileRow['name'] ? ' selected' : ''; ?>><?= htmlspecialchars($profileRow['name'], ENT_QUOTES); ?></option><?php endforeach; ?></select><small style="display:block;margin-top:6px;color:#888">Hanya profile dengan Expired Mode = None yang tersedia untuk Billing.</small></div>
        <div class="service-server-field"><label>Server Hotspot</label><select class="form-control" name="service_server"><option value="all">all</option><?php foreach ($hotspotServers as $serverRow): if(isset($serverRow['name'])): ?><option value="<?= htmlspecialchars($serverRow['name'], ENT_QUOTES); ?>"><?= htmlspecialchars($serverRow['name'], ENT_QUOTES); ?></option><?php endif; endforeach; ?></select></div>
      </div>
      <div class="service-add-actions"><button class="btn bg-primary" type="submit" onclick="loader()"><i class="fa fa-link"></i> Simpan Layanan</button><a class="btn bg-warning" href="./?customer=list&session=<?= rawurlencode($session); ?>"><i class="fa fa-close"></i> Batal</a></div>
    </form>
  </div>
</div></div></div>
<script>
$(function(){function updateServiceType(){var type=$('.service-type').val(),profile=$('.service-profile'),current=profile.val();profile.find('option[data-service]').hide().prop('disabled',true);profile.find('option[data-service="'+type+'"]').show().prop('disabled',false);if(!profile.find('option[value="'+current+'"]:visible').length)profile.val('');$('.service-server-field').toggle(type==='hotspot');}$('.service-type').on('change',updateServiceType);updateServiceType();});
</script>
