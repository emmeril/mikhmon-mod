<?php

error_reporting(0);
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
    if (!isset($data[$routerSession]) || $routerSession === 'mikhmon') {
      $userError = 'Router yang dipilih tidak valid.';
    } elseif (strtolower($username) === strtolower($useradm)) {
      $userError = 'Username sudah digunakan oleh administrator.';
    } elseif ($existingUser && $assigned > 0 && ($role !== 'mitra' || $routerSession !== $existingUser['session'])) {
      $userError = 'Akun ini masih memiliki ' . $assigned . ' pelanggan. Pindahkan assignment sebelum mengganti role atau router.';
    } elseif (mikhmonSaveUser($userId, $name, $username, $role, $routerSession, $password, $active) === false) {
      $userError = 'Pengguna gagal disimpan. Pastikan semua data lengkap, username unik, dan password diisi untuk akun baru.';
    } else {
      $userMessage = $userId === '' ? 'Akun pengguna berhasil dibuat.' : 'Akun pengguna berhasil diperbarui.';
    }
  } elseif ($action === 'delete') {
    $userId = isset($_POST['user_id']) ? (string) $_POST['user_id'] : '';
    $assigned = mikhmonAssignedCustomerCount($userId);
    if ($assigned > 0) {
      $userError = 'Akun mitra masih memiliki ' . $assigned . ' pelanggan. Pindahkan assignment pelanggan sebelum menghapus akun.';
    } elseif (mikhmonDeleteUser($userId)) {
      $userMessage = 'Akun pengguna berhasil dihapus.';
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
foreach ($users as $staff) {
  if ($staff['role'] === 'biller') {
    $stats = mikhmonBillerCommissionStats($staff['session'], $staff['id']);
    $monthlySummaries[$staff['id']] = array('label' => 'Komisi', 'count' => (int) $stats['month_count'], 'amount' => (float) $stats['month_amount']);
    continue;
  }
  $customerIds = array();
  foreach (mikhmonGetCustomers($staff['session']) as $customer) {
    if (isset($customer['mitra_id']) && (string) $customer['mitra_id'] === (string) $staff['id'] && isset($customer['id'])) {
      $customerIds[(string) $customer['id']] = true;
    }
  }
  $count = 0;
  $amount = 0;
  foreach (mikhmonGetInvoices($staff['session']) as $invoice) {
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
            <tr><td>Role</td><td><select class="form-control" name="role" required><option value="mitra"<?= $editUser && $editUser['role'] === 'mitra' ? ' selected' : ''; ?>>Mitra</option><option value="biller"<?= $editUser && $editUser['role'] === 'biller' ? ' selected' : ''; ?>>Biller</option></select></td></tr>
            <tr><td>Router</td><td><select class="form-control" name="router_session" required><option value="">Pilih router</option><?php foreach ($routerSessions as $routerName): ?><option value="<?= htmlspecialchars($routerName, ENT_QUOTES); ?>"<?= $editUser && $editUser['session'] === $routerName ? ' selected' : ''; ?>><?= htmlspecialchars($routerName, ENT_QUOTES); ?></option><?php endforeach; ?></select></td></tr>
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
            <td><?= htmlspecialchars($staff['name'], ENT_QUOTES); ?></td><td><?= htmlspecialchars($staff['username'], ENT_QUOTES); ?></td><td><?= strtoupper(htmlspecialchars($staff['role'], ENT_QUOTES)); ?></td><td><?= htmlspecialchars($staff['session'], ENT_QUOTES); ?></td><td class="<?= !empty($staff['active']) ? 'text-success' : 'text-danger'; ?>"><?= !empty($staff['active']) ? 'Aktif' : 'Nonaktif'; ?></td><td><small><?= $staffSummary['label']; ?>:</small><br><?= (int) $staffSummary['count']; ?> trx / <?= htmlspecialchars($commissionCurrency . ' ' . number_format($staffSummary['amount'], 0, ',', '.'), ENT_QUOTES); ?></td>
            <td><a class="btn bg-primary" href="<?= htmlspecialchars($userManagementBaseUrl, ENT_QUOTES); ?>&amp;user-id=<?= rawurlencode($staff['id']); ?>"><i class="fa fa-edit"></i> Edit</a> <form method="post" style="display:inline"><input type="hidden" name="user_action" value="delete"><input type="hidden" name="user_id" value="<?= htmlspecialchars($staff['id'], ENT_QUOTES); ?>"><button class="btn bg-danger" type="submit" onclick="return confirm('Hapus akun ini?');"><i class="fa fa-trash"></i> Hapus</button></form></td>
          </tr><?php endforeach; ?><?php if (!$users): ?><tr><td colspan="7" class="text-center">Belum ada akun mitra atau biller.</td></tr><?php endif; ?></tbody>
        </table></div>
      </div>
    </div>
  </div>
</div>
