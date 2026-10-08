<?php

error_reporting(0);
include_once(__DIR__ . '/../include/systemlog.php');
include_once(__DIR__ . '/../lib/voucher_summary.php');
if (!isset($_SESSION['mikhmon']) || !mikhmonIsAdmin()) {
  header('Location:../admin.php?id=login');
  exit;
}

$managedMessage = '';
$managedError = '';
$isRouterUserRoute = isset($admin) && $admin === 'users' && !empty($session);
$managedBaseUrl = $isRouterUserRoute
  ? './?admin=users&session=' . rawurlencode($session)
  : './admin.php?id=users';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['managed_action'])) {
  if (!mikhmonValidCsrf($_POST['_csrf'] ?? '')) {
    $managedError = 'Sesi formulir tidak valid. Muat ulang halaman dan coba lagi.';
  } elseif ($_POST['managed_action'] === 'save') {
    $username = trim((string) ($_POST['username'] ?? ''));
    if ($username !== '' && strtolower($username) === strtolower((string) $useradm)) {
      $managedError = 'Username sudah digunakan oleh administrator bawaan.';
    } else {
      $result = mikhmonSaveManagedUser(array(
        'partner_id' => $_POST['partner_id'] ?? '',
        'user_id' => $_POST['user_id'] ?? '',
        'type' => $_POST['type'] ?? '',
        'name' => $_POST['name'] ?? '',
        'session' => $_POST['router_session'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'email' => $_POST['email'] ?? '',
        'address' => $_POST['address'] ?? '',
        'commission' => $_POST['commission'] ?? 0,
        'login_enabled' => isset($_POST['login_enabled']),
        'username' => $username,
        'password' => $_POST['password'] ?? '',
        'active' => isset($_POST['active']),
      ));
      if (empty($result['status'])) {
        $managedError = $result['error'] ?? 'Data pengguna gagal disimpan.';
      } else {
        mikhmonSystemLog('success', 'Pengguna & Peran', 'Menyimpan data pengguna ' . trim((string) ($_POST['name'] ?? '')) . '.', mikhmonSystemLogCurrentUser(array('session' => $_POST['router_session'] ?? '')));
        echo '<script>window.location.replace(' . json_encode($managedBaseUrl . '&saved=1') . ')</script>';
        exit;
      }
    }
  } elseif ($_POST['managed_action'] === 'toggle') {
    $active = (string) ($_POST['set_active'] ?? '') === '1';
    if (mikhmonSetManagedUserActive((string) ($_POST['partner_id'] ?? ''), (string) ($_POST['user_id'] ?? ''), $active)) {
      mikhmonSystemLog('warning', 'Pengguna & Peran', ($active ? 'Mengaktifkan' : 'Menonaktifkan') . ' pengguna ' . trim((string) ($_POST['name'] ?? '')) . '.', mikhmonSystemLogCurrentUser());
      echo '<script>window.location.replace(' . json_encode($managedBaseUrl . ($active ? '&activated=1' : '&deactivated=1')) . ')</script>';
      exit;
    }
    $managedError = 'Status pengguna gagal diubah.';
  }
}

if (isset($_GET['saved'])) $managedMessage = 'Data pengguna berhasil disimpan.';
if (isset($_GET['activated'])) $managedMessage = 'Pengguna berhasil diaktifkan.';
if (isset($_GET['deactivated'])) $managedMessage = 'Pengguna berhasil dinonaktifkan tanpa menghapus riwayatnya.';

$routerSessions = array();
foreach ((array) $data as $routerName => $routerConfig) {
  if ($routerName !== 'mikhmon' && is_array($routerConfig)) $routerSessions[] = (string) $routerName;
}

$voucherSources = array();
foreach ($routerSessions as $routerName) {
  $routerRecord = mikhmonGetRouterRecord(array(), $routerName);
  $snapshot = isset($routerRecord['latest']) ? $routerRecord['latest'] : array();
  $updatedAt = (int) ($snapshot['updated_at'] ?? 0);
  $voucherSources[$routerName] = array(
    'available' => $updatedAt > 0,
    'profiles' => (array) ($snapshot['hotspot_profiles'] ?? array()),
    'users' => (array) ($snapshot['hotspot_users'] ?? array()),
    'label' => $updatedAt > 0 ? 'Backup ' . date('d/m/Y H:i', $updatedAt) : 'Belum ada data router',
  );
}
if ($isRouterUserRoute && !empty($routerConnected) && $API) {
  $liveProfiles = $API->comm('/ip/hotspot/user/profile/print');
  $liveUsers = $API->comm('/ip/hotspot/user/print', array('.proplist' => 'name,profile,comment,disabled,limit-uptime'));
  if (is_array($liveProfiles) && is_array($liveUsers)
    && !isset($liveProfiles['!trap']) && !isset($liveUsers['!trap'])
    && !isset($liveProfiles['!fatal']) && !isset($liveUsers['!fatal'])) {
    $voucherSources[(string) $session] = array(
      'available' => true,
      'profiles' => $liveProfiles,
      'users' => $liveUsers,
      'label' => 'Live ' . date('d/m/Y H:i'),
    );
  }
}

$managedMoney = function ($amount, $routerName) use ($data) {
  $config = isset($data[$routerName]) && is_array($data[$routerName]) ? $data[$routerName] : array();
  $currencyParts = explode('&', (string) ($config[6] ?? '&Rp'), 2);
  $currencyLabel = trim((string) ($currencyParts[1] ?? 'Rp')) ?: 'Rp';
  $isRupiah = in_array(strtolower(rtrim($currencyLabel, '.')), array('rp', 'idr'), true);
  return $currencyLabel . ' ' . number_format((float) $amount, $isRupiah ? 0 : 2, $isRupiah ? ',' : '.', $isRupiah ? '.' : ',');
};

$managedRows = array();
foreach (mikhmonGetUsers('admin') as $account) {
  $managedRows[] = array(
    'id' => (string) $account['id'],
    'partner_id' => '',
    'user_id' => (string) $account['id'],
    'name' => (string) $account['name'],
    'type' => 'admin',
    'session' => 'mikhmon',
    'username' => (string) $account['username'],
    'login' => true,
    'active' => !empty($account['active']),
    'customers' => 0,
    'partner' => false,
    'account' => $account,
  );
}
foreach (array_merge(mikhmonGetUsers('finance'), mikhmonGetUsers('operator')) as $account) {
  $managedRows[] = array(
    'id' => (string) $account['id'], 'partner_id' => '', 'user_id' => (string) $account['id'],
    'name' => (string) $account['name'], 'type' => (string) $account['role'], 'session' => (string) $account['session'],
    'username' => (string) $account['username'], 'login' => true, 'active' => !empty($account['active']),
    'customers' => 0, 'partner' => false, 'account' => $account,
  );
}
foreach (mikhmonGetPartners($isRouterUserRoute ? (string) $session : '') as $partner) {
  $account = $partner['user_id'] !== '' ? mikhmonFindUser($partner['user_id']) : false;
  $voucherSource = $voucherSources[$partner['session']] ?? array('available' => false, 'profiles' => array(), 'users' => array(), 'label' => 'Belum ada data router');
  $voucherSummary = $account ? mikhmonVoucherOwnerSummary($voucherSource['profiles'], $voucherSource['users'], $account['id']) : array('total' => 0, 'unused' => 0);
  $revenue = $account ? mikhmonVoucherOwnerRevenue($partner['session'], $voucherSource['profiles'], $account['id']) : array('voucher' => 0, 'customer' => 0);
  $managedRows[] = array(
    'id' => (string) $partner['id'],
    'partner_id' => (string) $partner['id'],
    'user_id' => (string) $partner['user_id'],
    'name' => (string) $partner['name'],
    'type' => (string) $partner['category'],
    'session' => (string) $partner['session'],
    'username' => $account ? (string) $account['username'] : '',
    'login' => (bool) $account,
    'active' => !empty($partner['active']) && (!$account || !empty($account['active'])),
    'customers' => $account ? mikhmonAssignedCustomerCount($account['id']) : 0,
    'vouchers' => $voucherSummary,
    'voucher_available' => !empty($voucherSource['available']),
    'voucher_source' => (string) $voucherSource['label'],
    'voucher_revenue' => $managedMoney($revenue['voucher'], $partner['session']),
    'customer_revenue' => $managedMoney($revenue['customer'], $partner['session']),
    'commission' => $managedMoney($partner['commission'], $partner['session']),
    'partner' => $partner,
    'account' => $account,
  );
}
usort($managedRows, function ($left, $right) {
  if ($left['type'] === 'admin' && $right['type'] !== 'admin') return -1;
  if ($right['type'] === 'admin' && $left['type'] !== 'admin') return 1;
  return strcasecmp($left['name'], $right['name']);
});

$editRow = false;
if (!empty($_GET['managed-id'])) {
  foreach ($managedRows as $row) {
    if ((string) $row['id'] === (string) $_GET['managed-id']) { $editRow = $row; break; }
  }
  if (!$editRow) $managedError = 'Data pengguna tidak ditemukan.';
}
$formPartner = $editRow && $editRow['partner'] ? $editRow['partner'] : mikhmonNormalizePartner(array('session' => $isRouterUserRoute ? $session : '', 'active' => true));
$formAccount = $editRow && $editRow['account'] ? $editRow['account'] : array();
$formType = $editRow ? $editRow['type'] : 'reseller';
$formName = $editRow ? $editRow['name'] : '';
$formPartnerId = $editRow ? $editRow['partner_id'] : '';
$formUserId = $editRow ? $editRow['user_id'] : '';
$formActive = !$editRow || $editRow['active'];
$formLoginEnabled = $formType === 'admin' || !empty($formAccount);
$submittedSave = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['managed_action'] ?? '') === 'save' && $managedError !== '';
if ($submittedSave) {
  $submittedType = strtolower((string) ($_POST['type'] ?? 'reseller'));
  $formType = in_array($submittedType, array('admin', 'reseller', 'sales', 'biller', 'finance', 'operator'), true) ? $submittedType : 'reseller';
  $formName = trim((string) ($_POST['name'] ?? ''));
  $formPartnerId = trim((string) ($_POST['partner_id'] ?? ''));
  $formUserId = trim((string) ($_POST['user_id'] ?? ''));
  $formActive = isset($_POST['active']);
  $formLoginEnabled = $formType === 'admin' || isset($_POST['login_enabled']);
  $formPartner = mikhmonNormalizePartner(array(
    'id' => $formPartnerId,
    'session' => $_POST['router_session'] ?? '',
    'name' => $formName,
    'category' => $formType,
    'phone' => $_POST['phone'] ?? '',
    'email' => $_POST['email'] ?? '',
    'address' => $_POST['address'] ?? '',
    'commission' => $_POST['commission'] ?? 0,
    'active' => $formActive,
  ));
  $formAccount = $formLoginEnabled ? array('username' => trim((string) ($_POST['username'] ?? ''))) : array();
}
$modalOpen = $editRow || $managedError !== '';
$typeLabels = array('admin' => 'Administrator', 'finance' => 'Keuangan', 'operator' => 'Operator', 'reseller' => 'Reseller', 'sales' => 'Sales', 'biller' => 'Biller');
?>
<style>
  .managed-header{display:flex;align-items:center;justify-content:space-between;gap:12px}.managed-header h3{margin:0}
  .managed-toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}.managed-toolbar .form-control{width:auto;min-width:180px}
  .managed-table td,.managed-table th{vertical-align:middle}.managed-status{font-weight:700}
  .managed-actions{display:flex;align-items:stretch;gap:6px;justify-content:center}.managed-actions>a,.managed-actions>form{flex:1 1 0;min-width:110px}.managed-actions form{display:flex;margin:0}.managed-actions .btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;width:100%;height:100%;min-height:38px;margin:0;white-space:nowrap}
  .managed-modal{position:fixed;inset:0;z-index:1200;display:none;align-items:flex-start;justify-content:center;padding:24px 12px;background:rgba(0,0,0,.58);overflow-y:auto;box-sizing:border-box}.managed-modal.is-open{display:flex}
  .managed-dialog{position:relative;width:min(780px,100%);max-height:calc(100dvh - 48px);overflow-y:auto}.managed-dialog .card{margin:0}.managed-dialog-header{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:52px}.managed-dialog-header h3{margin:0}.managed-close{flex:0 0 44px;width:44px;height:44px;margin:0;padding:0;font-size:24px;line-height:44px}
  .managed-form{display:grid;grid-template-columns:1fr 1fr;gap:12px}.managed-form .wide{grid-column:1/-1}.managed-form label{display:block;margin-bottom:5px;font-weight:600}.managed-form textarea{min-height:76px;resize:vertical}
  .managed-section{grid-column:1/-1;margin:6px 0 0;padding-top:12px;border-top:1px solid rgba(127,127,127,.25)}.managed-section h4{margin:0 0 4px}.managed-section p{margin:0;color:inherit;opacity:.75}
  .managed-form-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px;padding-top:12px;border-top:1px solid rgba(127,127,127,.25)}.managed-page :focus-visible{outline:2px solid #f5a623;outline-offset:2px}body.managed-modal-open{overflow:hidden}
  @media(max-width:750px){.managed-modal{padding:12px 8px}.managed-dialog{max-height:calc(100dvh - 24px)}.managed-header{align-items:stretch;flex-direction:column}.managed-header .btn{width:100%;min-height:44px}.managed-toolbar{flex-direction:column}.managed-toolbar .form-control{width:100%;min-height:44px}.managed-actions{flex-direction:row;flex-wrap:nowrap}.managed-actions .btn{min-height:44px}}
  @media(max-width:620px){.managed-form{grid-template-columns:1fr}.managed-form .wide,.managed-section{grid-column:auto}.managed-form-actions{align-items:stretch;flex-direction:column}.managed-form-actions .btn{width:100%;min-height:44px}}
</style>
<div class="managed-page">
  <?php if ($managedMessage !== ''): ?><div class="box bg-success" role="status"><i class="fa fa-check"></i> <?= htmlspecialchars($managedMessage, ENT_QUOTES); ?></div><?php endif; ?>
  <?php if ($managedError !== ''): ?><div class="box bg-danger" role="alert"><i class="fa fa-warning"></i> <?= htmlspecialchars($managedError, ENT_QUOTES); ?></div><?php endif; ?>
  <div class="card">
    <div class="card-header managed-header"><h3><i class="fa fa-users"></i> Pengguna &amp; Peran</h3><button id="openManagedForm" class="btn bg-primary" type="button"><i class="fa fa-user-plus"></i> Tambah Pengguna</button></div>
    <div class="card-body">
      <p><small>Satu data mengatur profil, router, akses login, dan status pengguna. Pendapatan ditampilkan untuk bulan berjalan.</small></p>
      <div class="managed-toolbar data-toolbar" role="search" aria-label="Filter pengguna"><div class="data-toolbar__filters"><input id="managedSearch" class="form-control" type="search" placeholder="Cari nama atau username" aria-label="Cari nama atau username"><select id="managedTypeFilter" class="form-control" aria-label="Filter jenis pengguna"><option value="all">Semua jenis</option><?php foreach ($typeLabels as $typeValue => $typeLabel): ?><option value="<?= $typeValue; ?>"><?= $typeLabel; ?></option><?php endforeach; ?></select><select id="managedRouterFilter" class="form-control" aria-label="Filter router"><option value="all">Semua router</option><?php foreach ($routerSessions as $routerName): ?><option value="<?= htmlspecialchars($routerName, ENT_QUOTES); ?>"><?= htmlspecialchars($routerName, ENT_QUOTES); ?></option><?php endforeach; ?></select></div></div>
      <div class="overflow box-bordered"><table class="table table-bordered table-hover text-nowrap managed-table"><thead><tr><th>Nama</th><th>Username</th><th>Jenis</th><th>Router</th><th>Akses Login</th><th>Pelanggan</th><th>Total Voucher</th><th>Voucher Belum Terpakai</th><th>Pendapatan Voucher<br><small>Bulan ini</small></th><th>Pendapatan Pelanggan<br><small>Bulan ini</small></th><th>Komisi</th><th>Status</th><th class="text-center">Aksi</th></tr></thead><tbody>
        <?php foreach ($managedRows as $row): $staffOnly = in_array($row['type'], array('admin','finance','operator'), true); ?><tr class="managed-row" data-type="<?= htmlspecialchars($row['type'], ENT_QUOTES); ?>" data-router="<?= htmlspecialchars($row['session'], ENT_QUOTES); ?>"><td><?= htmlspecialchars($row['name'], ENT_QUOTES); ?></td><td><?= $row['username'] !== '' ? htmlspecialchars($row['username'], ENT_QUOTES) : '-'; ?></td><td><?= htmlspecialchars($typeLabels[$row['type']] ?? ucfirst($row['type']), ENT_QUOTES); ?></td><td><?= in_array($row['type'], array('admin','finance'), true) ? 'Semua Router' : htmlspecialchars($row['session'], ENT_QUOTES); ?></td><td><?= $row['login'] ? '<span class="text-success">Tersedia</span>' : '<span>Tidak ada</span>'; ?></td><td class="text-right"><?= $staffOnly ? '-' : (int) $row['customers']; ?></td><td class="text-right"><?php if ($staffOnly): ?>-<?php elseif ($row['voucher_available']): ?><?= (int) $row['vouchers']['total']; ?><?php else: ?>-<br><small><?= htmlspecialchars($row['voucher_source'], ENT_QUOTES); ?></small><?php endif; ?></td><td class="text-right"><?= $staffOnly || !$row['voucher_available'] ? '-' : (int) $row['vouchers']['unused']; ?></td><td class="text-right"><?= $staffOnly ? '-' : htmlspecialchars($row['voucher_revenue'], ENT_QUOTES); ?></td><td class="text-right"><?= $staffOnly ? '-' : htmlspecialchars($row['customer_revenue'], ENT_QUOTES); ?></td><td class="text-right"><?= $staffOnly ? '-' : htmlspecialchars($row['commission'], ENT_QUOTES); ?></td><td class="managed-status <?= $row['active'] ? 'text-success' : 'text-danger'; ?>"><?= $row['active'] ? 'Aktif' : 'Nonaktif'; ?></td><td><div class="managed-actions"><a class="btn bg-primary" href="<?= htmlspecialchars($managedBaseUrl, ENT_QUOTES); ?>&amp;managed-id=<?= rawurlencode($row['id']); ?>"><i class="fa fa-edit"></i> Kelola</a><form method="post" onsubmit="return confirm('<?= $row['active'] ? 'Nonaktifkan pengguna ini tanpa menghapus riwayatnya?' : 'Aktifkan kembali pengguna ini?'; ?>');"><?= mikhmonCsrfField(); ?><input type="hidden" name="managed_action" value="toggle"><input type="hidden" name="partner_id" value="<?= htmlspecialchars($row['partner_id'], ENT_QUOTES); ?>"><input type="hidden" name="user_id" value="<?= htmlspecialchars($row['user_id'], ENT_QUOTES); ?>"><input type="hidden" name="name" value="<?= htmlspecialchars($row['name'], ENT_QUOTES); ?>"><input type="hidden" name="set_active" value="<?= $row['active'] ? '0' : '1'; ?>"><button class="btn <?= $row['active'] ? 'bg-warning' : 'bg-success'; ?>" type="submit"><i class="fa <?= $row['active'] ? 'fa-ban' : 'fa-check'; ?>"></i> <?= $row['active'] ? 'Nonaktifkan' : 'Aktifkan'; ?></button></form></div></td></tr><?php endforeach; ?>
        <?php if (!$managedRows): ?><tr class="managed-empty"><td colspan="13" class="text-center">Belum ada pengguna. Pilih Tambah Pengguna untuk membuat data pertama.</td></tr><?php endif; ?>
        <tr id="managedNoResults" style="display:none"><td colspan="13" class="text-center">Tidak ada pengguna yang cocok dengan filter.</td></tr>
      </tbody></table></div>
    </div>
  </div>

  <div id="managedModal" class="managed-modal<?= $modalOpen ? ' is-open' : ''; ?>" role="dialog" aria-modal="true" aria-labelledby="managedFormTitle" aria-hidden="<?= $modalOpen ? 'false' : 'true'; ?>">
    <div class="managed-dialog"><div class="card">
      <div class="card-header managed-dialog-header"><h3 id="managedFormTitle"><i class="fa <?= $editRow ? 'fa-edit' : 'fa-user-plus'; ?>"></i> <?= $editRow ? 'Kelola Pengguna' : 'Tambah Pengguna'; ?></h3><button class="btn bg-danger managed-close" type="button" aria-label="Tutup formulir">&times;</button></div>
      <div class="card-body"><form id="managedForm" method="post" autocomplete="off" data-editing="<?= $editRow ? 'true' : 'false'; ?>">
        <?= mikhmonCsrfField(); ?><input type="hidden" name="managed_action" value="save"><input type="hidden" name="partner_id" value="<?= htmlspecialchars($formPartnerId, ENT_QUOTES); ?>"><input type="hidden" name="user_id" value="<?= htmlspecialchars($formUserId, ENT_QUOTES); ?>">
        <div class="managed-form">
          <div><label for="managed-type">Jenis pengguna</label><select id="managed-type" class="form-control" name="type" required><?php $formIsStaff = in_array($formType, array('admin','finance','operator'), true); foreach ($typeLabels as $typeValue => $typeLabel): $optionIsStaff = in_array($typeValue, array('admin','finance','operator'), true); ?><option value="<?= $typeValue; ?>"<?= $formType === $typeValue ? ' selected' : ''; ?><?= $editRow && $formIsStaff !== $optionIsStaff ? ' disabled' : ''; ?>><?= $typeLabel; ?></option><?php endforeach; ?></select></div>
          <div><label for="managed-name">Nama</label><input id="managed-name" class="form-control" name="name" maxlength="100" required value="<?= htmlspecialchars($formName, ENT_QUOTES); ?>"></div>
          <div class="partner-field managed-router-field"><label for="managed-router">Router</label><select id="managed-router" class="form-control" name="router_session"><option value="">Pilih router</option><?php $managedSelectedSession = !empty($formPartner['session']) ? $formPartner['session'] : ($formAccount['session'] ?? ''); foreach ($routerSessions as $routerName): ?><option value="<?= htmlspecialchars($routerName, ENT_QUOTES); ?>"<?= ($managedSelectedSession === $routerName) ? ' selected' : ''; ?>><?= htmlspecialchars($routerName, ENT_QUOTES); ?></option><?php endforeach; ?></select></div>
          <div class="partner-field"><label for="managed-phone">Telepon</label><input id="managed-phone" class="form-control" name="phone" maxlength="30" value="<?= htmlspecialchars($formPartner['phone'], ENT_QUOTES); ?>"></div>
          <div class="partner-field"><label for="managed-email">Email</label><input id="managed-email" class="form-control" type="email" name="email" maxlength="120" value="<?= htmlspecialchars($formPartner['email'], ENT_QUOTES); ?>"></div>
          <div class="partner-field"><label for="managed-commission">Komisi</label><input id="managed-commission" class="form-control" type="number" min="0" step="1" name="commission" value="<?= (float) $formPartner['commission']; ?>"></div>
          <div class="wide partner-field"><label for="managed-address">Alamat</label><textarea id="managed-address" class="form-control" name="address" maxlength="255"><?= htmlspecialchars($formPartner['address'], ENT_QUOTES); ?></textarea></div>
          <div class="managed-section"><h4>Akses Login</h4><p id="managedAccessHelp">Aktifkan jika pengguna perlu masuk ke aplikasi.</p></div>
          <div class="wide"><label><input id="managed-login-enabled" type="checkbox" name="login_enabled" value="1"<?= $formLoginEnabled ? ' checked' : ''; ?>> Izinkan login ke aplikasi</label></div>
          <div class="login-field"><label for="managed-username">Username</label><input id="managed-username" class="form-control" name="username" maxlength="60" value="<?= htmlspecialchars($formAccount['username'] ?? '', ENT_QUOTES); ?>"></div>
          <div class="login-field"><label for="managed-password"><?= $formUserId !== '' ? 'Password baru' : 'Password'; ?></label><input id="managed-password" class="form-control" type="password" name="password"<?= $formUserId !== '' ? ' placeholder="Kosongkan jika tidak diubah"' : ''; ?>></div>
          <div class="managed-section"><h4>Status</h4><p>Menonaktifkan pengguna akan mempertahankan pelanggan dan riwayat transaksi.</p></div>
          <div class="wide"><label><input type="checkbox" name="active" value="1"<?= $formActive ? ' checked' : ''; ?>> Pengguna aktif</label></div>
        </div>
        <div class="managed-form-actions"><button class="btn bg-primary" type="submit"><i class="fa fa-save"></i> Simpan Pengguna</button><button class="btn bg-warning managed-cancel" type="button"><i class="fa fa-close"></i> Batal</button></div>
      </form></div>
    </div></div>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById('managedModal'), form = document.getElementById('managedForm'), openButton = document.getElementById('openManagedForm'), type = document.getElementById('managed-type'), loginEnabled = document.getElementById('managed-login-enabled'), lastFocus = null;
  function updateManagedFields() {
    var isGlobalStaff = type.value === 'admin' || type.value === 'finance', isStaff = isGlobalStaff || type.value === 'operator';
    Array.prototype.forEach.call(form.querySelectorAll('.partner-field'), function (field) { field.style.display = isStaff ? 'none' : ''; });
    document.querySelector('.managed-router-field').style.display = isGlobalStaff ? 'none' : '';
    document.getElementById('managed-router').required = !isGlobalStaff;
    loginEnabled.checked = isStaff || loginEnabled.checked;
    loginEnabled.disabled = isStaff;
    document.getElementById('managedAccessHelp').textContent = isStaff ? 'Peran staf selalu memiliki akses login sesuai lingkup tugasnya.' : 'Aktifkan jika pengguna perlu masuk ke aplikasi.';
    var showLogin = isStaff || loginEnabled.checked;
    Array.prototype.forEach.call(form.querySelectorAll('.login-field'), function (field) { field.style.display = showLogin ? '' : 'none'; });
    document.getElementById('managed-username').required = showLogin;
    document.getElementById('managed-password').required = showLogin && !form.elements.user_id.value;
  }
  function openModal() { lastFocus = document.activeElement; modal.classList.add('is-open'); modal.setAttribute('aria-hidden', 'false'); document.body.classList.add('managed-modal-open'); window.setTimeout(function () { document.getElementById('managed-name').focus(); }, 0); }
  function closeModal() { if (form.getAttribute('data-editing') === 'true') { window.location.assign(<?= json_encode($managedBaseUrl); ?>); return; } modal.classList.remove('is-open'); modal.setAttribute('aria-hidden', 'true'); document.body.classList.remove('managed-modal-open'); (lastFocus || openButton).focus(); }
  function prepareAdd() { form.reset(); form.elements.partner_id.value = ''; form.elements.user_id.value = ''; form.setAttribute('data-editing', 'false'); Array.prototype.forEach.call(type.options, function (option) { option.disabled = false; }); type.value = 'reseller'; form.elements.active.checked = true; document.getElementById('managedFormTitle').innerHTML = '<i class="fa fa-user-plus"></i> Tambah Pengguna'; updateManagedFields(); }
  function filterRows() { var query = document.getElementById('managedSearch').value.toLowerCase(), selectedType = document.getElementById('managedTypeFilter').value, selectedRouter = document.getElementById('managedRouterFilter').value, visible = 0; Array.prototype.forEach.call(document.querySelectorAll('.managed-row'), function (row) { var matches = row.textContent.toLowerCase().indexOf(query) !== -1 && (selectedType === 'all' || row.getAttribute('data-type') === selectedType) && (selectedRouter === 'all' || row.getAttribute('data-router') === selectedRouter); row.style.display = matches ? '' : 'none'; if (matches) visible++; }); document.getElementById('managedNoResults').style.display = visible === 0 && document.querySelectorAll('.managed-row').length ? '' : 'none'; }
  openButton.addEventListener('click', function () { prepareAdd(); openModal(); }); type.addEventListener('change', updateManagedFields); loginEnabled.addEventListener('change', updateManagedFields); modal.querySelector('.managed-close').addEventListener('click', closeModal); modal.querySelector('.managed-cancel').addEventListener('click', closeModal); modal.addEventListener('click', function (event) { if (event.target === modal) closeModal(); }); document.getElementById('managedSearch').addEventListener('input', filterRows); document.getElementById('managedTypeFilter').addEventListener('change', filterRows); document.getElementById('managedRouterFilter').addEventListener('change', filterRows);
  document.addEventListener('keydown', function (event) { if (!modal.classList.contains('is-open')) return; if (event.key === 'Escape') { event.preventDefault(); closeModal(); return; } if (event.key !== 'Tab') return; var items = Array.prototype.filter.call(modal.querySelectorAll('button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),a[href]'), function (item) { return item.offsetParent !== null; }); if (!items.length) return; var first = items[0], last = items[items.length - 1]; if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } });
  updateManagedFields(); filterRows(); if (modal.classList.contains('is-open')) { document.body.classList.add('managed-modal-open'); window.setTimeout(function () { document.getElementById('managed-name').focus(); }, 0); }
})();
</script>
