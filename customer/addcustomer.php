<?php
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
  exit;
}

include_once('./include/database.php');

$isEdit = isset($customer) && ($customer === 'edit' || ($customer === 'list' && $customerid !== ''));
$customerError = '';
$editCustomer = array();
$linkedUser = array();
$hotspotProfiles = array();
$hotspotServers = array();
$pppoeProfiles = array();

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

function customerFormValue($field, $default = '') {
  return isset($_POST[$field]) ? (string) $_POST[$field] : (string) $default;
}

function customerSelected($field, $value, $default = '') {
  return customerFormValue($field, $default) === (string) $value ? ' selected' : '';
}

if ($isEdit) {
  foreach (mikhmonGetCustomers($session) as $customerRow) {
    if (isset($customerRow['id']) && (string) $customerRow['id'] === (string) $customerid) {
      $editCustomer = $customerRow;
      break;
    }
  }
  if (!$editCustomer) {
    $customerError = 'Data pelanggan tidak ditemukan.';
  }
}

if (!empty($routerConnected)) {
  $hotspotProfiles = $API->comm('/ip/hotspot/user/profile/print');
  $hotspotServers = $API->comm('/ip/hotspot/print');
  $pppoeProfiles = $API->comm('/ppp/profile/print');
  $hotspotProfiles = is_array($hotspotProfiles) && customerApiError($hotspotProfiles) === '' ? $hotspotProfiles : array();
  $hotspotServers = is_array($hotspotServers) && customerApiError($hotspotServers) === '' ? $hotspotServers : array();
  $pppoeProfiles = is_array($pppoeProfiles) && customerApiError($pppoeProfiles) === '' ? $pppoeProfiles : array();

  if ($isEdit && $editCustomer && !empty($editCustomer['username'])) {
    $editCommand = isset($editCustomer['service']) && $editCustomer['service'] === 'pppoe' ? '/ppp/secret' : '/ip/hotspot/user';
    $linkedRows = $API->comm($editCommand . '/print', array('?name' => $editCustomer['username']));
    if (is_array($linkedRows) && customerApiError($linkedRows) === '' && isset($linkedRows[0])) {
      $linkedUser = $linkedRows[0];
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['customer_action'])) {
  $action = $_POST['customer_action'];
  $updating = $action === 'update' && $isEdit && $editCustomer;
  $creating = $action === 'create' && !$isEdit;

  if ($creating || $updating) {
    $customerName = trim(customerFormValue('customer_name'));
    $customerPhone = trim(customerFormValue('customer_phone'));
    $customerAddress = trim(customerFormValue('customer_address'));
    $service = $updating && isset($editCustomer['service']) ? $editCustomer['service'] : (customerFormValue('customer_service') === 'pppoe' ? 'pppoe' : 'hotspot');
    $username = trim(customerFormValue('username'));
    $password = customerFormValue('password');
    $profile = $service === 'pppoe' ? customerFormValue('pppoe_profile') : customerFormValue('hotspot_profile');
    $command = $service === 'pppoe' ? '/ppp/secret' : '/ip/hotspot/user';
    $oldUsername = $updating && isset($editCustomer['username']) ? $editCustomer['username'] : '';

    if (empty($routerConnected)) {
      $customerError = 'Router MikroTik tidak terhubung.';
    } elseif ($customerName === '' || $username === '' || $profile === '' || ($creating && $password === '')) {
      $customerError = $creating
        ? 'Nama pelanggan, username, password, dan profile wajib diisi.'
        : 'Nama pelanggan, username, dan profile wajib diisi.';
    } else {
      $existing = $API->comm($command . '/print', array('?name' => $username));
      $existingError = customerApiError($existing);
      if ($existingError !== '') {
        $customerError = 'Gagal memeriksa username di MikroTik: ' . $existingError;
      } elseif ($creating && is_array($existing) && count($existing) > 0) {
        $customerError = 'Username sudah ada di MikroTik.';
      } elseif ($updating && $username !== $oldUsername && is_array($existing) && count($existing) > 0) {
        $customerError = 'Username baru sudah digunakan di MikroTik.';
      } else {
        $targetRows = $updating ? $API->comm($command . '/print', array('?name' => $oldUsername)) : array();
        $targetError = customerApiError($targetRows);
        if ($updating && $targetError !== '') {
          $customerError = 'Gagal membaca user MikroTik: ' . $targetError;
        } elseif ($updating && (!isset($targetRows[0]) || empty($targetRows[0]['.id']))) {
          $customerError = 'User MikroTik yang terhubung tidak ditemukan. Username lama: ' . $oldUsername;
        } else {
          if ($service === 'pppoe') {
            $args = array(
              'name' => $username,
              'service' => 'pppoe',
              'profile' => $profile,
              'comment' => $customerName,
            );
          } else {
            $commentPrefix = $creating && $username === $password ? 'vc-' : 'up-';
            if ($updating && isset($targetRows[0]['comment']) && substr($targetRows[0]['comment'], 0, 3) === 'vc-') {
              $commentPrefix = 'vc-';
            }
            $args = array(
              'server' => customerFormValue('hotspot_server', 'all'),
              'name' => $username,
              'profile' => $profile,
              'comment' => $commentPrefix . $customerName,
            );
          }
          if ($password !== '') {
            $args['password'] = $password;
          }
          if ($creating) {
            $args['disabled'] = 'no';
          } else {
            $args['.id'] = $targetRows[0]['.id'];
          }

          $response = $API->comm($command . ($creating ? '/add' : '/set'), $args);
          $responseError = customerApiError($response);
          if ($responseError !== '') {
            $customerError = 'MikroTik menolak penyimpanan user: ' . $responseError;
          } else {
            $customerId = $updating ? $editCustomer['id'] : '';
            $saved = mikhmonSaveCustomer($session, $customerId, $customerName, $customerPhone, $customerAddress, $service, $username, $profile);
            if ($saved === false) {
              if ($creating) {
                $created = $API->comm($command . '/print', array('?name' => $username));
                if (isset($created[0]['.id'])) {
                  $API->comm($command . '/remove', array('.id' => $created[0]['.id']));
                }
                $customerError = 'User dibatalkan karena data pelanggan gagal disimpan.';
              } else {
                $customerError = 'User MikroTik sudah diperbarui, tetapi data pelanggan lokal gagal disimpan.';
              }
            } else {
              $result = $creating ? 'created=1' : 'updated=1';
              echo "<script>window.location='./?customer=list&session=" . rawurlencode($session) . "&" . $result . "'</script>";
              exit;
            }
          }
        }
      }
    }
  }
}

$defaultName = $isEdit && isset($editCustomer['name']) ? $editCustomer['name'] : '';
$defaultPhone = $isEdit && isset($editCustomer['phone']) ? $editCustomer['phone'] : '';
$defaultAddress = $isEdit && isset($editCustomer['address']) ? $editCustomer['address'] : '';
$defaultService = $isEdit && isset($editCustomer['service']) ? $editCustomer['service'] : 'hotspot';
$defaultUsername = $isEdit && isset($editCustomer['username']) ? $editCustomer['username'] : '';
$defaultProfile = $isEdit && isset($editCustomer['profile']) ? $editCustomer['profile'] : '';
$defaultUserName = $isEdit && isset($linkedUser['name']) ? $linkedUser['name'] : $defaultUsername;
$defaultUserProfile = $isEdit && isset($linkedUser['profile']) ? $linkedUser['profile'] : $defaultProfile;
$defaultServer = $isEdit && isset($linkedUser['server']) ? $linkedUser['server'] : 'all';
?>
<div class="row"><div class="col-12"><div class="card box-bordered">
  <div class="card-header"><h3><i class="fa <?= $isEdit ? 'fa-edit' : 'fa-user-plus'; ?>"></i> <?= $isEdit ? 'Edit Pelanggan' : 'Tambah Pelanggan'; ?></h3></div>
  <div class="card-body">
    <?php if ($customerError !== ''): ?><div class="box bg-danger"><?= htmlspecialchars($customerError, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($isEdit && $editCustomer && empty($linkedUser) && !empty($routerConnected)): ?><div class="box bg-warning">User MikroTik yang terhubung belum ditemukan. Simpan hanya dapat dilakukan setelah user tersebut tersedia.</div><?php endif; ?>
    <?php if (!$isEdit || $editCustomer): ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="customer_action" value="<?= $isEdit ? 'update' : 'create'; ?>">
      <table class="table">
        <tr><td>Nama Pelanggan</td><td><input class="form-control" name="customer_name" maxlength="100" required value="<?= htmlspecialchars(customerFormValue('customer_name', $defaultName), ENT_QUOTES); ?>"></td></tr>
        <tr><td>Nomor HP</td><td><input class="form-control" name="customer_phone" maxlength="30" value="<?= htmlspecialchars(customerFormValue('customer_phone', $defaultPhone), ENT_QUOTES); ?>"></td></tr>
        <tr><td>Alamat</td><td><textarea class="form-control" name="customer_address" maxlength="255"><?= htmlspecialchars(customerFormValue('customer_address', $defaultAddress), ENT_QUOTES); ?></textarea></td></tr>
        <tr><td>Jenis Layanan</td><td>
          <?php if ($isEdit): ?><input type="hidden" id="customerService" name="customer_service" value="<?= htmlspecialchars($defaultService, ENT_QUOTES); ?>"><input class="form-control" value="<?= strtoupper(htmlspecialchars($defaultService, ENT_QUOTES)); ?>" disabled>
          <?php else: ?><select class="form-control" id="customerService" name="customer_service" onchange="toggleCustomerService()"><option value="hotspot"<?= customerSelected('customer_service', 'hotspot', 'hotspot'); ?>>Hotspot</option><option value="pppoe"<?= customerSelected('customer_service', 'pppoe'); ?>>PPPoE</option></select><?php endif; ?>
        </td></tr>
        <tr><td>Username</td><td><input class="form-control" name="username" required value="<?= htmlspecialchars(customerFormValue('username', $defaultUserName), ENT_QUOTES); ?>"></td></tr>
        <tr><td><?= $isEdit ? 'Password Baru' : 'Password'; ?></td><td><input class="form-control" type="password" name="password"<?= $isEdit ? ' placeholder="Kosongkan jika tidak diubah"' : ' required'; ?>></td></tr>
        <tr class="hotspot-field"><td>Server Hotspot</td><td><select class="form-control" name="hotspot_server"><option value="all"<?= customerSelected('hotspot_server', 'all', $defaultServer); ?>>all</option><?php foreach ((array) $hotspotServers as $serverRow): ?><?php if (!isset($serverRow['name'])) continue; ?><option value="<?= htmlspecialchars($serverRow['name'], ENT_QUOTES); ?>"<?= customerSelected('hotspot_server', $serverRow['name'], $defaultServer); ?>><?= htmlspecialchars($serverRow['name'], ENT_QUOTES); ?></option><?php endforeach; ?></select></td></tr>
        <tr class="hotspot-field"><td>Profile Hotspot</td><td><select class="form-control" name="hotspot_profile"><option value="">Pilih profile</option><?php foreach ((array) $hotspotProfiles as $profileRow): ?><?php if (!isset($profileRow['name'])) continue; ?><option value="<?= htmlspecialchars($profileRow['name'], ENT_QUOTES); ?>"<?= customerSelected('hotspot_profile', $profileRow['name'], $defaultUserProfile); ?>><?= htmlspecialchars($profileRow['name'], ENT_QUOTES); ?></option><?php endforeach; ?></select></td></tr>
        <tr class="pppoe-field"><td>Profile PPPoE</td><td><select class="form-control" name="pppoe_profile"><option value="">Pilih profile</option><?php foreach ((array) $pppoeProfiles as $profileRow): ?><?php if (!isset($profileRow['name'])) continue; ?><option value="<?= htmlspecialchars($profileRow['name'], ENT_QUOTES); ?>"<?= customerSelected('pppoe_profile', $profileRow['name'], $defaultUserProfile); ?>><?= htmlspecialchars($profileRow['name'], ENT_QUOTES); ?></option><?php endforeach; ?></select></td></tr>
      </table>
      <a class="btn bg-warning" href="./?customer=list&session=<?= rawurlencode($session); ?>"><i class="fa fa-close"></i> Batal</a>
      <button class="btn bg-primary" type="submit" onclick="loader()"><i class="fa fa-save"></i> <?= $isEdit ? 'Simpan Perubahan' : 'Simpan & Buat User'; ?></button>
    </form>
    <?php endif; ?>
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
