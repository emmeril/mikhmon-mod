<?php

error_reporting(0);
include_once(__DIR__ . '/../include/systemlog.php');
if (!isset($_SESSION['mikhmon']) || !mikhmonIsAdmin()) {
  header('Location:../admin.php?id=login');
  exit;
}

$userMessage = '';
$userError = '';
$editUser = false;
$userManagementBaseUrl = isset($admin) && $admin === 'users' && !empty($session)
  ? './?admin=users&session=' . rawurlencode($session)
  : './admin.php?id=users';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = isset($_POST['user_action']) ? $_POST['user_action'] : '';
  if ($action === 'save') {
    $userId = isset($_POST['user_id']) ? trim((string) $_POST['user_id']) : '';
    $name = isset($_POST['name']) ? trim((string) $_POST['name']) : '';
    $username = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
    $role = isset($_POST['role']) ? (string) $_POST['role'] : '';
    $routerSession = isset($_POST['router_session']) ? trim((string) $_POST['router_session']) : '';
    $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
    $active = isset($_POST['active']);
    $existingUser = $userId !== '' ? mikhmonFindUser($userId) : false;
    $assigned = $userId !== '' ? mikhmonAssignedCustomerCount($userId) : 0;
    $validRouter = $role === 'admin'
      ? $routerSession === 'mikhmon'
      : isset($data[$routerSession]) && $routerSession !== 'mikhmon';
    if (!$validRouter) {
      $userError = 'Router yang dipilih tidak valid.';
    } elseif (strtolower($username) === strtolower($useradm)) {
      $userError = 'Username sudah digunakan oleh administrator.';
    } elseif ($existingUser && $assigned > 0 && ($role !== 'mitra' || $routerSession !== $existingUser['session'])) {
      $userError = 'Akun ini masih memiliki ' . $assigned . ' pelanggan. Pindahkan assignment sebelum mengganti role atau router.';
    } elseif (mikhmonSaveUser($userId, $name, $username, $role, $routerSession, $password, $active) === false) {
      $userError = 'Pengguna gagal disimpan. Pastikan semua data lengkap, username unik, dan password diisi untuk akun baru.';
    } else {
      $userMessage = $userId === '' ? 'Akun pengguna berhasil dibuat.' : 'Akun pengguna berhasil diperbarui.';
      mikhmonSystemLog('success', 'Manajemen User', ($userId === '' ? 'Membuat' : 'Memperbarui') . ' akun ' . $username . ' dengan role ' . strtoupper($role) . '.', mikhmonSystemLogCurrentUser(array('session' => $role === 'admin' ? '' : $routerSession)));
    }
  } elseif ($action === 'delete') {
    $userId = isset($_POST['user_id']) ? (string) $_POST['user_id'] : '';
    $deletedUser = mikhmonFindUser($userId);
    $assigned = mikhmonAssignedCustomerCount($userId);
    if ($assigned > 0) {
      $userError = 'Akun mitra masih memiliki ' . $assigned . ' pelanggan. Pindahkan assignment pelanggan sebelum menghapus akun.';
    } elseif (mikhmonDeleteUser($userId)) {
      $userMessage = 'Akun pengguna berhasil dihapus.';
      mikhmonSystemLog('warning', 'Manajemen User', 'Menghapus akun ' . ($deletedUser['username'] ?? $userId) . ' dengan role ' . strtoupper((string) ($deletedUser['role'] ?? '')) . '.', mikhmonSystemLogCurrentUser(array('session' => $deletedUser['session'] ?? '')));
    } else {
      $userError = 'Akun pengguna tidak ditemukan.';
    }
  }
}

