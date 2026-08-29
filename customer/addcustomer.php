<?php
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
}

include_once('./include/database.php');

$customerError = '';
$hotspotProfiles = array();
$hotspotServers = array();
$pppoeProfiles = array();
$ipPools = array();

function customerApiError($response) {
  if (!is_array($response)) {
    return '';
  }
  foreach (array('!trap', '!fatal') as $errorType) {
    if (isset($response[$errorType][0]['message'])) {
      return $response[$errorType][0]['message'];
    }
  }
  return '';
}

function customerSelected($field, $value, $default = '') {
  $selected = isset($_POST[$field]) ? (string) $_POST[$field] : (string) $default;
  return $selected === (string) $value ? ' selected' : '';
}

if (!empty($routerConnected)) {
  $hotspotProfiles = $API->comm('/ip/hotspot/user/profile/print');
  $hotspotServers = $API->comm('/ip/hotspot/print');
  $pppoeProfiles = $API->comm('/ppp/profile/print');
  $ipPools = $API->comm('/ip/pool/print');
  $hotspotProfiles = is_array($hotspotProfiles) && customerApiError($hotspotProfiles) === '' ? $hotspotProfiles : array();
  $hotspotServers = is_array($hotspotServers) && customerApiError($hotspotServers) === '' ? $hotspotServers : array();
  $pppoeProfiles = is_array($pppoeProfiles) && customerApiError($pppoeProfiles) === '' ? $pppoeProfiles : array();
  $ipPools = is_array($ipPools) && customerApiError($ipPools) === '' ? $ipPools : array();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['customer_action']) && $_POST['customer_action'] === 'create') {
  $customerName = trim(isset($_POST['customer_name']) ? $_POST['customer_name'] : '');
  $customerPhone = trim(isset($_POST['customer_phone']) ? $_POST['customer_phone'] : '');
  $customerAddress = trim(isset($_POST['customer_address']) ? $_POST['customer_address'] : '');
  $service = isset($_POST['customer_service']) && $_POST['customer_service'] === 'pppoe' ? 'pppoe' : 'hotspot';
  $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
  $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
  $profile = $service === 'pppoe' ? (isset($_POST['pppoe_profile']) ? $_POST['pppoe_profile'] : '') : (isset($_POST['hotspot_profile']) ? $_POST['hotspot_profile'] : '');

  if (empty($routerConnected)) {
    $customerError = 'Router MikroTik tidak terhubung.';
  } elseif ($customerName === '' || $username === '' || $password === '' || $profile === '') {
    $customerError = 'Nama pelanggan, username, password, dan profile wajib diisi.';
  } else {
    $command = $service === 'pppoe' ? '/ppp/secret' : '/ip/hotspot/user';
    $existing = $API->comm($command . '/print', array('?name' => $username));
    $existingError = customerApiError($existing);
    if ($existingError !== '') {
      $customerError = 'Gagal memeriksa username di MikroTik: ' . $existingError;
    } elseif (is_array($existing) && count($existing) > 0) {
      $customerError = 'Username sudah ada di MikroTik.';
    } else {
      if ($service === 'pppoe') {
        $args = array(
          'name' => $username,
          'password' => $password,
          'service' => 'pppoe',
          'profile' => $profile,
          'local-address' => isset($_POST['local_address']) ? trim($_POST['local_address']) : '',
          'remote-address' => isset($_POST['remote_address']) ? trim($_POST['remote_address']) : '',
          'caller-id' => isset($_POST['caller_id']) ? trim($_POST['caller_id']) : '',
          'comment' => $customerName,
          'disabled' => 'no',
        );
      } else {
        $dataLimit = isset($_POST['data_limit']) && $_POST['data_limit'] !== '' ? (int) $_POST['data_limit'] : 0;
        $dataUnit = isset($_POST['data_unit']) ? (int) $_POST['data_unit'] : 1048576;
        $args = array(
          'server' => isset($_POST['hotspot_server']) ? $_POST['hotspot_server'] : 'all',
          'name' => $username,
          'password' => $password,
          'profile' => $profile,
          'limit-uptime' => isset($_POST['time_limit']) && $_POST['time_limit'] !== '' ? $_POST['time_limit'] : '0',
          'limit-bytes-total' => $dataLimit > 0 ? $dataLimit * $dataUnit : '0',
          'comment' => ($username === $password ? 'vc-' : 'up-') . $customerName,
          'disabled' => 'no',
        );
      }
      foreach ($args as $key => $value) {
        if ($value === '') {
          unset($args[$key]);
        }
      }
      $response = $API->comm($command . '/add', $args);
      $responseError = customerApiError($response);
      if ($responseError !== '') {
        $customerError = 'MikroTik menolak pembuatan user: ' . $responseError;
      } else {
        $saved = mikhmonSaveCustomer($session, '', $customerName, $customerPhone, $customerAddress, $service, $username, $profile);
        if ($saved === false) {
          $created = $API->comm($command . '/print', array('?name' => $username));
          if (isset($created[0]['.id'])) {
            $API->comm($command . '/remove', array('.id' => $created[0]['.id']));
          }
          $customerError = 'User dibatalkan karena data pelanggan gagal disimpan.';
        } else {
          echo "<script>window.location='./?customer=list&session=" . rawurlencode($session) . "&created=1'</script>";
          exit;
        }
      }
    }
  }
}
?>
<div class="row"><div class="col-12"><div class="card box-bordered">
  <div class="card-header"><h3><i class="fa fa-user-plus"></i> Tambah Pelanggan</h3></div>
  <div class="card-body">
    <?php if ($customerError !== ''): ?><div class="box bg-danger"><?= htmlspecialchars($customerError, ENT_QUOTES); ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="customer_action" value="create">
      <table class="table">
        <tr><td>Nama Pelanggan</td><td><input class="form-control" name="customer_name" maxlength="100" required value="<?= htmlspecialchars(isset($_POST['customer_name']) ? $_POST['customer_name'] : '', ENT_QUOTES); ?>"></td></tr>
        <tr><td>Nomor HP</td><td><input class="form-control" name="customer_phone" maxlength="30" value="<?= htmlspecialchars(isset($_POST['customer_phone']) ? $_POST['customer_phone'] : '', ENT_QUOTES); ?>"></td></tr>
        <tr><td>Alamat</td><td><textarea class="form-control" name="customer_address" maxlength="255"><?= htmlspecialchars(isset($_POST['customer_address']) ? $_POST['customer_address'] : '', ENT_QUOTES); ?></textarea></td></tr>
        <tr><td>Jenis Layanan</td><td><select class="form-control" id="customerService" name="customer_service" onchange="toggleCustomerService()"><option value="hotspot"<?= customerSelected('customer_service', 'hotspot', 'hotspot'); ?>>Hotspot</option><option value="pppoe"<?= customerSelected('customer_service', 'pppoe'); ?>>PPPoE</option></select></td></tr>
        <tr><td>Username</td><td><input class="form-control" name="username" required value="<?= htmlspecialchars(isset($_POST['username']) ? $_POST['username'] : '', ENT_QUOTES); ?>"></td></tr>
        <tr><td>Password</td><td><input class="form-control" type="password" name="password" required></td></tr>
        <tr class="hotspot-field"><td>Server Hotspot</td><td><select class="form-control" name="hotspot_server"><option value="all"<?= customerSelected('hotspot_server', 'all', 'all'); ?>>all</option><?php foreach ((array) $hotspotServers as $serverRow): ?><?php if (!isset($serverRow['name'])) continue; ?><option value="<?= htmlspecialchars($serverRow['name'], ENT_QUOTES); ?>"<?= customerSelected('hotspot_server', $serverRow['name']); ?>><?= htmlspecialchars($serverRow['name'], ENT_QUOTES); ?></option><?php endforeach; ?></select></td></tr>
        <tr class="hotspot-field"><td>Profile Hotspot</td><td><select class="form-control" name="hotspot_profile"><option value="">Pilih profile</option><?php foreach ((array) $hotspotProfiles as $profileRow): ?><?php if (!isset($profileRow['name'])) continue; ?><option value="<?= htmlspecialchars($profileRow['name'], ENT_QUOTES); ?>"<?= customerSelected('hotspot_profile', $profileRow['name']); ?>><?= htmlspecialchars($profileRow['name'], ENT_QUOTES); ?></option><?php endforeach; ?></select></td></tr>
        <tr class="hotspot-field"><td>Time Limit</td><td><input class="form-control" name="time_limit" placeholder="Contoh: 1d atau 12h" value="<?= htmlspecialchars(isset($_POST['time_limit']) ? $_POST['time_limit'] : '', ENT_QUOTES); ?>"></td></tr>
        <tr class="hotspot-field"><td>Data Limit</td><td><div class="input-group"><div class="input-group-10 col-box-9"><input class="group-item group-item-l" type="number" min="0" name="data_limit" value="<?= htmlspecialchars(isset($_POST['data_limit']) ? $_POST['data_limit'] : '', ENT_QUOTES); ?>"></div><div class="input-group-2 col-box-3"><select class="group-item group-item-r" name="data_unit"><option value="1048576"<?= customerSelected('data_unit', '1048576', '1048576'); ?>>MB</option><option value="1073741824"<?= customerSelected('data_unit', '1073741824'); ?>>GB</option></select></div></div></td></tr>
        <tr class="pppoe-field"><td>Profile PPPoE</td><td><select class="form-control" name="pppoe_profile"><option value="">Pilih profile</option><?php foreach ((array) $pppoeProfiles as $profileRow): ?><?php if (!isset($profileRow['name'])) continue; ?><option value="<?= htmlspecialchars($profileRow['name'], ENT_QUOTES); ?>"<?= customerSelected('pppoe_profile', $profileRow['name']); ?>><?= htmlspecialchars($profileRow['name'], ENT_QUOTES); ?></option><?php endforeach; ?></select></td></tr>
        <tr class="pppoe-field"><td>Local Address</td><td><input class="form-control" name="local_address" list="customerIpPools" value="<?= htmlspecialchars(isset($_POST['local_address']) ? $_POST['local_address'] : '', ENT_QUOTES); ?>" placeholder="Pilih IP pool atau ketik IP address"></td></tr>
        <tr class="pppoe-field"><td>Remote Address</td><td><input class="form-control" name="remote_address" list="customerIpPools" value="<?= htmlspecialchars(isset($_POST['remote_address']) ? $_POST['remote_address'] : '', ENT_QUOTES); ?>" placeholder="Pilih IP pool atau ketik IP address"></td></tr>
        <tr class="pppoe-field"><td>Caller ID</td><td><input class="form-control" name="caller_id" value="<?= htmlspecialchars(isset($_POST['caller_id']) ? $_POST['caller_id'] : '', ENT_QUOTES); ?>"></td></tr>
      </table>
      <datalist id="customerIpPools"><?php foreach ((array) $ipPools as $poolRow): ?><?php if (!isset($poolRow['name'])) continue; ?><option value="<?= htmlspecialchars($poolRow['name'], ENT_QUOTES); ?>"><?= htmlspecialchars(isset($poolRow['ranges']) ? $poolRow['ranges'] : '', ENT_QUOTES); ?></option><?php endforeach; ?></datalist>
      <a class="btn bg-warning" href="./?customer=list&session=<?= rawurlencode($session); ?>"><i class="fa fa-close"></i> Batal</a>
      <button class="btn bg-primary" type="submit" onclick="loader()"><i class="fa fa-save"></i> Simpan & Buat User</button>
    </form>
  </div>
</div></div></div>
<script>
function toggleCustomerService() {
  var isPppoe = document.getElementById('customerService').value === 'pppoe';
  $('.hotspot-field').toggle(!isPppoe);
  $('.pppoe-field').toggle(isPppoe);
}
toggleCustomerService();
</script>