if (!empty($_GET['user-id'])) $editUser = mikhmonFindUser($_GET['user-id']);
$users = mikhmonGetUsers();
$commissionCurrency = isset($currency) && trim((string) $currency) !== '' ? (string) $currency : 'Rp';
$monthlySummaries = array();
$currentMonth = date('Ym');
$databaseForSummaries = mikhmonReadDatabase();
$customersBySession = isset($databaseForSummaries['customers']) ? $databaseForSummaries['customers'] : array();
$invoicesBySession = isset($databaseForSummaries['invoices']) ? $databaseForSummaries['invoices'] : array();
foreach ($users as $staff) {
  if ($staff['role'] === 'admin') {
    $monthlySummaries[$staff['id']] = array('label' => 'Akses penuh', 'count' => 0, 'amount' => 0);
    continue;
  }
  if ($staff['role'] === 'biller') {
    $count = 0;
    foreach ((array) ($invoicesBySession[$staff['session']] ?? array()) as $invoice) {
      if (($invoice['status'] ?? '') === 'paid' && (string) ($invoice['paid_by_user_id'] ?? '') === (string) $staff['id'] && !empty($invoice['paid_at']) && date('Ym', (int) $invoice['paid_at']) === $currentMonth) $count++;
    }
    $monthlySummaries[$staff['id']] = array('label' => 'Komisi', 'count' => $count, 'amount' => $count * mikhmonBillerCommissionAmount());
    continue;
  }
  $customerIds = array();
  foreach ((array) ($customersBySession[$staff['session']] ?? array()) as $customer) {
    if (isset($customer['mitra_id']) && (string) $customer['mitra_id'] === (string) $staff['id'] && isset($customer['id'])) {
      $customerIds[(string) $customer['id']] = true;
    }
  }
  $count = 0;
  $amount = 0;
  foreach ((array) ($invoicesBySession[$staff['session']] ?? array()) as $invoice) {
    if (!isset($invoice['customer_id'], $customerIds[(string) $invoice['customer_id']]) || ($invoice['status'] ?? '') !== 'paid') continue;
    if (empty($invoice['paid_at']) || date('Ym', (int) $invoice['paid_at']) !== $currentMonth) continue;
    $count++;
    $amount += isset($invoice['amount']) ? (float) $invoice['amount'] : 0;
  }
  $monthlySummaries[$staff['id']] = array('label' => 'Penjualan', 'count' => $count, 'amount' => $amount);
}
$routerSessions = array();
foreach ((array) $data as $routerName => $routerConfig) {
  if ($routerName !== 'mikhmon') $routerSessions[] = $routerName;
}
?>
<div class="row">
  <div class="col-5">
    <div class="card">
      <div class="card-header"><h3><i class="fa fa-user-plus"></i> <?= $editUser ? 'Edit Pengguna' : 'Tambah Pengguna'; ?></h3></div>
      <div class="card-body">
        <?php if ($userMessage !== ''): ?><div class="box bg-success"><?= htmlspecialchars($userMessage, ENT_QUOTES); ?></div><?php endif; ?>
        <?php if ($userError !== ''): ?><div class="box bg-danger"><?= htmlspecialchars($userError, ENT_QUOTES); ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="user_action" value="save">
          <input type="hidden" name="user_id" value="<?= htmlspecialchars($editUser ? $editUser['id'] : '', ENT_QUOTES); ?>">
          <table class="table">
            <tr><td>Nama</td><td><input class="form-control" name="name" maxlength="100" required value="<?= htmlspecialchars($editUser ? $editUser['name'] : '', ENT_QUOTES); ?>"></td></tr>
            <tr><td>Username</td><td><input class="form-control" name="username" maxlength="60" required value="<?= htmlspecialchars($editUser ? $editUser['username'] : '', ENT_QUOTES); ?>"></td></tr>
            <tr><td>Role</td><td><select class="form-control" id="user-role" name="role" required><option value="mitra"<?= (!$editUser || $editUser['role'] === 'mitra') ? ' selected' : ''; ?>>Mitra</option><option value="biller"<?= $editUser && $editUser['role'] === 'biller' ? ' selected' : ''; ?>>Biller</option><option value="admin"<?= $editUser && $editUser['role'] === 'admin' ? ' selected' : ''; ?>>Admin</option></select></td></tr>
            <tr id="user-router-row"><td>Router</td><td><select class="form-control" id="user-router" name="router_session" required><option value=""<?= !$editUser ? ' selected' : ''; ?>>Pilih router</option><?php foreach ($routerSessions as $routerName): ?><option value="<?= htmlspecialchars($routerName, ENT_QUOTES); ?>"<?= $editUser && $editUser['session'] === $routerName ? ' selected' : ''; ?>><?= htmlspecialchars($routerName, ENT_QUOTES); ?></option><?php endforeach; ?><option id="user-router-all" value="mikhmon"<?= $editUser && $editUser['role'] === 'admin' ? ' selected' : ''; ?>>Semua Router</option></select></td></tr>
            <tr><td><?= $editUser ? 'Password Baru' : 'Password'; ?></td><td><input class="form-control" type="password" name="password"<?= $editUser ? ' placeholder="Kosongkan jika tidak diubah"' : ' required'; ?>></td></tr>
            <tr><td>Status</td><td><label><input type="checkbox" name="active" value="1"<?= !$editUser || !empty($editUser['active']) ? ' checked' : ''; ?>> Aktif</label></td></tr>
          </table>
          <?php if ($editUser): ?><a class="btn bg-warning" href="<?= htmlspecialchars($userManagementBaseUrl, ENT_QUOTES); ?>">Batal</a><?php endif; ?>
          <button class="btn bg-primary" type="submit"><i class="fa fa-save"></i> Simpan</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-7">
    <div class="card">
      <div class="card-header"><h3><i class="fa fa-users"></i> Manajemen User</h3></div>
      <div class="card-body">
        <p><small>Mitra hanya melihat pelanggan yang ditetapkan admin. Biller hanya mengelola Billing pada router yang dipilih.</small></p>
        <div class="overflow box-bordered"><table class="table table-bordered table-hover text-nowrap">
          <thead><tr><th>Nama</th><th>Username</th><th>Role</th><th>Router</th><th>Status</th><th>Ringkasan Bulan Ini</th><th>Aksi</th></tr></thead>
          <tbody><?php foreach ($users as $staff): ?><tr>
            <?php $staffSummary = isset($monthlySummaries[$staff['id']]) ? $monthlySummaries[$staff['id']] : array('label' => 'Aktivitas', 'count' => 0, 'amount' => 0); ?>
            <td><?= htmlspecialchars($staff['name'], ENT_QUOTES); ?></td><td><?= htmlspecialchars($staff['username'], ENT_QUOTES); ?></td><td><?= strtoupper(htmlspecialchars($staff['role'], ENT_QUOTES)); ?></td><td><?= $staff['role'] === 'admin' ? 'Semua Router' : htmlspecialchars($staff['session'], ENT_QUOTES); ?></td><td class="<?= !empty($staff['active']) ? 'text-success' : 'text-danger'; ?>"><?= !empty($staff['active']) ? 'Aktif' : 'Nonaktif'; ?></td><td><?php if ($staff['role'] === 'admin'): ?><small>Akses penuh ke pengaturan dan semua router.</small><?php else: ?><small><?= $staffSummary['label']; ?>:</small><br><?= (int) $staffSummary['count']; ?> trx / <?= htmlspecialchars($commissionCurrency . ' ' . number_format($staffSummary['amount'], 0, ',', '.'), ENT_QUOTES); ?><?php endif; ?></td>
            <td><a class="btn bg-primary" href="<?= htmlspecialchars($userManagementBaseUrl, ENT_QUOTES); ?>&amp;user-id=<?= rawurlencode($staff['id']); ?>"><i class="fa fa-edit"></i> Edit</a> <form method="post" style="display:inline"><input type="hidden" name="user_action" value="delete"><input type="hidden" name="user_id" value="<?= htmlspecialchars($staff['id'], ENT_QUOTES); ?>"><button class="btn bg-danger" type="submit" onclick="return confirm('Hapus akun ini?');"><i class="fa fa-trash"></i> Hapus</button></form></td>
          </tr><?php endforeach; ?><?php if (!$users): ?><tr><td colspan="7" class="text-center">Belum ada akun admin, mitra, atau biller.</td></tr><?php endif; ?></tbody>
        </table></div>
      </div>
    </div>
  </div>
</div>
<script>
function updateUserRouterField() {
  var role = document.getElementById('user-role');
  var router = document.getElementById('user-router');
  var allRouters = document.getElementById('user-router-all');
  var row = document.getElementById('user-router-row');
  if (!role || !router || !allRouters || !row) return;
  var isAdmin = role.value === 'admin';
  row.style.display = isAdmin ? 'none' : '';
  router.required = !isAdmin;
  allRouters.disabled = !isAdmin;
  if (isAdmin) router.value = 'mikhmon';
  else if (router.value === 'mikhmon') router.value = '';
}
document.addEventListener('DOMContentLoaded', function () {
  var role = document.getElementById('user-role');
  if (role) role.addEventListener('change', updateUserRouterField);
  updateUserRouterField();
});
</script>
